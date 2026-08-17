<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

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
    }

    protected function api(string $path): string
    {
        return '/api/v1'.(str_starts_with($path, '/') ? $path : '/'.$path);
    }
}
