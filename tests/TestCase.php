<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function api(string $path): string
    {
        return '/api/v1'.(str_starts_with($path, '/') ? $path : '/'.$path);
    }
}
