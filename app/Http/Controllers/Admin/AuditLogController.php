<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\MorphMap;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The audit log viewer.
 *
 * Read-only by design: there is no store, update or destroy action and no
 * route to one. An audit trail that its subjects can edit proves nothing.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'action' => ['nullable', Rule::in(AuditAction::values())],
            'auditable_type' => ['nullable', Rule::in(array_keys(MorphMap::map()))],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $logs = AuditLog::query()
            ->with('user:id,name,email')
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->when($filters['auditable_type'] ?? null, fn ($q, $type) => $q->where('auditable_type', $type))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where('subject', 'like', "%{$search}%"))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('admin.audit.index', [
            'logs' => $logs,
            'filters' => $filters,
            'actions' => AuditAction::cases(),
            // Only types that actually appear, so the filter has no dead options.
            'types' => AuditLog::query()->distinct()->orderBy('auditable_type')->pluck('auditable_type'),
            'actors' => User::query()
                ->whereIn('id', AuditLog::query()->whereNotNull('user_id')->distinct()->select('user_id'))
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function show(AuditLog $auditLog): View
    {
        return view('admin.audit.show', [
            'log' => $auditLog->load('user:id,name,email'),
        ]);
    }
}
