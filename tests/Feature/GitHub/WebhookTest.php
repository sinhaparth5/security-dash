<?php

use App\Jobs\ReviewPullRequest;
use App\Jobs\ScanRepository;
use App\Models\Service;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    fakeGithubApp('whsec-test');
});

/**
 * @param  array<string, mixed>  $payload
 */
function deliver(string $event, array $payload, string $secret = 'whsec-test')
{
    $body = (string) json_encode($payload);

    return test()->call('POST', route('github.webhook'), server: [
        'HTTP_X_GITHUB_EVENT' => $event,
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
        'CONTENT_TYPE' => 'application/json',
    ], content: $body);
}

test('deliveries not signed with the webhook secret are rejected', function () {
    Service::factory()->create(['repository' => 'acme/billing', 'watch_repository' => true]);

    deliver('push', ['ref' => 'refs/heads/main', 'repository' => ['full_name' => 'acme/billing', 'default_branch' => 'main']], 'wrong')->assertUnauthorized();

    Queue::assertNothingPushed();
});

test('a push to the default branch rescans the services that watch the repository', function () {
    $watched = Service::factory()->create(['repository' => 'Acme/Billing', 'watch_repository' => true]);
    Service::factory()->create(['repository' => 'acme/billing', 'watch_repository' => false]);

    deliver('push', ['ref' => 'refs/heads/main', 'after' => 'abc123', 'repository' => ['full_name' => 'acme/billing', 'default_branch' => 'main']])->assertStatus(202);
    deliver('push', ['ref' => 'refs/heads/feature', 'after' => 'def456', 'repository' => ['full_name' => 'acme/billing', 'default_branch' => 'main']])->assertStatus(202);

    Queue::assertPushed(ScanRepository::class, 1);
    Queue::assertPushed(ScanRepository::class, fn (ScanRepository $job) => $job->service->is($watched) && $job->sha === 'abc123');
});

test('an opened or updated pull request gets a dependency review', function () {
    Service::factory()->create(['repository' => 'acme/billing']);
    $pull = ['number' => 42, 'pull_request' => ['head' => ['sha' => 'head1'], 'base' => ['sha' => 'base1']], 'repository' => ['full_name' => 'acme/billing']];

    deliver('pull_request', ['action' => 'synchronize', ...$pull])->assertStatus(202);
    deliver('pull_request', ['action' => 'closed', ...$pull])->assertStatus(202);

    Queue::assertPushed(ReviewPullRequest::class, 1);
    Queue::assertPushed(ReviewPullRequest::class, fn (ReviewPullRequest $job) => [$job->repository, $job->number, $job->head, $job->base] === ['acme/billing', 42, 'head1', 'base1']);
});

test('repositories no service links to are ignored', function () {
    deliver('pull_request', ['action' => 'opened', 'number' => 1, 'repository' => ['full_name' => 'someone/else']])->assertNoContent();

    Queue::assertNothingPushed();
});
