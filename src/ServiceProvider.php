<?php

namespace adz\core;

use adz\core\Providers\EventServiceProvider;
use GraphQL;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as AuthServiceProvider;

define('ADZ_CORE_SRC', __DIR__);

class ServiceProvider extends AuthServiceProvider
{

    //  protected $defer = true ;

    protected $policies = [
    ];
    protected $listen = [

    ];

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadGraphQLSchemas();
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->register(EventServiceProvider::class);
        $loader = AliasLoader::getInstance();
        $loader->alias('Baianat', BaianatFacade::class);
        $this->app->singleton('Baianat', function () {
            return new Baianat();
        });
        // $this->app->make('Baianat\Common\CommonController');
        if ($this->app->runningInConsole()) {
            $this->registerConsoleCommands();
            $this->registerResources();
        }
        $this->loadHelpers();
    }

    private function registerConsoleCommands()
    {
        $this->commands(Commands\GenerateCommand::class);
    }

    private function loadHelpers()
    {
        foreach (glob(__DIR__ . '/Helpers/*.php') as $filename) {
            require_once $filename;
        }
    }

    private function registerResources()
    {
        $publishablePath = dirname(__DIR__) . '/publishable';

        $publishable = [
            'seeds' => [
                "{$publishablePath}/database/seeds/" => database_path('seeds'),
            ],
            'config' => [
                "{$publishablePath}/config/baianat.php" => config_path('baianat.php'),
            ],
        ];

        foreach ($publishable as $group => $paths) {
            $this->publishes($paths, $group);
        }
    }

    public function registerGates()
    {
        $this->registerPolicies();
    }

    protected function loadGraphQLSchemas()
    {

        if ($this->app->runningInConsole()) {
            return;
        }

        $exceptions = ['ResponseQueryType','ResponseType'];

        $queries = glob(app_path('GraphQL' . '/Query/*.php'));
        $types = glob(app_path('GraphQL' . '/Type/*.php'));
        $enums = glob(app_path('GraphQL' . '/Enum/*.php'));
        $mutations = glob(app_path('GraphQL' . '/Mutation/*.php'));
        if (!empty($types)) {
            foreach ($types as $type) {
                $_type = basename($type, ".php");
                if(!in_array( $_type, $exceptions)){ 
                  GraphQL::addType("\App\GraphQL\Type\\{$_type}", $_type);
                }
            }
        }  
        if (!empty($enums)) {
            foreach ($enums as $enum) {
                $_enum = basename($enum, ".php");
                GraphQL::addType("\App\GraphQL\Enums\\{$_enum}", $_enum);
            }
        }
        if (!empty($queries)) {
            $queriesList = [];
            foreach ($queries as $type) {
                $_type = basename($type, ".php");
                $queriesList[$_type] = "\App\GraphQL\Query\\{$_type}";
            }
            $mutationsList = [];
            foreach ($mutations as $type) {
                $_type = basename($type, ".php");
                $mutationsList[$_type] = "\App\GraphQL\Mutation\\{$_type}";
            }
 
            GraphQL::addSchema("default", [
                'query' => $queriesList,
                'mutation' => $mutationsList,
            ]);
        }

    }

}
