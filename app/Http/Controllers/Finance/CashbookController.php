<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\FinancialAccount;
use App\Services\BusinessContext;
use App\Services\FinancialLedgerService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The cashbook: one account's ledger with a running balance.
 *
 * The running balance is built from the ledger plus the account's opening
 * balance, never read from a stored field, so what the screen shows and what
 * the entries say cannot disagree.
 *
 * No export buttons. Excel, PDF and print belong to Phase 9, and a button that
 * does nothing is worse than no button.
 */
class CashbookController extends Controller
{
    public function __invoke(
        Request $request,
        BusinessContext $context,
        FinancialLedgerService $ledger,
    ): View {
        $businessId = $context->business()->id;

        $accounts = FinancialAccount::query()
            ->where('business_id', $businessId)
            ->orderBy('name')
            ->get();

        $filters = $request->validate([
            'account' => [
                'nullable', 'integer',
                Rule::exists('financial_accounts', 'id')->where('business_id', $businessId),
            ],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $account = isset($filters['account'])
            ? $accounts->firstWhere('id', (int) $filters['account'])
            : $accounts->first();

        $book = $account
            ? $ledger->cashbook($account, $filters['from'] ?? null, $filters['to'] ?? null)
            : [
                'opening' => '0.00',
                'brought_forward' => '0.00',
                'rows' => collect(),
                'closing' => '0.00',
                'paginator' => null,
            ];

        return view('finance.cashbook.index', [
            'accounts' => $accounts,
            'account' => $account,
            'filters' => $filters,
            'opening' => $book['opening'],
            'broughtForward' => $book['brought_forward'],
            'rows' => $book['rows'],
            'closing' => $book['closing'],
            'paginator' => $book['paginator'],
        ]);
    }
}
