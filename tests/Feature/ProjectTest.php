<?php

namespace Tests\Feature;

use App\User;
use App\Project;
use Github\Client;
use Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    public function testProjectIndex()
    {
        $user = factory(User::class)->create();
        $response = $this->actingAs($user)->get('/projects');
        $response->assertRedirect('/home');
    }

    public function testProjectCreate()
    {
        Gate::before(function () {
            return true;
        });

        $user = factory(User::class)->create();
        $response = $this->actingAs($user)->get('/projects/create');
        $response->assertRedirect('/projects');
    }

    public function testProjectEdit()
    {
        Gate::before(function () {
            return true;
        });

        $user = factory(User::class)->create();
        $project = factory(Project::class)->create(['user_id' => 1]);
        $response = $this->actingAs($user)->get("/projects/{$project->id}/edit");
        $response->assertRedirect("/projects/{$project->id}");
    }

    public function testProjectView()
    {
        $user = factory(User::class)->create();
        $anotherUser = factory(User::class)->create();
        $project = factory(Project::class)->make();
        $user->projects()->save($project);

        Gate::before(function ($policyUser) use ($project) {
            return $policyUser->id === $project->user_id;
        });

        $response = $this->actingAs($user)
            ->get("/projects/{$project->id}");

        $response->assertSuccessful();
        $response->assertSee($project->forge_site_url);
        $response->assertSee($project->forge_deployment);
        $response->assertSee($project->forge_deployment_initial);

        $response = $this->actingAs($anotherUser)
            ->get("/projects/{$project->id}");

        $response->assertForbidden();
        $response->assertDontSee($project->forge_site_url);
        $response->assertDontSee($project->forge_deployment);
        $response->assertDontSee($project->forge_deployment_initial);
    }

    public function testProjectStore()
    {
        $user = factory(User::class)->create();
        $anotherUser = factory(User::class)->create();

        Gate::before(function ($policyUser) use ($user) {
            return $policyUser->id === $user->id;
        });

        $githubMock = \Mockery::mock(Client::class);
        $githubMock->shouldReceive('authenticate')->twice();
        $githubMock->shouldReceive('api->hooks->create')->twice()->andReturn(['id' => 1337]);
        $this->app->instance(Client::class, $githubMock);

        $response = $this->actingAs($user)->post('/projects/', [
            'forge_site_url' => '*.test.com',
            'forge_server_id' => 1,
            'github_repo' => 'test/repo',
            'webhook_secret' => '1234567890',
            'webhook_id' => 1,
            'forge_deployment' => 'composer require',
            'forge_deployment_initial' => 'php artisan key:generate',
        ]);
        $response->assertRedirect('/home');
        $response = $this->actingAs($user)->get('/home');
        $response->assertSee('*.test.com');
        $response->assertSee('test/repo');

        $project = $user->projects()->first();
        $project->delete();
        $this->assertTrue($project->trashed());
        $response = $this->actingAs($user)->post('/projects/', [
            'forge_site_url' => '*.test.com',
            'forge_server_id' => 1,
            'github_repo' => 'test/repo',
            'webhook_secret' => '1234567890',
            'webhook_id' => 1,
            'forge_deployment' => 'composer require',
            'forge_deployment_initial' => 'php artisan key:generate',
        ]);
        $response->assertRedirect('/home');
        $response = $this->actingAs($user)->get('/home');
        $response->assertSee('*.test.com');
        $response->assertSee('test/repo');
        $newProject = $user->projects()->first();
        $this->assertSame($project->id, $newProject->id);
        $this->assertFalse($newProject->trashed());

        $response = $this->actingAs($anotherUser)->post('/projects/', [
            'forge_site_url' => '*.test.com',
            'forge_server_id' => 1,
            'github_repo' => 'test/repo',
            'webhook_secret' => '1234567890',
            'webhook_id' => 1,
            'forge_deployment' => 'composer require',
            'forge_deployment_initial' => 'php artisan key:generate',
        ]);
        $response->assertForbidden();
        $response = $this->actingAs($anotherUser)->get('/home');
        $response->assertDontSee('*.test.com');
        $response->assertDontSee('test/repo');
    }

    public function testProjectUpdate()
    {
        $user = factory(User::class)->create();
        $anotherUser = factory(User::class)->create();
        $project = factory(Project::class)->make();
        $user->projects()->save($project);

        Gate::before(function ($policyUser) use ($project) {
            return $policyUser->id === $project->user_id;
        });

        $response = $this->actingAs($anotherUser)->put("/projects/{$project->id}", [
            'forge_site_url' => '*.test.com',
            'forge_server_id' => -1,
            'github_repo' => 'test/repo',
            'webhook_secret' => '1234567890',
            'webhook_id' => -1,
            'forge_deployment' => 'composer require',
            'forge_deployment_initial' => 'php artisan key:generate',
        ]);
        $response->assertForbidden();

        $projectLatest = $project->fresh();
        $this->assertSame($project->forge_site_url, $projectLatest->forge_site_url);
        $this->assertSame($project->forge_server_id, $projectLatest->forge_server_id);
        $this->assertSame($project->github_repo, $projectLatest->github_repo);
        $this->assertSame($project->webhook_secret, $projectLatest->webhook_secret);
        $this->assertSame($project->webhook_id, $projectLatest->webhook_id);
        $this->assertSame($project->forge_deployment, $projectLatest->forge_deployment);
        $this->assertSame($project->forge_deployment_initial, $projectLatest->forge_deployment_initial);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", [
            'forge_site_url' => '*.test.com',
            'forge_server_id' => -1,
            'github_repo' => 'test/repo',
            'webhook_secret' => '1234567890',
            'webhook_id' => 12345,
            'forge_deployment' => 'composer require',
            'forge_deployment_initial' => 'php artisan key:generate',
        ]);
        $project = $project->fresh();

        $this->assertSame('*.test.com', $project->forge_site_url);
        $this->assertNotSame(-1, $project->forge_server_id);
        $this->assertNotSame('test/repo', $project->github_repo);
        $this->assertNotSame('1234567890', $project->webhook_secret);
        $this->assertSame(12345, $project->webhook_id);
        $this->assertSame('composer require', $project->forge_deployment);
        $this->assertSame('php artisan key:generate', $project->forge_deployment_initial);
    }

    public function testProjectDelete()
    {
        $user = factory(User::class)->create();
        $anotherUser = factory(User::class)->create();
        /** @var Project $project */
        $project = factory(Project::class)->make();
        $user->projects()->save($project);

        Gate::before(function ($policyUser) use ($user) {
            return $policyUser->id === $user->id;
        });

        $githubMock = \Mockery::mock(Client::class);
        $githubMock->shouldReceive('authenticate')->once();
        $githubMock->shouldReceive('api->hooks->remove')->once();
        $this->app->instance(Client::class, $githubMock);

        $response = $this->actingAs($anotherUser)->delete("/projects/{$project->id}");
        $response->assertForbidden();
        $this->assertNotNull($project->fresh());

        $response = $this->actingAs($user)->delete("/projects/{$project->id}");
        $response->assertRedirect('/projects');
        $this->assertTrue($project->fresh()->trashed());
    }
}
