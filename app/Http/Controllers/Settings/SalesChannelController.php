<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\SalesChannel;
use App\Services\AuditLogger;
use App\Services\BusinessContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sales channels.
 *
 * The three seeded channels are system channels: later phases branch on their
 * slugs to choose a specialised workflow, so a rename or deletion would break
 * code rather than just a label. Their slugs are therefore fixed and they
 * cannot be deleted, while their display names stay editable.
 *
 * Administrators may add their own channels freely. Those have no specialised
 * behaviour and use the generic sale entry when Phase 5 arrives.
 */
class SalesChannelController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BusinessContext $context,
    ) {}

    public function index(): View
    {
        return view('settings.sales-channels.index', [
            'channels' => SalesChannel::query()
                ->where('business_id', $this->context->business()->id)
                ->withCount('buyers')
                ->ordered()
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $businessId = $this->context->business()->id;

        /*
         * Slugs are stored in one canonical form: lower case, underscores. MySQL
         * compares them case-insensitively but PHP branches on them with `===`, so
         * `Mandali` slipped past the reserved-slug rule, matched the Mandali list's
         * query, and then failed the profile's channel check with a 404.
         */
        if (is_string($request->input('slug'))) {
            $request->merge(['slug' => Str::of($request->input('slug'))->trim()->lower()->replace('-', '_')->value()]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:40', 'alpha_dash',
                Rule::unique('sales_channels', 'slug')->where('business_id', $businessId),
                // A custom channel must not claim a reserved system slug.
                Rule::notIn(SalesChannel::systemSlugs()),
            ],
        ]);

        $channel = DB::transaction(function () use ($validated, $businessId): SalesChannel {
            $channel = SalesChannel::create($validated + [
                'business_id' => $businessId,
                'is_system' => false,
                'is_active' => true,
                'sort_order' => 200,
            ]);

            $this->audit->created($channel, $validated);

            return $channel;
        });

        return back()->with('status', __('settings.sales_channels.created', ['name' => $channel->name]));
    }

    public function update(Request $request, SalesChannel $salesChannel): RedirectResponse
    {
        $this->assertBelongsToBusiness($salesChannel);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($validated, $salesChannel): void {
            $before = $salesChannel->only(['name']);
            $salesChannel->fill($validated)->save();
            $this->audit->updated($salesChannel, $before, $salesChannel->only(['name']));
        });

        return back()->with('status', __('settings.sales_channels.updated', ['name' => $salesChannel->name]));
    }

    public function updateStatus(Request $request, SalesChannel $salesChannel): RedirectResponse
    {
        $this->assertBelongsToBusiness($salesChannel);

        $active = (bool) $request->validate(['is_active' => ['required', 'boolean']])['is_active'];

        DB::transaction(function () use ($salesChannel, $active): void {
            $salesChannel->forceFill(['is_active' => $active])->save();
            $this->audit->statusChanged($salesChannel, $active);
        });

        return back()->with('status', __(
            $active ? 'settings.sales_channels.activated' : 'settings.sales_channels.deactivated',
            ['name' => $salesChannel->name]
        ));
    }

    public function destroy(SalesChannel $salesChannel): RedirectResponse
    {
        $this->assertBelongsToBusiness($salesChannel);

        if ($salesChannel->isSystemChannel()) {
            throw ValidationException::withMessages([
                'channel' => __('settings.sales_channels.errors.system_protected'),
            ]);
        }

        if ($salesChannel->hasBuyers()) {
            throw ValidationException::withMessages([
                'channel' => __('settings.sales_channels.errors.in_use'),
            ]);
        }

        $name = $salesChannel->name;

        DB::transaction(function () use ($salesChannel): void {
            $this->audit->custom(
                AuditAction::Deleted,
                $salesChannel,
                $salesChannel->only(['name', 'slug']),
                [],
            );

            $salesChannel->delete();
        });

        return back()->with('status', __('settings.sales_channels.deleted', ['name' => $name]));
    }

    private function assertBelongsToBusiness(SalesChannel $channel): void
    {
        if ((int) $channel->business_id !== (int) $this->context->business()->id) {
            throw ValidationException::withMessages([
                'channel' => __('settings.sales_channels.errors.wrong_business'),
            ]);
        }
    }
}
