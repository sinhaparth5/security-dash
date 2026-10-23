<?php

use App\Enums\Ecosystem;
use App\Jobs\EvaluateService;
use App\Models\Dependency;
use App\Models\Service;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Queue::fake();
});

test('a CycloneDX SBOM replaces npm and Go dependencies and keeps the runtime', function () {
    $service = Service::factory()->create();
    $runtime = Dependency::factory()->for($service)->create(['ecosystem' => Ecosystem::Runtime, 'name' => 'node', 'version' => 'v22.11.0']);
    $gone = Dependency::factory()->for($service)->create(['ecosystem' => Ecosystem::Npm, 'name' => 'left-pad']);
    Sanctum::actingAs($service);

    $this->postJson(route('api.v1.sbom'), [
        'bomFormat' => 'CycloneDX',
        'specVersion' => '1.6',
        'components' => [
            ['type' => 'library', 'name' => 'express', 'purl' => 'pkg:npm/express@4.21.2', 'components' => [
                ['type' => 'library', 'purl' => 'pkg:npm/%40types/node@22.1.0?vcs_url=x'],
            ]],
            ['type' => 'library', 'purl' => 'pkg:golang/golang.org/x/net@v0.40.0'],
            ['type' => 'library', 'purl' => 'pkg:pypi/requests@2.32.0'],
            ['type' => 'library', 'name' => 'no-purl'],
        ],
    ])->assertNoContent();

    expect($service->dependencies()->orderBy('name')->get()->map->only('ecosystem', 'name', 'version')->all())->toBe([
        ['ecosystem' => Ecosystem::Npm, 'name' => '@types/node', 'version' => '22.1.0'],
        ['ecosystem' => Ecosystem::Npm, 'name' => 'express', 'version' => '4.21.2'],
        ['ecosystem' => Ecosystem::Go, 'name' => 'golang.org/x/net', 'version' => 'v0.40.0'],
        ['ecosystem' => Ecosystem::Runtime, 'name' => 'node', 'version' => 'v22.11.0'],
    ]);
    $this->assertModelMissing($gone);
    $this->assertModelExists($runtime);
    expect($service->fresh()->last_ping_at)->not->toBeNull();
    Queue::assertPushed(EvaluateService::class);
});

test('an SBOM that is not CycloneDX is rejected', function () {
    Sanctum::actingAs(Service::factory()->create());

    $this->postJson(route('api.v1.sbom'), ['bomFormat' => 'SPDX', 'components' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('bomFormat');
});
