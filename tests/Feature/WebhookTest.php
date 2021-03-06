<?php

namespace Tests\Feature;

use App\Branch;
use App\Project;
use Tests\TestCase;
use App\Jobs\SetupSql;
use App\Jobs\SetupSite;
use App\Jobs\DeploySite;
use App\Jobs\RemoveSite;
use Illuminate\Support\Facades\Bus;
use App\Jobs\RemoveInitialDeployment;
use Illuminate\Foundation\Testing\RefreshDatabase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    public function pr()
    {
        return [
            'pull_request' => [

            ],
            'repository' => [
                'full_name' => 'example/repository',
            ],
            'action' => 'none',
        ];
    }

    public function headers($content = '', $secret = '')
    {
        $hash = hash_hmac('sha1', $content, $secret);

        return [
            'X-Hub-Signature' => 'sha1=' . $hash,
        ];
    }

    public function testNoPullRequest()
    {
        $pr = $this->pr();
        unset($pr['pull_request']);

        $response = $this->post('/api/webhooks/github/pullrequest', $pr, $this->headers());

        $response->assertForbidden();
    }

    public function testNoRepository()
    {
        $pr = $this->pr();

        unset($pr['repository']);

        $response = $this->post('/api/webhooks/github/pullrequest', $pr, $this->headers());

        $response->assertForbidden();
    }

    public function testNoSignature()
    {
        $response = $this->post('/api/webhooks/github/pullrequest', $this->pr(), []);

        $response->assertForbidden();
    }

    public function testSignatureVerification()
    {
        $project = factory(Project::class)->create(['user_id' => 1]);
        $branch = factory(Branch::class)->make();
        $project->branches()->save($branch);

        $pr = $this->pr();
        $pr['repository']['full_name'] = $project->github_repo;
        $pr['pull_request']['number'] = $branch->issue_number;

        $headers = $this->headers(json_encode($pr), $project->webhook_secret);

        $response = $this->json('POST', '/api/webhooks/github/pullrequest', $pr, $headers);
        $response->assertSuccessful();

        $headers = $this->headers(json_encode($pr), 'not a working secret');
        $response = $this->json('POST', '/api/webhooks/github/pullrequest', $pr, $headers);
        $response->assertForbidden();
    }

    public function testNoProjectFound()
    {
        $project = factory(Project::class)->create(['user_id' => 1]);

        $pr = $this->pr();
        $pr['repository']['full_name'] = 'doesnotexist';
        $pr['pull_request']['number'] = 1;

        $headers = $this->headers(json_encode($pr), $project->webhook_secret);

        $response = $this->json('POST', '/api/webhooks/github/pullrequest', $pr, $headers);

        $response->assertForbidden();
    }

    public function testNoBranchFound()
    {
        Bus::fake();

        $project = factory(Project::class)->create(['user_id' => 1]);
        $branch = factory(Branch::class)->make();
        $project->branches()->save($branch);

        $pr = $this->pr();
        $pr['repository']['full_name'] = $project->github_repo;
        $pr['pull_request']['number'] = -1;

        $pr['action'] = 'closed';
        $headers = $this->headers(json_encode($pr), $project->webhook_secret);
        $response = $this->json('POST', '/api/webhooks/github/pullrequest', $pr, $headers);
        $response->assertStatus(400);
        Bus::assertNotDispatched(RemoveSite::class);

        $pr['action'] = 'synchronize';
        $headers = $this->headers(json_encode($pr), $project->webhook_secret);
        $response = $this->json('POST', '/api/webhooks/github/pullrequest', $pr, $headers);
        $response->assertStatus(400);
        Bus::assertNotDispatched(DeploySite::class);
    }

    public function testOpenedOrReopened()
    {
        Bus::fake();

        $project = factory(Project::class)->create(['user_id' => 1]);
        $branch = factory(Branch::class)->make();
        $project->branches()->save($branch);

        $pr = $this->pr();
        $pr['repository']['full_name'] = $project->github_repo;
        $pr['pull_request']['number'] = $branch->issue_number;

        $pr['action'] = 'opened';
        $headers = $this->headers(json_encode($pr), $project->webhook_secret);
        $response = $this->json('POST', '/api/webhooks/github/pullrequest', $pr, $headers);
        $response->assertSuccessful();
        Bus::assertDispatched(SetupSite::class);

        $pr['action'] = 'reopened';
        $headers = $this->headers(json_encode($pr), $project->webhook_secret);
        $response = $this->json('POST', '/api/webhooks/github/pullrequest', $pr, $headers);
        $response->assertSuccessful();
        Bus::assertDispatched(SetupSite::class);
    }

    public function testClosed()
    {
        Bus::fake();

        $project = factory(Project::class)->create(['user_id' => 1]);
        $branch = factory(Branch::class)->make();
        $project->branches()->save($branch);

        $pr = $this->pr();
        $pr['repository']['full_name'] = $project->github_repo;
        $pr['pull_request']['number'] = $branch->issue_number;

        $pr['action'] = 'closed';
        $headers = $this->headers(json_encode($pr), $project->webhook_secret);
        $response = $this->json('POST', '/api/webhooks/github/pullrequest', $pr, $headers);
        $response->assertSuccessful();
        Bus::assertDispatched(RemoveSite::class);
    }

    public function testSynchronize()
    {
        Bus::fake();

        $project = factory(Project::class)->create(['user_id' => 1]);
        $branch = factory(Branch::class)->make();
        $project->branches()->save($branch);

        $pr = $this->pr();
        $pr['repository']['full_name'] = $project->github_repo;
        $pr['pull_request']['number'] = $branch->issue_number;

        $pr['action'] = 'synchronize';
        $headers = $this->headers(json_encode($pr), $project->webhook_secret);
        $response = $this->json('POST', '/api/webhooks/github/pullrequest', $pr, $headers);
        $response->assertSuccessful();
        Bus::assertDispatched(DeploySite::class);
    }

    public function testOther()
    {
        Bus::fake();

        $project = factory(Project::class)->create(['user_id' => 1]);
        $branch = factory(Branch::class)->make();
        $project->branches()->save($branch);

        $pr = $this->pr();
        $pr['repository']['full_name'] = $project->github_repo;
        $pr['pull_request']['number'] = $branch->issue_number;

        $pr['action'] = 'notarealaction';
        $headers = $this->headers(json_encode($pr), $project->webhook_secret);
        $response = $this->json('POST', '/api/webhooks/github/pullrequest', $pr, $headers);
        $response->assertSuccessful();
        Bus::assertNotDispatched(SetupSite::class);
        Bus::assertNotDispatched(RemoveSite::class);
        Bus::assertNotDispatched(DeploySite::class);
        Bus::assertNotDispatched(SetupSql::class);
        Bus::assertNotDispatched(RemoveInitialDeployment::class);
    }
}
