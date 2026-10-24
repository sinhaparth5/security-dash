<?php

use App\Enums\Ecosystem;
use App\Models\Advisory;
use App\Models\Dependency;
use App\Models\Service;
use App\Models\User;

test('the sbom lists every package with its graph and a vex statement per advisory', function () {
    $user = User::factory()->create(['name' => 'Ada']);
    $service = Service::factory()->create(['name' => 'billing', 'requires' => ['@acme/ui' => '^1.0.0']]);
    $ui = Dependency::factory()->for($service)->create(['ecosystem' => Ecosystem::Npm, 'name' => '@acme/ui', 'version' => '1.2.0', 'requires' => ['lodash' => '^4.17.0']]);
    $lodash = Dependency::factory()->for($service)->create(['ecosystem' => Ecosystem::Npm, 'name' => 'lodash', 'version' => '4.17.20', 'requires' => []]);
    $net = Dependency::factory()->for($service)->create(['ecosystem' => Ecosystem::Go, 'name' => 'golang.org/x/net', 'version' => 'v0.17.0']);
    Dependency::factory()->for($service)->create(['ecosystem' => Ecosystem::Image, 'name' => 'ghcr.io/acme/billing', 'version' => 'latest']);

    Advisory::factory()->for($lodash)->create(['osv_id' => 'GHSA-unreached', 'severity' => 'HIGH', 'fixed_version' => '4.17.21', 'reachable' => false]);
    Advisory::factory()->for($lodash)->create(['osv_id' => 'GHSA-accepted', 'fixed_version' => null]);
    Advisory::factory()->for($ui)->create(['osv_id' => 'GHSA-open', 'severity' => 'MODERATE', 'fixed_version' => '1.3.0', 'reachable' => true]);
    Advisory::factory()->for($net)->create(['osv_id' => 'GO-2024-2687', 'fixed_version' => '0.23.0', 'reachable' => false, 'affected_symbols' => ['golang.org/x/net/http2.Framer.ReadFrame']]);
    $service->riskAcceptances()->create(['user_id' => $user->id, 'osv_id' => 'GHSA-accepted', 'reason' => 'Only used in a build script.']);

    $bom = $this->actingAs($user)->get(route('services.sbom', $service))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="billing-'.$service->environment.'.cdx.json"')
        ->json();

    expect($bom)->bomFormat->toBe('CycloneDX')->specVersion->toBe('1.6')
        ->and(array_column($bom['components'], 'purl'))->toBe(['pkg:golang/golang.org/x/net@v0.17.0', 'pkg:npm/%40acme/ui@1.2.0', 'pkg:npm/lodash@4.17.20'])
        ->and($bom['dependencies'][0])->toBe(['ref' => 'service', 'dependsOn' => ['pkg:golang/golang.org/x/net@v0.17.0', 'pkg:npm/%40acme/ui@1.2.0']])
        ->and($bom['dependencies'][1])->toBe(['ref' => 'pkg:npm/%40acme/ui@1.2.0', 'dependsOn' => ['pkg:npm/lodash@4.17.20']]);

    $analysis = collect($bom['vulnerabilities'])->pluck('analysis', 'id');

    expect($analysis['GHSA-unreached'])->toMatchArray(['state' => 'not_affected', 'justification' => 'code_not_reachable'])
        ->and($analysis['GO-2024-2687'])->toMatchArray(['state' => 'not_affected', 'justification' => 'code_not_present'])
        ->and($analysis['GO-2024-2687']['detail'])->toContain('golang.org/x/net/http2.Framer.ReadFrame')
        ->and($analysis['GHSA-accepted'])->toMatchArray(['state' => 'exploitable', 'response' => ['will_not_fix'], 'detail' => 'Risk accepted by Ada: Only used in a build script.'])
        ->and($analysis['GHSA-open'])->toMatchArray(['state' => 'in_triage', 'response' => ['update'], 'detail' => 'Fixed in 1.3.0.']);
    $this->assertDatabaseHas('audit_events', ['action' => 'service.sbom_exported', 'service_id' => $service->id]);
});

test('guests cannot export an sbom', function () {
    $this->get(route('services.sbom', Service::factory()->create()))->assertRedirect(route('login'));
});
