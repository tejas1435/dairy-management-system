<?php

use App\Support\Quantity;

/*
 * Exact litre arithmetic.
 *
 * This is the one Phase 3 concern that genuinely needs no database, so it lives in
 * the Unit suite. Everything else about milk involves rows.
 *
 * The reason the helper exists at all is the reconciliation screen: given 10.000
 * litres allocated as 3.333 + 3.333 + 3.334, "remaining" must be exactly 0.000. In
 * binary floating point it is -0.00000000000000044409, and that residue is not
 * cosmetic — it makes a "remaining must not go negative" check reject a day that
 * balances perfectly.
 */

test('the canonical scale is three decimal places', function () {
    expect(Quantity::SCALE)->toBe(3)
        ->and(Quantity::ZERO)->toBe('0.000');
});

/*
|--------------------------------------------------------------------------
| Normalisation contract
|--------------------------------------------------------------------------
*/

test('numeric input is normalised to three decimal places', function (mixed $input, string $expected) {
    expect(Quantity::of($input))->toBe($expected);
})->with([
    'integer' => [10, '10.000'],
    'integer string' => ['10', '10.000'],
    'one decimal' => ['10.5', '10.500'],
    'three decimals' => ['10.500', '10.500'],
    'float' => [10.5, '10.500'],
    'zero' => ['0', '0.000'],
    'negative' => ['-1.5', '-1.500'],
    'leading dot' => ['.25', '0.250'],
    // Truncation, not rounding: bcadd at scale 3 drops the excess digits. The
    // Form Requests reject a fourth decimal before it ever gets here.
    'extra precision is truncated' => ['1.2349', '1.234'],
]);

test('absent means zero, because an unset column and an empty sum both mean no milk', function (mixed $input) {
    expect(Quantity::of($input))->toBe('0.000');
})->with([
    'null' => [null],
    'empty string' => [''],
]);

test('a non-numeric quantity throws instead of silently becoming zero', function (string $input) {
    // '12,500' is the case that matters: a comma decimal separator would have
    // recorded 0.000 litres instead of twelve and a half.
    expect(fn () => Quantity::of($input))->toThrow(InvalidArgumentException::class);
})->with([
    'comma separator' => ['12,500'],
    'letters' => ['abc'],
    'trailing unit' => ['10 L'],
    'whitespace only' => ['   '],
]);

/*
|--------------------------------------------------------------------------
| The exactness that the module depends on
|--------------------------------------------------------------------------
*/

test('ten litres allocated in uneven thirds leaves exactly zero', function () {
    $remaining = Quantity::sub(
        Quantity::sub(Quantity::sub('10.000', '3.333'), '3.333'),
        '3.334'
    );

    expect($remaining)->toBe('0.000')
        ->and(Quantity::isZero($remaining))->toBeTrue()
        ->and(Quantity::isNegative($remaining))->toBeFalse();
});

test('the same sum in binary floating point does not reach zero', function () {
    // Not testing our code — recording why the helper exists, so nobody
    // "simplifies" it back to floats.
    $floaty = 10.0 - 3.333 - 3.333 - 3.334;

    expect($floaty)->not->toBe(0.0)
        ->and(abs($floaty))->toBeGreaterThan(0.0);
});

test('exact subtraction at the millilitre', function (string $a, string $b, string $expected) {
    expect(Quantity::sub($a, $b))->toBe($expected);
})->with([
    ['1.000', '0.999', '0.001'],
    ['0.001', '0.001', '0.000'],
    ['10.000', '10.001', '-0.001'],
    ['10.000', '10.000', '0.000'],
    ['0.000', '0.001', '-0.001'],
]);

test('a one millilitre overrun stays negative and is never rounded away', function () {
    $remaining = Quantity::sub('10.000', '10.001');

    expect($remaining)->toBe('-0.001')
        ->and(Quantity::isNegative($remaining))->toBeTrue()
        ->and(Quantity::isZero($remaining))->toBeFalse();
});

test('addition is exact across many small quantities', function () {
    $total = Quantity::sum(['1.000', '0.500', '0.250', '0.125', '0.125']);

    expect($total)->toBe('2.000');
});

test('summing an empty list is zero', function () {
    expect(Quantity::sum([]))->toBe('0.000');
});

test('summing tenths that floats cannot represent is still exact', function () {
    // 0.1 + 0.2 !== 0.3 in binary floating point.
    expect(Quantity::sum(['0.100', '0.200']))->toBe('0.300')
        ->and(Quantity::compare(Quantity::sum(['0.100', '0.200']), '0.300'))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Comparison and predicates
|--------------------------------------------------------------------------
*/

test('comparison works at three decimal places', function () {
    expect(Quantity::compare('1.000', '1.000'))->toBe(0)
        ->and(Quantity::compare('1.001', '1.000'))->toBe(1)
        ->and(Quantity::compare('1.000', '1.001'))->toBe(-1)
        // Equal once normalised.
        ->and(Quantity::compare('1', '1.000'))->toBe(0);
});

test('the predicates agree with the comparison', function () {
    expect(Quantity::isZero('0'))->toBeTrue()
        ->and(Quantity::isZero('0.000'))->toBeTrue()
        ->and(Quantity::isZero('0.001'))->toBeFalse()
        ->and(Quantity::isPositive('0.001'))->toBeTrue()
        ->and(Quantity::isPositive('0.000'))->toBeFalse()
        ->and(Quantity::isNegative('-0.001'))->toBeTrue()
        ->and(Quantity::isNegative('0.000'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Signing, used to apply an adjustment direction
|--------------------------------------------------------------------------
*/

test('negate flips the sign exactly', function () {
    expect(Quantity::negate('2.500'))->toBe('-2.500')
        ->and(Quantity::negate('-2.500'))->toBe('2.500')
        // Zero has no negative.
        ->and(Quantity::isZero(Quantity::negate('0.000')))->toBeTrue();
});

test('signed applies a direction without the column ever holding a sign', function () {
    expect(Quantity::signed('1.250', 1))->toBe('1.250')
        ->and(Quantity::signed('1.250', -1))->toBe('-1.250');
});

test('a net adjustment of an increase and a decrease is exact', function () {
    // increase 1.250, decrease 0.500  ->  net +0.750
    $net = Quantity::add(Quantity::signed('1.250', 1), Quantity::signed('0.500', -1));

    expect($net)->toBe('0.750');
});
