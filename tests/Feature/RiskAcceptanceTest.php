<?php

use App\Jobs\EvaluateService;
use App\Models\Advisory;
use App\Models\Dependency;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake();
});

test('accepting an advisory records why, re-grades the service and shows it as accepted', function () {
    $dependency = Dependency::factory()->vulnerable()->create();
    Advisory::factory()->for($dependency)->create(['osv_id' => 'GHSA-aaaa-bbbb-cccc']);
    $service = $dependency->service;

    $this->actingAs(User::factory()->create(['name' => 'Parth']))
        ->post(route('services.risk-acceptances.store', $service), [
            'osv_id' => 'GHSA-aaaa-bbbb-cccc',
            'reason' => 'We never parse untrusted input with it.',
            'expires_at' => now()->addMonth()->toDateString(),
        ])
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'success');

    Queue::assertPushed(EvaluateService::class, fn (EvaluateService $job) => $job->service->is($service));
    $this->assertDatabaseHas('audit_events', ['action' => 'risk.accepted', 'service_id' => $service->id]);

    $this->get(route('services.show', $service))->assertInertia(fn (Assert $page) => $page
        ->where('service.dependencies.0.advisories.0.accepted', true)
        ->where('service.risk_acceptances.0.reason', 'We never parse untrusted input with it.')
        ->where('service.risk_acceptances.0.accepted_by', 'Parth'));
});

test('an acceptance needs a reason and a future expiry', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('services.risk-acceptances.store', Service::factory()->create()), [
            'osv_id' => 'GHSA-aaaa-bbbb-cccc',
            'expires_at' => now()->subDay()->toDateString(),
        ])
        ->assertSessionHasErrors(['reason', 'expires_at']);
});

test('removing an acceptance only works through its own service', function () {
    $service = Service::factory()->create();
    $acceptance = $service->riskAcceptances()->create(['osv_id' => 'GHSA-aaaa-bbbb-cccc', 'reason' => 'x']);
    $user = User::factory()->create();

    $this->actingAs($user)->delete(route('services.risk-acceptances.destroy', [Service::factory()->create(), $acceptance]))->assertNotFound();
    $this->actingAs($user)->delete(route('services.risk-acceptances.destroy', [$service, $acceptance]))->assertRedirect();

    $this->assertModelMissing($acceptance);
    Queue::assertPushed(EvaluateService::class);
});
