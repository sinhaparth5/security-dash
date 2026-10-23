<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
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

        $issued = $service->createToken('heartbeat');
        $token = $issued->plainTextToken;

        if ($service->wasRecentlyCreated) {
            AuditEvent::record('service.registered', $service, ['via' => 'console']);
        }

        AuditEvent::record('token.issued', $service, ['token_id' => $issued->accessToken->id, 'via' => 'console']);

        $this->info("Token for {$service->name} ({$service->environment}). It will not be shown again:");
        $this->line($token);

        return self::SUCCESS;
    }
}
