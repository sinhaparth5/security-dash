<?php

use App\Enums\Ecosystem;
use App\Enums\ServiceStatus;
use App\Jobs\EvaluateService;
use App\Models\Advisory;
use App\Models\Dependency;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Queue::fake();
});

/**
 * @param  list<array<string, string>>  $vulnerabilities
 * @return array<string, mixed>
 */
function trivyReport(string $artifactName, array $vulnerabilities): array
{
    return [
        'SchemaVersion' => 2,
        'ArtifactName' => $artifactName,
        'Results' => [
            ['Target' => 'debian 12.5', 'Class' => 'os-pkgs', 'Vulnerabilities' => $vulnerabilities],
            ['Target' => 'app/go.mod', 'Class' => 'lang-pkgs'],
        ],
    ];
}

test('image scan without a token returns 401', function () {
    $this->postJson(route('api.v1.image-scan'), trivyReport('app:1.0', []))
        ->assertUnauthorized();
});

test('image scan from a logged-in dashboard user returns 403', function () {
    $this->actingAs(User::factory()->create(), 'web');

    $this->postJson(route('api.v1.image-scan'), trivyReport('app:1.0', []))
        ->assertForbidden();
});

test('image scan with an invalid report returns 422 and queues nothing', function () {
    Sanctum::actingAs(Service::factory()->create());

    $this->postJson(route('api.v1.image-scan'), ['Results' => [['Vulnerabilities' => [['PkgName' => 'openssl']]]]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ArtifactName', 'Results.0.Vulnerabilities.0.VulnerabilityID']);

    Queue::assertNothingPushed();
});

test('image scan with findings turns the image red and stores one advisory per vulnerability', function () {
    $service = Service::factory()->create();
    Sanctum::actingAs($service);

    $this->postJson(route('api.v1.image-scan'), trivyReport('ghcr.io/acme/billing:1.4.2', [
        ['VulnerabilityID' => 'CVE-2024-5535', 'PkgName' => 'libssl3', 'InstalledVersion' => '3.0.11-1', 'FixedVersion' => '3.0.14-1', 'Severity' => 'CRITICAL', 'Title' => 'openssl: SSL_select_next_proto buffer overread'],
        ['VulnerabilityID' => 'CVE-2024-5535', 'PkgName' => 'openssl', 'InstalledVersion' => '3.0.11-1', 'FixedVersion' => '3.0.14-1', 'Severity' => 'CRITICAL'],
        ['VulnerabilityID' => 'CVE-2023-45853', 'PkgName' => 'zlib1g', 'InstalledVersion' => '1:1.2.13', 'Severity' => 'HIGH'],
    ]))->assertNoContent();

    $image = $service->dependencies()->sole();
    expect($image->ecosystem)->toBe(Ecosystem::Image);
    expect($image->name)->toBe('ghcr.io/acme/billing');
    expect($image->version)->toBe('1.4.2');
    expect($image->status)->toBe(ServiceStatus::Red);
    expect($image->advisories()->orderBy('osv_id')->get(['osv_id', 'severity', 'summary', 'fixed_version'])->toArray())->toBe([
        ['osv_id' => 'CVE-2023-45853', 'severity' => 'HIGH', 'summary' => 'zlib1g 1:1.2.13', 'fixed_version' => null],
        ['osv_id' => 'CVE-2024-5535', 'severity' => 'CRITICAL', 'summary' => 'libssl3 3.0.11-1 (fixed in 3.0.14-1): openssl: SSL_select_next_proto buffer overread', 'fixed_version' => null],
    ]);
    Queue::assertPushed(EvaluateService::class, fn (EvaluateService $job) => $job->service->is($service));
});

test('a clean re-scan replaces the previous findings and turns the image green', function () {
    $service = Service::factory()->create();
    $image = Dependency::factory()->for($service)->vulnerable()->create(['ecosystem' => Ecosystem::Image, 'name' => 'ghcr.io/acme/billing', 'version' => '1.4.2']);
    $fixed = Advisory::factory()->for($image)->create();
    Sanctum::actingAs($service);

    $this->postJson(route('api.v1.image-scan'), trivyReport('ghcr.io/acme/billing:1.4.3', []))
        ->assertNoContent();

    $this->assertModelMissing($fixed);
    $image->refresh();
    expect($image->version)->toBe('1.4.3');
    expect($image->status)->toBe(ServiceStatus::Green);
});

test('image references are split into repository and version', function (string $reference, string $name, string $version) {
    $service = Service::factory()->create();
    Sanctum::actingAs($service);

    $this->postJson(route('api.v1.image-scan'), trivyReport($reference, []))->assertNoContent();

    expect($service->dependencies()->sole()->only('name', 'version'))->toBe(['name' => $name, 'version' => $version]);
})->with([
    'tag' => ['alpine:3.20', 'alpine', '3.20'],
    'registry port and tag' => ['localhost:5000/acme/app:2.0', 'localhost:5000/acme/app', '2.0'],
    'registry port without tag' => ['localhost:5000/acme/app', 'localhost:5000/acme/app', 'latest'],
    'digest wins over tag' => ['acme/app:2.0@sha256:abc123', 'acme/app', 'sha256:abc123'],
]);
