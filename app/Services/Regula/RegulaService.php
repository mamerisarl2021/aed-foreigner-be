<?php

declare(strict_types=1);

namespace App\Services\Regula;

interface RegulaService
{
    /**
     * Full identity analysis: Document Reader + Face match (+ liveness when a transaction id is given).
     *
     * @param  array<string, mixed>  $files  Local paths keyed by document slot (selfie, recto, verso)
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function analyzeIdentity(array $files, array $data): array;

    /**
     * Document-only analysis: Document Reader alone — no selfie, no face match, no liveness.
     * Personne morale step 2 reads the demandeur's identity document without a liveness session.
     *
     * @param  array<string, mixed>  $files  Local paths keyed by document slot (recto, verso)
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function analyzeDocument(array $files, array $data): array;
}
