<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\DateFormat;
use App\Enums\Locale;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateBusinessRequest;
use App\Services\AuditLogger;
use App\Services\BusinessContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class BusinessController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function edit(BusinessContext $context): View
    {
        return view('settings.business.edit', [
            'business' => $context->business(),
            'locales' => Locale::cases(),
            'dateFormats' => DateFormat::cases(),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }

    public function update(UpdateBusinessRequest $request, BusinessContext $context): RedirectResponse
    {
        $business = $context->business();

        DB::transaction(function () use ($request, $business): void {
            $tracked = [
                'name', 'legal_name', 'mobile', 'email', 'address',
                'currency', 'timezone', 'date_format', 'default_locale', 'is_active',
            ];

            $before = $business->only($tracked);

            $business->fill($request->validated())->save();

            /*
             * Currency, timezone, date format and default locale change how
             * every figure in the application is interpreted and displayed, so
             * a change here is worth a record of who made it.
             */
            $this->audit->updated($business, $before, $business->only($tracked), $business->name);
        });

        $context->forget();

        return redirect()->route('settings.business.edit')
            ->with('status', __('settings.business.updated'));
    }
}
