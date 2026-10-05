<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Enums\FinancialAccountType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreFinancialAccountRequest;
use App\Http\Requests\Finance\UpdateFinancialAccountRequest;
use App\Models\FinancialAccount;
use App\Services\AuditLogger;
use App\Services\BusinessContext;
use App\Services\FinancialLedgerService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancialAccountController extends Controller
{
    public function __construct(
        private readonly BusinessContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): View
    {
        // withBalance() aggregates every account's credits and debits in the
        // list query, so a page of accounts is three queries rather than one
        // per row.
        $accounts = FinancialAccount::query()
            ->where('business_id', $this->context->business()->id)
            ->withBalance()
            ->orderBy('name')
            ->paginate(20);

        return view('finance.accounts.index', ['accounts' => $accounts]);
    }

    public function create(): View
    {
        return view('finance.accounts.create', ['types' => FinancialAccountType::cases()]);
    }

    public function store(StoreFinancialAccountRequest $request): RedirectResponse
    {
        $account = DB::transaction(function () use ($request): FinancialAccount {
            $account = FinancialAccount::create(
                $request->validated() + ['business_id' => $this->context->business()->id]
            );

            $this->audit->created($account, [
                'name' => $account->name,
                'type' => $account->type->value,
                'opening_balance' => (string) $account->opening_balance,
            ]);

            return $account;
        });

        return redirect()->route('finance.accounts.index')
            ->with('status', __('finance.accounts.created', ['name' => $account->name]));
    }

    public function show(FinancialAccount $account, FinancialLedgerService $ledger): View
    {
        $this->assertBelongsToBusiness($account);

        return view('finance.accounts.show', [
            'account' => $account,
            'balance' => $ledger->balanceFor($account),
            'recentEntries' => $account->ledgerEntries()
                ->with('creator:id,name')
                ->latest('entry_date')
                ->latest('id')
                ->limit(20)
                ->get(),
        ]);
    }

    public function edit(FinancialAccount $account): View
    {
        $this->assertBelongsToBusiness($account);

        return view('finance.accounts.edit', [
            'account' => $account,
            'types' => FinancialAccountType::cases(),
            // Changing the opening balance of an account that already has
            // postings would silently restate every balance since. Locked.
            'openingBalanceLocked' => $account->hasFinancialHistory(),
        ]);
    }

    public function update(UpdateFinancialAccountRequest $request, FinancialAccount $account): RedirectResponse
    {
        $this->assertBelongsToBusiness($account);

        DB::transaction(function () use ($request, $account): void {
            $before = $account->only(['name', 'type', 'opening_balance', 'notes']);

            $validated = $request->validated();

            if ($account->hasFinancialHistory()) {
                unset($validated['opening_balance']);
            }

            $account->fill($validated)->save();

            $this->audit->updated($account, $before, $account->only(['name', 'type', 'opening_balance', 'notes']));
        });

        return redirect()->route('finance.accounts.index')
            ->with('status', __('finance.accounts.updated', ['name' => $account->name]));
    }

    public function updateStatus(Request $request, FinancialAccount $account): RedirectResponse
    {
        $this->assertBelongsToBusiness($account);

        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        $active = (bool) $validated['is_active'];

        DB::transaction(function () use ($account, $active): void {
            $account->forceFill(['is_active' => $active])->save();
            $this->audit->statusChanged($account, $active);
        });

        return back()->with('status', __(
            $active ? 'finance.accounts.activated' : 'finance.accounts.deactivated',
            ['name' => $account->name]
        ));
    }

    /**
     * Possessing an id grants nothing: an account from another business must be
     * refused even though the route model binding resolved it.
     */
    private function assertBelongsToBusiness(FinancialAccount $account): void
    {
        if ((int) $account->business_id !== (int) $this->context->business()->id) {
            throw ValidationException::withMessages([
                'account' => __('finance.accounts.errors.wrong_business'),
            ]);
        }
    }
}
