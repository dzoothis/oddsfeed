<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\File;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->bindRepositories();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Automatically bind all repositories to their interfaces.
     */
    private function bindRepositories(): void
    {
        $repositoryPath = app_path('Repositories');
        $interfacePath = app_path('Interfaces');

        if (!File::exists($repositoryPath) || !File::exists($interfacePath)) {
            return;
        }

        $repositoryFiles = File::files($repositoryPath);
        $interfaceFiles = File::files($interfacePath);

        // Create a map of available interfaces
        $availableInterfaces = [];
        foreach ($interfaceFiles as $file) {
            $interfaceName = $file->getFilenameWithoutExtension();
            $availableInterfaces[$interfaceName] = 'App\\Interfaces\\' . $interfaceName;
        }

        foreach ($repositoryFiles as $file) {
            $repositoryClassName = 'App\\Repositories\\' . $file->getFilenameWithoutExtension();

            if (!class_exists($repositoryClassName)) {
                continue;
            }

            $className = $file->getFilenameWithoutExtension();
            $interfaceName = str_replace('Repository', 'Interface', $className);

            if (!isset($availableInterfaces[$interfaceName])) {
                continue;
            }

            $this->app->bind($availableInterfaces[$interfaceName], $repositoryClassName);
        }
    }
}
