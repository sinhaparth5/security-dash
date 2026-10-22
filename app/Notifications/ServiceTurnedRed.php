<?php

namespace App\Notifications;

use App\Enums\ServiceStatus;
use App\Models\Dependency;
use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ServiceTurnedRed extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * How many vulnerable dependencies the mail lists before pointing to the dashboard.
     */
    private const LISTED_DEPENDENCIES = 5;

    public function __construct(public Service $service) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $vulnerable = $this->service->dependencies()
            ->where('status', ServiceStatus::Red)
            ->withCount('advisories')
            ->with('advisories')
            ->orderByDesc('advisories_count')
            ->get();

        $message = (new MailMessage)
            ->error()
            ->subject("Security patch required: {$this->service->name} ({$this->service->environment})")
            ->line("{$this->service->name} ({$this->service->environment}) is running versions with known security advisories.");

        foreach ($vulnerable->take(self::LISTED_DEPENDENCIES) as $dependency) {
            $message->line($this->describe($dependency));
        }

        if ($vulnerable->count() > self::LISTED_DEPENDENCIES) {
            $message->line('…and '.($vulnerable->count() - self::LISTED_DEPENDENCIES).' more.');
        }

        return $message->action('Open the dashboard', route('dashboard'));
    }

    private function describe(Dependency $dependency): string
    {
        $fixedVersion = $dependency->advisories->pluck('fixed_version')->filter()
            ->sort(fn (string $a, string $b) => version_compare($b, $a))
            ->first();

        return "{$dependency->name} {$dependency->version}: {$dependency->advisories_count} "
            .str('advisory')->plural($dependency->advisories_count)
            .($fixedVersion ? ", upgrade to {$fixedVersion} or later" : '');
    }
}
