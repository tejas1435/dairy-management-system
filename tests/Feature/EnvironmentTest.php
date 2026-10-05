<?php

use Illuminate\Support\Facades\DB;

/*
 * Guards architecture decision D2: the application and its test suite run on
 * MySQL only. SQLite silently diverges on foreign keys, composite unique
 * constraints, DECIMAL arithmetic and strict mode, so a green SQLite suite
 * would not prove the schema works. These tests fail loudly if the test
 * environment is ever pointed somewhere else.
 */

test('the test suite runs against mysql', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');
});

test('the test suite runs against the dedicated test schema', function () {
    expect(DB::connection()->getDatabaseName())->toBe('dairy_management_test');
});

test('the test schema stores utf8mb4 so gujarati and hindi survive a round trip', function () {
    $charset = DB::selectOne('SELECT @@character_set_database AS charset')->charset;

    expect($charset)->toBe('utf8mb4');
});

test('the application timezone is asia kolkata', function () {
    expect(config('app.timezone'))->toBe('Asia/Kolkata');
});

test('money and milk quantities keep full precision through the database', function () {
    // DECIMAL(14,2) money and DECIMAL(10,3) milk are core to the spec; this
    // proves the driver returns them without float drift.
    $row = DB::selectOne('SELECT CAST(? AS DECIMAL(14,2)) AS money, CAST(? AS DECIMAL(10,3)) AS litres', [
        '172000.55',
        '89.125',
    ]);

    expect($row->money)->toBe('172000.55')
        ->and($row->litres)->toBe('89.125');
});
