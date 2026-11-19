<?php

use App\Enums\ServiceStatus;
use App\Models\Advisory;
use App\Models\AdvisoryHistory;
use App\Models\Dependency;
use App\Models\Service;
use App\Models\ServiceSnapshot;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('dashboard lists services worst status first with their dependencies and advisories', function () {
    Service::factory()->create(['name' => 'a-green', 'status' => ServiceStatus::Green]);
    Service::factory()->create(['name' => 'b-unknown', 'status' => ServiceStatus::Unknown]);
    Service::factory()->create(['name' => 'c-yellow', 'status' => ServiceStatus::Yellow]);
    $red = Service::factory()->create(['name' => 'd-red', 'status' => ServiceStatus::Red]);
    $dependency = Dependency::factory()->for($red)->vulnerable()->create(['name' => 'lodash', 'version' => '4.17.20']);
    Advisory::factory()->for($dependency)->create(['osv_id' => 'GHSA-29mw-wpgm-hmr9', 'fixed_version' => '4.17.21']);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('services.0.name', 'd-red')
            ->where('services.1.name', 'c-yellow')
            ->where('services.2.name', 'b-unknown')
            ->where('services.3.name', 'a-green')
            ->where('services.0.dependencies.0.name', 'lodash')
            ->where('services.0.dependencies.0.status', 'red')
            ->where('services.0.dependencies.0.advisories.0.osv_id', 'GHSA-29mw-wpgm-hmr9')
            ->where('services.0.dependencies.0.advisories.0.fixed_version', '4.17.21')
        );
});

test('dashboard flags services that have not reported recently as stale', function (?string $lastPing, bool $isStale) {
    $this->freezeSecond();
    Service::factory()->create(['last_ping_at' => $lastPing === null ? null : now()->sub($lastPing)]);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('services.0.is_stale', $isStale));
})->with([
    'reported an hour ago' => ['1 hour', false],
    'reported two days ago' => ['2 days', true],
    'never reported' => [null, true],
]);

test('service page shows one service with its dependencies worst first', function () {
    $service = Service::factory()->create(['status' => ServiceStatus::Red]);
    Dependency::factory()->for($service)->create(['name' => 'a-current', 'status' => ServiceStatus::Green]);
    $vulnerable = Dependency::factory()->for($service)->vulnerable()->create(['name' => 'lodash']);
    Advisory::factory()->for($vulnerable)->create(['osv_id' => 'GHSA-29mw-wpgm-hmr9']);

    $this->actingAs(User::factory()->create())
        ->get(route('services.show', $service))
        ->assertInertia(fn (Assert $page) => $page
            ->component('services/Show')
            ->where('service.id', $service->id)
            ->where('service.dependencies.0.name', 'lodash')
            ->where('service.dependencies.0.advisories.0.osv_id', 'GHSA-29mw-wpgm-hmr9')
            ->where('service.dependencies.1.name', 'a-current')
        );
});

test('guests cannot open a service page', function () {
    $this->get(route('services.show', Service::factory()->create()))->assertRedirect(route('login'));
});

test('malware and actively exploited advisories sort first', function () {
    $service = Service::factory()->create(['status' => ServiceStatus::Red]);
    $plain = Dependency::factory()->for($service)->vulnerable()->create(['name' => 'a-plain']);
    Advisory::factory()->for($plain)->create(['osv_id' => 'GHSA-1111-2222-3333']);
    $exploited = Dependency::factory()->for($service)->vulnerable()->create(['name' => 'b-exploited']);
    Advisory::factory()->for($exploited)->create(['osv_id' => 'GHSA-4444-5555-6666', 'known_exploited' => true]);
    $malware = Dependency::factory()->for($service)->vulnerable()->create(['name' => 'c-malware']);
    Advisory::factory()->for($malware)->create(['osv_id' => 'MAL-2026-1']);
    Service::factory()->create(['name' => 'aaa-red', 'status' => ServiceStatus::Red]);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('services.0.id', $service->id)
            ->where('services.0.malicious', true)
            ->where('services.0.dependencies.0.name', 'c-malware')
            ->where('services.0.dependencies.1.name', 'b-exploited')
            ->where('services.0.dependencies.2.name', 'a-plain'));
});

test('the dashboard warns when grading has stalled', function (?string $evaluated, bool $stalled) {
    Service::factory()->create(['created_at' => now()->subDay(), 'evaluated_at' => $evaluated === null ? null : now()->sub($evaluated)]);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('pipeline.stalled', $stalled)->where('pipeline.failed_jobs', 0));
})->with([
    'graded 10 minutes ago' => ['10 minutes', false],
    'graded 3 hours ago' => ['3 hours', true],
    'never graded' => [null, true],
]);

test('the service page includes its status history newest first', function () {
    $service = Service::factory()->create();
    $service->statusChanges()->create(['status' => ServiceStatus::Green, 'created_at' => now()->subDay()]);
    $service->statusChanges()->create(['status' => ServiceStatus::Red]);

    $this->actingAs(User::factory()->create())
        ->get(route('services.show', $service))
        ->assertInertia(fn (Assert $page) => $page
            ->where('history.0.status', 'red')
            ->where('history.1.status', 'green'));
});

test('the dashboard charts how many services sat in each status per day and lists this month\'s changes', function () {
    $this->travelTo(now()->setDate(2026, 10, 20)->setTime(12, 0));
    $api = Service::factory()->create(['name' => 'api', 'status' => ServiceStatus::Red, 'created_at' => now()->subDays(40)]);
    $api->statusChanges()->forceCreate(['status' => ServiceStatus::Green, 'created_at' => now()->subDays(40)]);
    $api->statusChanges()->forceCreate(['status' => ServiceStatus::Red, 'created_at' => now()->subDays(2)]);
    // Graded before status history existed: counted from registration with its current status.
    Service::factory()->create(['status' => ServiceStatus::Yellow, 'created_at' => now()->subDays(10)]);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('history.trend', 30)
            ->where('history.trend.0', ['date' => '2026-09-21', 'green' => 1, 'yellow' => 0, 'red' => 0, 'unknown' => 0])
            ->where('history.trend.20', ['date' => '2026-10-11', 'green' => 1, 'yellow' => 1, 'red' => 0, 'unknown' => 0])
            ->where('history.trend.29', ['date' => '2026-10-20', 'green' => 0, 'yellow' => 1, 'red' => 1, 'unknown' => 0])
            ->has('history.events', 1)
            ->where('history.events.0.service', 'api')
            ->where('history.events.0.status', 'red'));
});

test('the dashboard reports how long fixes took by severity and environment, and how many met their deadline', function () {
    $this->freezeTime();
    $prod = Service::factory()->create(['environment' => 'production']);
    $staging = Service::factory()->create(['environment' => 'staging']);
    $fix = fn (Service $service, string $osvId, ?string $severity, int $days, int $ago = 1) => AdvisoryHistory::create([
        'service_id' => $service->id, 'ecosystem' => 'npm', 'package' => $osvId, 'osv_id' => $osvId, 'severity' => $severity,
        'first_seen_at' => now()->subDays($ago + $days), 'resolved_at' => now()->subDays($ago),
    ]);
    $fix($prod, 'GHSA-1', 'CRITICAL', 1);
    $fix($prod, 'GHSA-2', 'CRITICAL', 2); // the deadline day itself is on time
    $fix($staging, 'GHSA-3', 'critical', 5);
    $fix($prod, 'GHSA-4', 'MEDIUM', 10);
    $fix($prod, 'MAL-1', null, 3);
    $fix($prod, 'GHSA-old', 'HIGH', 1, ago: 91); // outside the 90-day window
    AdvisoryHistory::create(['service_id' => $prod->id, 'ecosystem' => 'npm', 'package' => 'open', 'osv_id' => 'GHSA-5', 'severity' => 'LOW', 'first_seen_at' => now()->subDays(50)]);

    $this->actingAs(User::factory()->create())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('fixTimes.severity', [
                ['label' => 'MALWARE', 'fixed' => 1, 'median_days' => 3, 'p90_days' => 3, 'on_time' => 0],
                ['label' => 'CRITICAL', 'fixed' => 3, 'median_days' => 2, 'p90_days' => 5, 'on_time' => 67],
                ['label' => 'MODERATE', 'fixed' => 1, 'median_days' => 10, 'p90_days' => 10, 'on_time' => 100],
            ])
            ->where('fixTimes.environment', [
                ['label' => 'production', 'fixed' => 4, 'median_days' => 2, 'p90_days' => 10, 'on_time' => 75],
                ['label' => 'staging', 'fixed' => 1, 'median_days' => 5, 'p90_days' => 5, 'on_time' => 0],
            ]));
});

test('the dashboard reports how late the fleet first saw newly published advisories', function () {
    $this->freezeTime();
    $old = Service::factory()->create(['created_at' => now()->subDays(100)]);
    $new = Service::factory()->create(['created_at' => now()->subDays(5)]);
    $seen = fn (Service $service, string $osvId, ?int $publishedAgo, int $seenAgo) => AdvisoryHistory::create([
        'service_id' => $service->id, 'ecosystem' => 'npm', 'package' => 'lodash', 'osv_id' => $osvId,
        'published_at' => $publishedAgo === null ? null : now()->subDays($publishedAgo), 'first_seen_at' => now()->subDays($seenAgo),
    ]);
    $seen($old, 'GHSA-1', 10, 9);  // 1 day late
    $seen($new, 'GHSA-1', 10, 2);  // a later sighting elsewhere doesn't count
    $seen($old, 'GHSA-2', 20, 17); // 3 days late
    $seen($new, 'GHSA-3', 50, 4);  // published before that service reported: onboarding, not detection
    $seen($old, 'GHSA-4', 60, 40); // first seen outside the 30 days
    $seen($old, 'EOL-node', null, 1); // synthetic, no publish date

    $this->actingAs(User::factory()->create())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('fixTimes.detection', ['window_days' => 30, 'seen' => 2, 'median_days' => 1, 'p90_days' => 3]));
});

test('the dashboard ranks open advisories to fix first and says why', function () {
    $prod = Service::factory()->create(['name' => 'billing', 'environment' => 'production']);
    $staging = Service::factory()->create(['name' => 'search', 'environment' => 'staging']);
    $advisory = fn (Service $service, string $osvId, array $attributes = []) => Advisory::factory()
        ->for(Dependency::factory()->for($service)->create(['name' => $osvId]))
        ->create(['osv_id' => $osvId, 'severity' => 'MODERATE', 'epss' => null, 'known_exploited' => false, 'reachable' => null, ...$attributes]);
    $advisory($prod, 'GHSA-epss', ['epss' => 0.5, 'reachable' => true]);            // 1 + 10 + 2 = 13
    $advisory($prod, 'GHSA-kev', ['known_exploited' => true, 'reachable' => false]); // 50 × 0.25 = 12.5
    $advisory($staging, 'MAL-1', ['reachable' => false]);                             // 100 × 0.5, reach doesn't discount malware
    $advisory($prod, 'GHSA-crit', ['severity' => 'CRITICAL']);                        // 1 + 4 = 5
    $advisory($prod, 'GHSA-accepted', ['known_exploited' => true]);
    $prod->riskAcceptances()->create(['osv_id' => 'GHSA-accepted', 'reason' => 'Behind the VPN.']);

    $this->actingAs(User::factory()->create())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('fixFirst', 4)
            ->where('fixFirst.0.osv_id', 'MAL-1')
            ->where('fixFirst.0.reasons', ['malware', 'staging'])
            ->where('fixFirst.1.osv_id', 'GHSA-epss')
            ->where('fixFirst.1.reasons', ['EPSS 50%', 'moderate', 'reachable', 'prod'])
            ->where('fixFirst.2.osv_id', 'GHSA-kev')
            ->where('fixFirst.2.reasons', ['KEV', 'not reached', 'prod'])
            ->where('fixFirst.3.osv_id', 'GHSA-crit')
            ->where('fixFirst.3.score', 5));
});

test('a nightly snapshot records each service\'s drift, and the dashboard shows it against 30 days ago', function () {
    $this->freezeTime();
    $billing = Service::factory()->create(['name' => 'billing', 'status' => ServiceStatus::Yellow]);
    $current = Service::factory()->create(['name' => 'current']);
    Dependency::factory()->for($billing)->create(['name' => 'react', 'version' => '16.14.0', 'latest_version' => '19.1.0']);
    Dependency::factory()->for($billing)->create(['name' => 'golang.org/x/net', 'version' => 'v0.3.0', 'latest_version' => 'v0.40.0']); // 0.x: outdated, 0 majors
    Dependency::factory()->for($billing)->create(['name' => 'node', 'version' => '18.20.0', 'latest_version' => '18.20.0', 'eol_at' => now()->subMonth()]);
    Dependency::factory()->for($billing)->create(['name' => 'private-lib', 'version' => '1.0.0', 'latest_version' => null]);
    Dependency::factory()->for($current)->create(['name' => 'lodash', 'version' => '4.17.21', 'latest_version' => '4.17.21']);
    ServiceSnapshot::create(['service_id' => $billing->id, 'date' => today()->subDays(20), 'status' => ServiceStatus::Green, 'dependencies' => 3, 'outdated' => 1, 'majors_behind' => 1, 'end_of_life' => 0]);
    ServiceSnapshot::create(['service_id' => $billing->id, 'date' => today()->subDays(40), 'status' => ServiceStatus::Green, 'dependencies' => 3, 'outdated' => 0, 'majors_behind' => 0, 'end_of_life' => 0]);

    ServiceSnapshot::take();
    ServiceSnapshot::take(); // twice in a day keeps one row

    expect(ServiceSnapshot::where('date', today()->toDateString())->count())->toBe(2);
    expect(ServiceSnapshot::where('service_id', $billing->id)->where('date', today()->toDateString())->sole()->only(['status', 'dependencies', 'outdated', 'majors_behind', 'end_of_life']))
        ->toBe(['status' => ServiceStatus::Yellow, 'dependencies' => 4, 'outdated' => 2, 'majors_behind' => 3, 'end_of_life' => 1]);

    $this->actingAs(User::factory()->create())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('drift.since', today()->subDays(20)->toDateString())
            ->where('drift.totals', ['outdated' => 2, 'majors_behind' => 3, 'end_of_life' => 1])
            ->has('drift.services', 1)
            ->where('drift.services.0.name', 'billing')
            ->where('drift.services.0.majors_change', 2));
});

test('the dashboard reports how long each service sat red over 90 days, counting a service still red up to now', function () {
    $this->freezeTime();
    $billing = Service::factory()->create(['name' => 'billing', 'environment' => 'production', 'status' => ServiceStatus::Red]);
    $api = Service::factory()->create(['name' => 'api', 'environment' => 'production', 'status' => ServiceStatus::Green]);
    $legacy = Service::factory()->create(['name' => 'legacy', 'environment' => 'staging', 'status' => ServiceStatus::Red, 'created_at' => now()->subDays(5)]);
    Service::factory()->create(['name' => 'calm', 'status' => ServiceStatus::Green]);
    $change = fn (Service $service, ServiceStatus $status, int $daysAgo) => $service->statusChanges()->create(['status' => $status])->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
    // Red from before the window: only the 90 days inside it count, and the spell didn't start in it.
    $change($billing, ServiceStatus::Red, 100);
    $change($billing, ServiceStatus::Green, 80);
    $change($billing, ServiceStatus::Red, 3); // still red
    $change($api, ServiceStatus::Red, 20);
    $change($api, ServiceStatus::Yellow, 16);
    $change($api, ServiceStatus::Red, 10);
    $change($api, ServiceStatus::Green, 8);

    $this->actingAs(User::factory()->create())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('redTime.services', [
                ['id' => $billing->id, 'name' => 'billing', 'environment' => 'production', 'red_days' => 13, 'times_red' => 1, 'median_recovery_days' => 20, 'red_now' => true],
                ['id' => $api->id, 'name' => 'api', 'environment' => 'production', 'red_days' => 6, 'times_red' => 2, 'median_recovery_days' => 2, 'red_now' => false],
                ['id' => $legacy->id, 'name' => 'legacy', 'environment' => 'staging', 'red_days' => 5, 'times_red' => 1, 'median_recovery_days' => null, 'red_now' => true],
            ])
            ->where('redTime.environment', [
                ['label' => 'production', 'services' => 2, 'red_days' => 19, 'times_red' => 3],
                ['label' => 'staging', 'services' => 1, 'red_days' => 5, 'times_red' => 1],
            ]));
});
