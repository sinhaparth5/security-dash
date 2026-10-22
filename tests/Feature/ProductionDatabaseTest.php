<?php

use App\Providers\AppServiceProvider;

test('production refuses to boot on SQLite', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['database.default' => 'sqlite']);

    expect(fn () => (new AppServiceProvider(app()))->boot())
        ->toThrow(RuntimeException::class, 'Production must run on PostgreSQL');
});
