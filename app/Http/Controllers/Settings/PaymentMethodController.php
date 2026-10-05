<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Services\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Payment methods: how money moved, recorded only.
 *
 * Nothing here processes a payment. The seeded methods have fixed codes because
 * business logic matches on the code, so a method can be relabelled in any
 * language without breaking anything, and can be deactivated but never deleted
 * once it appears on a posted record.
 */
class PaymentMethodController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        return view('settings.payment-methods.index', [
            'methods' => PaymentMethod::query()
                ->withCount(['partnerContributions', 'fundingAllocations'])
                ->ordered()
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:40', 'alpha_dash', Rule::unique('payment_methods', 'code')],
        ]);

        $method = DB::transaction(function () use ($validated): PaymentMethod {
            $method = PaymentMethod::create($validated + [
                'is_system' => false,
                'is_active' => true,
                'sort_order' => 200,
            ]);

            $this->audit->created($method, $validated);

            return $method;
        });

        return back()->with('status', __('settings.payment_methods.created', ['name' => $method->name]));
    }

    /** Only the display name is editable; the code is the stable identifier. */
    public function update(Request $request, PaymentMethod $paymentMethod): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($validated, $paymentMethod): void {
            $before = $paymentMethod->only(['name']);
            $paymentMethod->fill($validated)->save();
            $this->audit->updated($paymentMethod, $before, $paymentMethod->only(['name']));
        });

        return back()->with('status', __('settings.payment_methods.updated', ['name' => $paymentMethod->name]));
    }

    public function updateStatus(Request $request, PaymentMethod $paymentMethod): RedirectResponse
    {
        $active = (bool) $request->validate(['is_active' => ['required', 'boolean']])['is_active'];

        DB::transaction(function () use ($paymentMethod, $active): void {
            $paymentMethod->forceFill(['is_active' => $active])->save();
            $this->audit->statusChanged($paymentMethod, $active);
        });

        return back()->with('status', __(
            $active ? 'settings.payment_methods.activated' : 'settings.payment_methods.deactivated',
            ['name' => $paymentMethod->name]
        ));
    }

    /**
     * Deletion is allowed only for an administrator-added method that nothing
     * references. Historical records must keep describing how they were paid.
     */
    public function destroy(PaymentMethod $paymentMethod): RedirectResponse
    {
        if ($paymentMethod->is_system) {
            throw ValidationException::withMessages([
                'method' => __('settings.payment_methods.errors.system_protected'),
            ]);
        }

        if ($paymentMethod->isInUse()) {
            throw ValidationException::withMessages([
                'method' => __('settings.payment_methods.errors.in_use'),
            ]);
        }

        $name = $paymentMethod->name;

        DB::transaction(function () use ($paymentMethod): void {
            $this->audit->custom(
                AuditAction::Deleted,
                $paymentMethod,
                $paymentMethod->only(['name', 'code']),
                [],
            );

            $paymentMethod->delete();
        });

        return back()->with('status', __('settings.payment_methods.deleted', ['name' => $name]));
    }
}
