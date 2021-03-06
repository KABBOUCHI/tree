<?php

namespace App\Http\Controllers;

use Exception;
use Github\Client;
use App\Services\Forge;
use Github\ResultPager;

class HomeController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth')->except('welcome');
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Forge $forge)
    {
        try {
            $forge = $forge->setApiKey(auth()->user()->forge_token);
            $servers = $forge->servers();
        } catch (Exception $exception) {
            $servers = [];
            $forgeException = 'Your Forge API Token is invalid.';
        }

        try {
            $github = new Client();
            $github->authenticate(auth()->user()->github_token, null, Client::AUTH_HTTP_PASSWORD);

            $paginator = new ResultPager($github);
            $repositories = $paginator->fetchAll($github->api('me'), 'repositories', ['all']);
        } catch (Exception $exception) {
            $repositories = [];
            $githubException = 'Your Github API Token is invalid.';
        }

        $repositories = collect($repositories)->where('permissions.admin', true);

        return view('home')->with(compact('servers', 'repositories', 'forgeException', 'githubException'));
    }
}
