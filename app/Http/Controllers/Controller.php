<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /**
     * Gives controllers $this->authorize(), used where the permission depends
     * on the record rather than only on the route (buyers, whose permission
     * family comes from their sales channel).
     */
    use AuthorizesRequests;
}
