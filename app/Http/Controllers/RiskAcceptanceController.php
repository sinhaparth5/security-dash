<?php

namespace App\Http\Controllers;

use App\Jobs\EvaluateService;
use App\Models\AuditEvent;
use App\Models\RiskAcceptance;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RiskAcceptanceController extends Controller
{
    /**
     * Accept an advisory for this service (not affected, mitigated, or can wait), with a reason
     * and an optional expiry. The service is re-graded right after.
     */
    public function store(Request $request, Service $service): RedirectResponse
    {
        $validated = $request->validate([
            'osv_id' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:1000'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);

        $acceptance = $service->riskAcceptances()->updateOrCreate(
            ['osv_id' => $validated['osv_id']],
            ['reason' => $validated['reason'], 'expires_at' => $validated['expires_at'] ?? null, 'user_id' => $request->user()?->id],
        );

        AuditEvent::record('risk.accepted', $service, ['advisory' => $acceptance->osv_id, 'reason' => $acceptance->reason, 'expires_at' => $acceptance->expires_at?->toDateString()]);
        EvaluateService::dispatch($service);
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$acceptance->osv_id} accepted. Re-grading the service."]);

        return back();
    }

    /**
     * Stop accepting an advisory, so it counts again from the next evaluation.
     */
    public function destroy(Service $service, RiskAcceptance $riskAcceptance): RedirectResponse
    {
        $riskAcceptance->delete();

        AuditEvent::record('risk.revoked', $service, ['advisory' => $riskAcceptance->osv_id]);
        EvaluateService::dispatch($service);
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$riskAcceptance->osv_id} counts again. Re-grading the service."]);

        return back();
    }
}
