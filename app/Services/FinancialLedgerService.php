<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LedgerDirection;
use App\Models\FinancialAccount;
use App\Models\FinancialLedgerEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only way financial ledger entries are created.
 *
 * Controllers never build a FinancialLedgerEntry. They call a domain action,
 * the action calls this service, and this service decides what gets posted.
 * That keeps three guarantees in one place:
 *
 *  1. **Idempotency.** Every posting carries a deterministic key derived from
 *     the domain operation that caused it, backed by a unique index. A retried
 *     request, a double-clicked Save or a re-run job cannot double a balance;
 *     the repeat is recognised and the existing entry returned.
 *
 *  2. **Reversal, not deletion.** A posting that must be undone gets an
 *     opposite entry pointing back at the original. Both remain, so the ledger
 *     still explains what was believed at the time. A unique index on
 *     reverses_entry_id means an entry can be reversed at most once.
 *
 *  3. **Decimal arithmetic.** Amounts are handled as strings through bcmath.
 *     Money never becomes a float.
 */
class FinancialLedgerService
{
    /**
     * Posts a credit, increasing the account balance.
     *
     * @param  string  $idempotencyKey  deterministic for the causing operation
     */
    public function credit(
        FinancialAccount $account,
        string $amount,
        string $date,
        string $description,
        string $idempotencyKey,
        ?Model $reference = null,
    ): FinancialLedgerEntry {
        return $this->post(LedgerDirection::Credit, $account, $amount, $date, $description, $idempotencyKey, $reference);
    }

    /** Posts a debit, decreasing the account balance. */
    public function debit(
        FinancialAccount $account,
        string $amount,
        string $date,
        string $description,
        string $idempotencyKey,
        ?Model $reference = null,
    ): FinancialLedgerEntry {
        return $this->post(LedgerDirection::Debit, $account, $amount, $date, $description, $idempotencyKey, $reference);
    }

    /**
     * Reverses an entry with an opposite one.
     *
     * Safe to call twice: the second call finds the existing reversal and
     * returns it rather than posting another. Both the deterministic key and
     * the unique index on reverses_entry_id enforce that.
     */
    public function reverse(
        FinancialLedgerEntry $original,
        string $description,
        ?string $date = null,
    ): FinancialLedgerEntry {
        if ($original->isReversal()) {
            throw new RuntimeException('A reversal entry cannot itself be reversed.');
        }

        $existing = FinancialLedgerEntry::query()
            ->where('reverses_entry_id', $original->getKey())
            ->first();

        if ($existing) {
            return $existing;
        }

        return $this->write([
            'financial_account_id' => $original->financial_account_id,
            'entry_date' => $date ?? now()->toDateString(),
            'direction' => $original->direction->opposite()->value,
            'amount' => (string) $original->amount,
            'reference_type' => $original->reference_type,
            'reference_id' => $original->reference_id,
            'description' => $description,
            'idempotency_key' => self::reversalKey($original->idempotency_key),
            'reverses_entry_id' => $original->getKey(),
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * Reverses every entry posted for a domain record that has not already been
     * reversed. Used when an expense or contribution is cancelled.
     *
     * @return array<int, FinancialLedgerEntry>
     */
    public function reverseAllFor(Model $reference, string $description, ?string $date = null): array
    {
        $originals = FinancialLedgerEntry::query()
            ->where('reference_type', $reference->getMorphClass())
            ->where('reference_id', $reference->getKey())
            ->whereNull('reverses_entry_id')
            ->lockForUpdate()
            ->get();

        $reversals = [];

        foreach ($originals as $original) {
            $reversals[] = $this->reverse($original, $description, $date);
        }

        return $reversals;
    }

    /** The derived balance of an account. */
    public function balanceFor(FinancialAccount $account, ?string $upTo = null): string
    {
        return $account->balance($upTo);
    }

    /**
     * Deterministic idempotency key for a domain operation.
     *
     * Shape: "purpose:identifier", e.g. "expense_funding:41" for the ledger
     * effect of funding allocation 41. Any caller deriving the same key for the
     * same operation is recognised as a repeat.
     */
    public static function key(string $purpose, int|string $identifier): string
    {
        return $purpose.':'.$identifier;
    }

    public static function reversalKey(string $originalKey): string
    {
        return 'reversal:'.$originalKey;
    }

    private function post(
        LedgerDirection $direction,
        FinancialAccount $account,
        string $amount,
        string $date,
        string $description,
        string $idempotencyKey,
        ?Model $reference,
    ): FinancialLedgerEntry {
        if (bccomp($amount, '0', 2) <= 0) {
            throw new RuntimeException('A ledger entry amount must be greater than zero.');
        }

        return $this->write([
            'financial_account_id' => $account->getKey(),
            'entry_date' => $date,
            'direction' => $direction->value,
            'amount' => $amount,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'description' => $description,
            'idempotency_key' => $idempotencyKey,
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * Inserts the entry, treating a duplicate key as "already posted".
     *
     * The race this closes: two concurrent requests both check for an existing
     * entry, both find none, and both insert. The unique index rejects the
     * loser, and rather than surfacing a constraint violation to the user we
     * return the entry the winner wrote, which is the outcome they wanted.
     */
    private function write(array $attributes): FinancialLedgerEntry
    {
        $existing = FinancialLedgerEntry::query()
            ->where('idempotency_key', $attributes['idempotency_key'])
            ->first();

        if ($existing) {
            return $existing;
        }

        try {
            return FinancialLedgerEntry::create($attributes);
        } catch (QueryException $exception) {
            if (! $this->isDuplicateKey($exception)) {
                throw $exception;
            }

            $entry = FinancialLedgerEntry::query()
                ->where('idempotency_key', $attributes['idempotency_key'])
                ->first();

            if (! $entry) {
                throw $exception;
            }

            return $entry;
        }
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        return ($exception->errorInfo[1] ?? null) === 1062;
    }

    /**
     * Running balance rows for the cashbook.
     *
     * The running total is accumulated in SQL-ordered sequence rather than
     * recomputed per row, so a long ledger stays one query plus one pass.
     *
     * @return array{opening: string, rows: Collection<int, object>, closing: string}
     */
    public function cashbook(
        FinancialAccount $account,
        ?string $from = null,
        ?string $to = null,
        int $perPage = 50,
    ): array {
        // Everything before the window contributes to the period's opening.
        $opening = bcadd((string) $account->opening_balance, '0', 2);

        if ($from !== null) {
            $opening = bcadd($opening, $this->netMovementBefore($account, $from), 2);
        }

        $base = fn () => FinancialLedgerEntry::query()
            ->forAccount($account->getKey())
            ->when($from, fn ($q) => $q->whereDate('entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('entry_date', '<=', $to));

        $paginator = $base()
            ->with(['creator:id,name'])
            ->orderBy('entry_date')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();

        /*
         * Page 2 cannot start from the period's opening balance: it must start
         * from the opening plus everything the earlier pages contain, or every
         * page after the first would show wrong balances.
         */
        $offset = ($paginator->currentPage() - 1) * $paginator->perPage();
        $broughtForward = bcadd($opening, $this->netMovementOverFirst($base(), $offset), 2);

        $running = $broughtForward;

        $rows = collect($paginator->items())->map(function (FinancialLedgerEntry $entry) use (&$running): object {
            $running = bcadd($running, $entry->signedAmount(), 2);

            return (object) [
                'entry' => $entry,
                'running_balance' => $running,
            ];
        });

        return [
            'opening' => $opening,
            'brought_forward' => $broughtForward,
            'rows' => $rows,
            // The closing figure of this page, not of the whole period.
            'closing' => $running,
            'paginator' => $paginator,
        ];
    }

    /** Net credits minus debits for an account before a given date. */
    private function netMovementBefore(FinancialAccount $account, string $before): string
    {
        $credits = FinancialLedgerEntry::query()
            ->forAccount($account->getKey())
            ->where('direction', LedgerDirection::Credit->value)
            ->whereDate('entry_date', '<', $before)
            ->sum('amount');

        $debits = FinancialLedgerEntry::query()
            ->forAccount($account->getKey())
            ->where('direction', LedgerDirection::Debit->value)
            ->whereDate('entry_date', '<', $before)
            ->sum('amount');

        return bcsub((string) $credits, (string) $debits, 2);
    }

    /**
     * Net movement over the first N rows of an ordered query.
     *
     * One aggregate over a limited subquery rather than fetching those rows, so
     * paging deep into a long ledger costs the same as paging into its start.
     *
     * @param  Builder<FinancialLedgerEntry>  $query
     */
    private function netMovementOverFirst($query, int $count): string
    {
        if ($count <= 0) {
            return '0.00';
        }

        $inner = $query
            ->reorder()
            ->orderBy('entry_date')
            ->orderBy('id')
            ->limit($count)
            ->selectRaw(
                'case when direction = ? then amount else -amount end as signed_amount',
                [LedgerDirection::Credit->value]
            );

        $total = DB::query()->fromSub($inner, 'earlier_rows')->sum('signed_amount');

        return bcadd((string) ($total ?? '0'), '0', 2);
    }

    /** Convenience for actions that need the whole thing inside one transaction. */
    public function transaction(callable $callback): mixed
    {
        return DB::transaction($callback);
    }
}
