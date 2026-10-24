<?php

namespace App\Http\Controllers\GitHub;

use App\Http\Controllers\Controller;
use App\Jobs\ReviewPullRequest;
use App\Jobs\ScanRepository;
use App\Models\Service;
use App\Upstream\GitHubApp;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WebhookController extends Controller
{
    /**
     * Deliveries from patchwatch-bot, signed with its webhook secret. A push to the default branch
     * rescans the services that watch the repository; an opened or updated PR gets a dependency review.
     * Repositories no service links to are ignored.
     */
    public function __invoke(Request $request, GitHubApp $app): Response
    {
        abort_unless($app->verify($request->getContent(), $request->header('X-Hub-Signature-256')), 401);

        $services = Service::whereRaw('lower(repository) = ?', [strtolower((string) $request->input('repository.full_name'))])->get();

        if ($services->isEmpty()) {
            return response()->noContent();
        }

        $repository = (string) $services->first()->repository;

        match ($request->header('X-GitHub-Event')) {
            'push' => $request->input('ref') === 'refs/heads/'.$request->input('repository.default_branch') && ! $request->boolean('deleted')
                ? $services->where('watch_repository', true)->each(fn (Service $service) => ScanRepository::dispatch($service, (string) $request->input('after')))
                : null,
            'pull_request' => in_array($request->input('action'), ['opened', 'synchronize', 'reopened'], true)
                ? ReviewPullRequest::dispatch($repository, $request->integer('number'), (string) $request->input('pull_request.head.sha'), (string) $request->input('pull_request.base.sha'))
                : null,
            default => null,
        };

        return response()->noContent(202);
    }
}
