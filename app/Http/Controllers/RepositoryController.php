<?php

namespace App\Http\Controllers;

use App\Jobs\ScanRepository;
use App\Models\AuditEvent;
use App\Models\Service;
use App\Upstream\GitHubApp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RepositoryController extends Controller
{
    /**
     * The repositories patchwatch-bot is installed on, and which of them a service watches.
     */
    public function index(GitHubApp $app): Response
    {
        $slug = $app->slug();

        return Inertia::render('repositories/Index', [
            'app' => [
                'enabled' => $app->enabled(),
                'install_url' => $slug === null ? null : "https://github.com/apps/{$slug}/installations/new",
            ],
            'repositories' => Inertia::defer(fn () => $app->enabled() ? $this->listed($app) : []),
        ]);
    }

    /**
     * Watch a repository: a service fed by scanning it on every push to the default branch, with
     * patchwatch-bot reviewing its PRs. The first scan is queued straight away.
     */
    public function store(Request $request, GitHubApp $app): RedirectResponse
    {
        $validated = $request->validate(['repository' => ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/']]);
        $repository = collect($app->repositories())->firstWhere('full_name', $validated['repository'])
            ?? throw ValidationException::withMessages(['repository' => 'patchwatch-bot isn\'t installed on that repository.']);

        $service = Service::firstOrCreate([
            'name' => ltrim(Str::after($repository['full_name'], '/'), '._-') ?: 'repository',
            'environment' => trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $repository['default_branch']), '._-') ?: 'main',
        ]);

        // Services are named after the repository alone, so acme/api and other/api would share one and the second would take it over.
        if ($service->repository !== null && strcasecmp($service->repository, $repository['full_name']) !== 0) {
            throw ValidationException::withMessages(['repository' => "{$service->name} ({$service->environment}) already follows {$service->repository}. Rename that service first."]);
        }

        $service->update(['repository' => $repository['full_name'], 'watch_repository' => true]);

        AuditEvent::record('service.repository_watched', $service, ['repository' => $repository['full_name']]);
        ScanRepository::dispatch($service);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Watching {$repository['full_name']}. The first scan is on its way."]);

        return to_route('services.show', $service);
    }

    /**
     * @return list<array{full_name: string, private: bool, html_url: string, service: array{id: int, name: string, environment: string}|null}>
     */
    private function listed(GitHubApp $app): array
    {
        $watched = Service::where('watch_repository', true)->get(['id', 'name', 'environment', 'repository'])->keyBy(fn (Service $service) => strtolower((string) $service->repository));

        return array_map(fn (array $repository) => [
            'full_name' => $repository['full_name'],
            'private' => $repository['private'],
            'html_url' => $repository['html_url'],
            'service' => $watched->get(strtolower($repository['full_name']))?->only(['id', 'name', 'environment']),
        ], $app->repositories());
    }
}
