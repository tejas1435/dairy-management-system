<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Actions\Partners\CancelPartnerContribution;
use App\Actions\Partners\CreatePartnerContribution;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\CancelTransactionRequest;
use App\Http\Requests\Finance\StorePartnerContributionRequest;
use App\Models\Partner;
use App\Models\PartnerContribution;
use Illuminate\Http\RedirectResponse;

/**
 * Recording and cancelling partner contributions.
 *
 * The controller resolves the request and hands off; the money logic, the
 * ledger posting and the audit record all live in the actions, inside one
 * transaction.
 */
class PartnerContributionController extends Controller
{
    public function store(
        StorePartnerContributionRequest $request,
        Partner $partner,
        CreatePartnerContribution $action,
    ): RedirectResponse {
        $contribution = $action->handle($partner, $request->validated());

        return redirect()->route('finance.partners.show', $partner)
            ->with('status', __('partners.contribution_recorded', [
                'amount' => number_format((float) $contribution->amount, 2),
            ]));
    }

    public function cancel(
        CancelTransactionRequest $request,
        PartnerContribution $contribution,
        CancelPartnerContribution $action,
    ): RedirectResponse {
        $action->handle($contribution, $request->validated()['cancellation_reason']);

        return redirect()->route('finance.partners.show', $contribution->partner_id)
            ->with('status', __('partners.contribution_cancelled'));
    }
}
