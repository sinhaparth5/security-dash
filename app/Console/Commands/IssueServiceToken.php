<?php

namespace App\Console\Commands;

use App\Models\Service;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('service:token {name} {environment}')]
#[Description('Register a service (if new) and issue it a heartbeat API token')]
class IssueServiceToken extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $service = Service::firstOrCreate([
            'name' => $this->argument('name'),
            'environment' => $this->argument('environment'),
        ]);

        $token = $service->createToken('heartbeat')->plainTextToken;

        $this->info("Token for {$service->name} ({$service->environment}). It will not be shown again:");
        $this->line($token);

        return self::SUCCESS;
    }
}
