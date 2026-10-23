<?php

use App\Enums\ServiceStatus;
use App\Models\Advisory;
use App\Models\Dependency;
use App\Models\Service;
use App\Models\User;
use App\Notifications\ServiceTurnedRed;

test('the mail names the service and lists vulnerable dependencies with the version that fixes them all', function () {
    $service = Service::factory()->create(['name' => 'auth-gateway', 'environment' => 'staging', 'status' => ServiceStatus::Red]);
    $dependency = Dependency::factory()->for($service)->vulnerable()->create(['name' => 'golang.org/x/net', 'version' => '0.20.0']);
    Advisory::factory()->for($dependency)->create(['fixed_version' => '0.23.0']);
    Advisory::factory()->for($dependency)->create(['fixed_version' => '0.38.0']);
    Dependency::factory()->for($service)->create(['name' => 'healthy-package']);

    $mail = (new ServiceTurnedRed($service))->toMail(User::factory()->make());
    $content = $mail->render();

    expect($mail->subject)->toBe('Security patch required: auth-gateway (staging)');
    expect((string) $content)
        ->toContain('golang.org/x/net 0.20.0: 2 advisories, upgrade to 0.38.0 or later')
        ->toContain(route('dashboard'))
        ->not->toContain('healthy-package');
});

test('the mail escapes dependency names reported by the service', function () {
    $service = Service::factory()->create(['status' => ServiceStatus::Red]);
    $dependency = Dependency::factory()->for($service)->vulnerable()->create(['name' => "<script>alert('xss')</script>"]);
    Advisory::factory()->for($dependency)->create();

    $content = (string) (new ServiceTurnedRed($service))->toMail(User::factory()->make())->render();

    expect($content)
        ->toContain('&lt;script&gt;')
        ->not->toContain("<script>alert('xss')</script>");
});

test('the mail does not turn names reported by the service into links', function () {
    $service = Service::factory()->create(['status' => ServiceStatus::Red]);
    $dependency = Dependency::factory()->for($service)->vulnerable()->create(['name' => '[Sign in again](https://evil.example/login)']);
    Advisory::factory()->for($dependency)->create();

    $content = (string) (new ServiceTurnedRed($service))->toMail(User::factory()->make())->render();

    expect($content)
        ->toContain('[Sign in again](https://evil.example/login)')
        ->not->toContain('href="https://evil.example/login"');
});

test('the mail summarises dependencies beyond the first five', function () {
    $service = Service::factory()->create(['status' => ServiceStatus::Red]);
    Dependency::factory()->for($service)->vulnerable()->count(7)->create()
        ->each(fn (Dependency $dependency) => Advisory::factory()->for($dependency)->create());

    $content = (string) (new ServiceTurnedRed($service))->toMail(User::factory()->make())->render();

    expect($content)->toContain('…and 2 more.');
});
