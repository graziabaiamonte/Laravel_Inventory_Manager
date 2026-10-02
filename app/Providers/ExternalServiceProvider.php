<?php

namespace App\Providers;

use App\Contracts\ExternalStoreServiceInterface;
use App\Services\External\DiscogsClient;
use Illuminate\Support\ServiceProvider;

class ExternalServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->bind(ExternalStoreServiceInterface::class, DiscogsClient::class);
    }
}
