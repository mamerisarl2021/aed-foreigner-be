<?php

declare(strict_types=1);

namespace App\Services\Regula;

interface RegulaService
{
    /**
     * @param  array<string, mixed>  $files  Local paths keyed by document slot (selfie, recto, verso)
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function analyzeIdentity(array $files, array $data): array;
}
