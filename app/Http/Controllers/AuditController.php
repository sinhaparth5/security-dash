<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use Inertia\Inertia;
use Inertia\Response;

class AuditController extends Controller
{
    /**
     * Who registered services, issued or revoked tokens, changed allowlists and signed in, newest first.
     */
    public function __invoke(): Response
    {
        return Inertia::render('audit/Index', [
            'events' => AuditEvent::query()
                ->with(['user:id,name', 'service:id,name,environment'])
                ->latest('id')
                ->simplePaginate(50)
                ->through(fn (AuditEvent $event) => [
                    'id' => $event->id,
                    'action' => $event->action,
                    'details' => $event->details,
                    'ip' => $event->ip,
                    'created_at' => $event->created_at->toIso8601String(),
                    'user' => $event->user?->name,
                    'service' => $event->service ? ['id' => $event->service->id, 'name' => $event->service->name, 'environment' => $event->service->environment] : null,
                ]),
        ]);
    }
}
