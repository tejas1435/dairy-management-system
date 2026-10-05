<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\BusinessContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The foundation dashboard.
 *
 * Phase 1 deliberately shows only data that genuinely exists: the business, its
 * primary farm, the signed-in user and their roles. No milk, revenue, expense or
 * animal figures appear, because inventing them would make an empty system look
 * populated and would have to be unpicked later. Phase 8 builds the real
 * operational dashboard on top of this page.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, BusinessContext $context): View
    {
        return view('dashboard', [
            'business' => $context->businessOrNull(),
            'primaryFarm' => $context->primaryFarmOrNull(),
            'user' => $request->user()->loadMissing('roles'),
        ]);
    }
}
