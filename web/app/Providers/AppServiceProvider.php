<?php

namespace App\Providers;

use App\Contracts\MaterialClassifier;
use App\Services\Recycling\ClassifierFactory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(MaterialClassifier::class, fn () => ClassifierFactory::make());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
