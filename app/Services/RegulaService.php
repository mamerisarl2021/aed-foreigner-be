<?php

namespace App\Services;

interface RegulaService
{
    public function analyzeIdentity(array $files, array $data): array;
}
