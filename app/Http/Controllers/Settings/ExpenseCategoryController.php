<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use App\Services\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExpenseCategoryController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        return view('settings.expense-categories.index', [
            'categories' => ExpenseCategory::query()->withCount('expenses')->ordered()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:40', 'alpha_dash', Rule::unique('expense_categories', 'code')],
        ]);

        $category = DB::transaction(function () use ($validated): ExpenseCategory {
            $category = ExpenseCategory::create($validated + [
                'is_system' => false,
                'is_active' => true,
                'sort_order' => 200,
            ]);

            $this->audit->created($category, $validated);

            return $category;
        });

        return back()->with('status', __('settings.expense_categories.created', ['name' => $category->name]));
    }

    public function update(Request $request, ExpenseCategory $expenseCategory): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($validated, $expenseCategory): void {
            $before = $expenseCategory->only(['name']);
            $expenseCategory->fill($validated)->save();
            $this->audit->updated($expenseCategory, $before, $expenseCategory->only(['name']));
        });

        return back()->with('status', __('settings.expense_categories.updated', ['name' => $expenseCategory->name]));
    }

    public function updateStatus(Request $request, ExpenseCategory $expenseCategory): RedirectResponse
    {
        $active = (bool) $request->validate(['is_active' => ['required', 'boolean']])['is_active'];

        DB::transaction(function () use ($expenseCategory, $active): void {
            $expenseCategory->forceFill(['is_active' => $active])->save();
            $this->audit->statusChanged($expenseCategory, $active);
        });

        return back()->with('status', __(
            $active ? 'settings.expense_categories.activated' : 'settings.expense_categories.deactivated',
            ['name' => $expenseCategory->name]
        ));
    }

    /** A category that has ever been used stays, so old expenses keep meaning. */
    public function destroy(ExpenseCategory $expenseCategory): RedirectResponse
    {
        if ($expenseCategory->is_system) {
            throw ValidationException::withMessages([
                'category' => __('settings.expense_categories.errors.system_protected'),
            ]);
        }

        if ($expenseCategory->isInUse()) {
            throw ValidationException::withMessages([
                'category' => __('settings.expense_categories.errors.in_use'),
            ]);
        }

        $name = $expenseCategory->name;

        DB::transaction(function () use ($expenseCategory): void {
            $this->audit->custom(
                AuditAction::Deleted,
                $expenseCategory,
                $expenseCategory->only(['name', 'code']),
                [],
            );

            $expenseCategory->delete();
        });

        return back()->with('status', __('settings.expense_categories.deleted', ['name' => $name]));
    }
}
