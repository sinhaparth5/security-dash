<?php

namespace App\Notifications;

use App\Enums\ServiceStatus;
use App\Http\Controllers\DashboardController;
use App\Models\AdvisoryHistory;
use App\Models\Dependency;
use App\Models\Service;
use App\Models\StatusChange;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WeeklyDigest extends Notification implements ShouldQueue
{
    use Queueable;

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
     * What moved this week, from the advisory history: new advisories by severity and how many were fixed.
     * Each counts once per service and package, so one advisory in three services is three.
     */
    public static function advisoryChanges(CarbonInterface $since): string
    {
        $new = AdvisoryHistory::where('first_seen_at', '>=', $since)->get(['osv_id', 'severity'])
            ->countBy(fn (AdvisoryHistory $row) => match (true) {
                str_starts_with($row->osv_id, 'MAL-') => 'malware',
                $row->severity === null => 'unrated',
                default => strtolower(str_replace('MEDIUM', 'MODERATE', strtoupper($row->severity))),
            })
            ->sortBy(fn (int $count, string $severity) => array_search($severity, ['malware', 'critical', 'high', 'moderate', 'low', 'unrated'], true));
        $fixed = AdvisoryHistory::where('resolved_at', '>=', $since)->count();
        $total = $new->sum();

        return 'This week: '.($total === 0 ? 'no new advisories' : "+{$total} new (".$new->map(fn (int $count, string $severity) => "{$count} {$severity}")->join(', ').')')
            .", {$fixed} fixed.";
    }

    /**
     * The last seven days: what turned Red, what recovered, what is overdue, blind spots, the five advisories to fix first, and which PRs wait for review.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $since = now()->subWeek();
        $changes = StatusChange::with('service:id,name,environment')->where('created_at', '>=', $since)->get();
        $label = fn (?Service $service) => $service ? "{$service->name} ({$service->environment})" : 'a deleted service';
        $names = fn (ServiceStatus $status) => $changes->where('status', $status)->map(fn (StatusChange $change) => $label($change->service))->unique()->values();

        $turnedRed = $names(ServiceStatus::Red);
        $recovered = $names(ServiceStatus::Green);
        $red = Service::where('status', ServiceStatus::Red)->count();
        // ponytail: presents every service to reuse the dashboard's overdue rule; query advisories directly past a few hundred services.
        $overdue = Service::with(DashboardController::relations())->get()
            ->map(fn (Service $service) => [$label($service), DashboardController::present($service)['overdue']])
            ->filter(fn (array $row) => $row[1] > 0);
        $openPullRequests = Dependency::where('pull_request_state', 'open')->count();

        $message = (new MailMessage)
            ->subject('Patchwatch weekly digest')
            ->line("{$red} ".str('service')->plural($red).($red === 1 ? ' needs' : ' need').' a security patch right now.')
            ->line($turnedRed->isEmpty() ? 'Nothing turned Red this week.' : 'Turned Red this week: '.$turnedRed->join(', ').'.')
            ->line($recovered->isEmpty() ? 'Nothing recovered to Green this week.' : 'Back to Green: '.$recovered->join(', ').'.')
            ->line(self::advisoryChanges($since));

        foreach ($overdue as [$service, $count]) {
            $message->line("{$service}: {$count} overdue ".str('advisory')->plural($count).'.');
        }

        foreach (DashboardController::coverage()['gaps'] as $gap) {
            $message->line("Blind spot: {$gap['service']['name']} ({$gap['service']['environment']}) {$gap['detail']}.");
        }

        foreach (DashboardController::fixFirst(5) as $i => $item) {
            $message->line(($i === 0 ? 'Fix first: ' : '').$item['osv_id']." in {$item['package']} on {$item['service']['name']} (".implode(' · ', $item['reasons']).').');
        }

        return $message
            ->line($openPullRequests === 0 ? 'No upgrade PRs are waiting for review.' : "{$openPullRequests} upgrade ".str('PR')->plural($openPullRequests).' opened by Patchwatch '.($openPullRequests === 1 ? 'is' : 'are').' still open.')
            ->action('Open the dashboard', route('dashboard'));
    }
}
