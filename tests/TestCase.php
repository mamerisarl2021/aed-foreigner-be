<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        try {
            parent::setUp();
        } catch (BindingResolutionException) {
            // rdkafka producers torn down after a previous test can leave the
            // container unable to resolve `config` for the next setUp.
            $this->app = null;
            parent::setUp();
        }

        // Le store `array` vit le temps du processus : sans ce vidage, les compteurs de
        // throttle d'un test épuisent le quota du suivant (§11.1, pas d'état partagé).
        Cache::store()->flush();
    }

    protected function api(string $path): string
    {
        return '/api/v1'.(str_starts_with($path, '/') ? $path : '/'.$path);
    }
}
