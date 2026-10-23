<?php

namespace App\Notifications;

use App\Enums\ServiceStatus;
use App\Models\Advisory;
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

        $malicious = $vulnerable->flatMap->advisories->filter(fn (Advisory $advisory) => $advisory->isMalicious());
        $label = "{$this->service->name} ({$this->service->environment})";

        $message = (new MailMessage)->error();

        if ($malicious->isNotEmpty()) {
            $message->subject("Malicious package detected: {$label}")
                ->line("{$label} depends on a package OSV reports as malware ({$malicious->pluck('osv_id')->unique()->join(', ')}).")
                ->line('Treat every machine that installed it as compromised: remove the package, rebuild from a clean state and rotate any secrets it could read.');
        } else {
            $message->subject("Security patch required: {$label}")
                ->line("{$label} is running versions with known security advisories.");
        }

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
