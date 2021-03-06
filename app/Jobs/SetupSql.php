<?php

namespace App\Jobs;

use App\Branch;
use App\Services\Forge;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SetupSql implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $branch;

    /**
     * Create a new job instance.
     *
     * @param Branch $branch
     */
    public function __construct(Branch $branch)
    {
        $this->branch = $branch;
    }

    /**
     * Execute the job.
     *
     * @param Forge $forge
     * @return void
     */
    public function handle(Forge $forge)
    {
        $branch = $this->branch;
        $project = $branch->project;
        $forge = $forge->setApiKey($project->user->forge_token);

        // MySQL
        $sqlUsername = str_replace('/', '_', $project->github_repo) . '_' . $branch->issue_number;
        $sqlPassword = str_random(20);
        $mysqlDatabase = $forge->createMysqlDatabase($project->forge_server_id, ['name' => $sqlUsername], false);
        $mysqlUser = $forge->createMysqlUser($project->forge_server_id, [
            'name'      => $sqlUsername,
            'password'  => $sqlPassword,
            'databases' => [$mysqlDatabase->id],
        ], false);

        $branch->forge_mysql_database_id = $mysqlDatabase->id;
        $branch->forge_mysql_user_id = $mysqlUser->id;
        $branch->save();

        // Environment
        $environment = $forge->siteEnvironmentFile($project->forge_server_id, $branch->forge_site_id);
        $environment = preg_replace('/^DB_DATABASE=.*$/m', 'DB_DATABASE=' . $sqlUsername, $environment);
        $environment = preg_replace('/^DB_USERNAME=.*$/m', 'DB_USERNAME=' . $sqlUsername, $environment);
        $environment = preg_replace('/^DB_PASSWORD=.*$/m', 'DB_PASSWORD=' . $sqlPassword, $environment);

        if (strlen($environment) > 0) {
            $forge->updateSiteEnvironmentFile($project->forge_server_id, $branch->forge_site_id, $environment);
        }
    }
}
