<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Actions\Expenses\CancelExpense;
use App\Actions\Expenses\CreateExpense;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\CancelTransactionRequest;
use App\Http\Requests\Finance\StoreExpenseRequest;
use App\Http\Requests\Finance\UpdateExpenseRequest;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\Partner;
use App\Models\PaymentMethod;
use App\Services\AuditLogger;
use App\Services\BusinessContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExpenseController extends Controller
{
    public function __construct(
        private readonly BusinessContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'integer', Rule::exists('expense_categories', 'id')],
            'status' => ['nullable', Rule::in(TransactionStatus::values())],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $query = Expense::query()
            ->with(['category:id,name', 'fundingAllocations'])
            ->where('business_id', $this->context->business()->id)
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('description', 'like', "%{$s}%")
                ->orWhere('payee_name', 'like', "%{$s}%")))
            ->when($filters['category'] ?? null, fn ($q, $c) => $q->where('expense_category_id', $c))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('expense_date', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('expense_date', '<=', $d));

        /*
         * The headline total counts expenses, not funding allocations, and
         * excludes cancelled rows. Summing allocations here would double-count
         * every split expense.
         */
        $activeTotal = (clone $query)->where('status', TransactionStatus::Active->value)->sum('amount');

        return view('finance.expenses.index', [
            'expenses' => $query->orderByDesc('expense_date')->orderByDesc('id')
                ->paginate(20)->withQueryString(),
            'filters' => $filters,
            'categories' => ExpenseCategory::query()->ordered()->get(),
            'activeTotal' => number_format((float) $activeTotal, 2),
        ]);
    }

    public function create(): View
    {
        return view('finance.expenses.create', $this->formData());
    }

    public function store(StoreExpenseRequest $request, CreateExpense $action): RedirectResponse
    {
        $validated = $request->validated();

        $expense = $action->handle($validated, $validated['allocations']);

        return redirect()->route('finance.expenses.show', $expense)
            ->with('status', __('expenses.created', ['description' => $expense->description]));
    }

    public function show(Expense $expense): View
    {
        $this->assertBelongsToBusiness($expense);

        return view('finance.expenses.show', [
            'expense' => $expense->load([
                'category:id,name',
                'creator:id,name',
                'canceller:id,name',
                'fundingAllocations.source',
                'fundingAllocations.paymentMethod:id,name',
            ]),
        ]);
    }

    public function edit(Expense $expense): View
    {
        $this->assertBelongsToBusiness($expense);
        $this->assertNotCancelled($expense);

        return view('finance.expenses.edit', [
            'expense' => $expense->load('fundingAllocations.source'),
            'categories' => ExpenseCategory::query()->active()->ordered()->get(),
        ]);
    }

    /**
     * Updates descriptive fields only.
     *
     * The amount, date and funding split are not editable after posting; the
     * form request does not accept them, so a crafted payload changes nothing.
     */
    public function update(UpdateExpenseRequest $request, Expense $expense): RedirectResponse
    {
        $this->assertBelongsToBusiness($expense);
        $this->assertNotCancelled($expense);

        DB::transaction(function () use ($request, $expense): void {
            $before = $expense->only(['expense_category_id', 'description', 'payee_name', 'notes']);

            $expense->fill($request->validated())->save();

            $this->audit->updated(
                $expense,
                $before,
                $expense->only(['expense_category_id', 'description', 'payee_name', 'notes']),
                $expense->description,
            );
        });

        return redirect()->route('finance.expenses.show', $expense)
            ->with('status', __('expenses.updated', ['description' => $expense->description]));
    }

    public function cancel(
        CancelTransactionRequest $request,
        Expense $expense,
        CancelExpense $action,
    ): RedirectResponse {
        $this->assertBelongsToBusiness($expense);

        $action->handle($expense, $request->validated()['cancellation_reason']);

        return redirect()->route('finance.expenses.show', $expense)
            ->with('status', __('expenses.cancelled'));
    }

    /** @return array<string, mixed> */
    private function formData(): array
    {
        $businessId = $this->context->business()->id;

        return [
            'categories' => ExpenseCategory::query()->active()->ordered()->get(),
            'accounts' => FinancialAccount::query()
                ->where('business_id', $businessId)->active()->orderBy('name')->get(),
            'partners' => Partner::query()
                ->where('business_id', $businessId)->active()->orderBy('name')->get(),
            'paymentMethods' => PaymentMethod::query()->active()->ordered()->get(),
        ];
    }

    private function assertBelongsToBusiness(Expense $expense): void
    {
        if ((int) $expense->business_id !== (int) $this->context->business()->id) {
            throw ValidationException::withMessages([
                'expense' => __('expenses.errors.wrong_business'),
            ]);
        }
    }

    private function assertNotCancelled(Expense $expense): void
    {
        if ($expense->isCancelled()) {
            throw ValidationException::withMessages([
                'status' => __('expenses.errors.cancelled_not_editable'),
            ]);
        }
    }
}
