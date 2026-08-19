<?php

declare(strict_types=1);

namespace App\Services;

final class ServiceResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly mixed $data = null,
        public readonly int $code = 200,
    ) {}

    public static function ok(string $message, mixed $data = null, int $code = 200): self
    {
        return new self(true, $message, $data, $code);
    }

    public static function fail(string $message, mixed $data = null, int $code = 400): self
    {
        return new self(false, $message, $data, $code);
    }
}
