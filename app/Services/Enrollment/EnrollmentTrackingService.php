<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Models\EnrollmentRequest;
use App\Models\User;
use App\Services\ServiceResult;

final class EnrollmentTrackingService
{
    public function show(string $numeroSuivi, string $email): ServiceResult
    {
        $code = strtoupper(trim($numeroSuivi));
        $enrollment = EnrollmentRequest::query()
            ->with(['submittedBy', 'enrolledCompany'])
            ->where('tracking_code', $code)
            ->first();

        if (! $this->emailMatches($enrollment, $email)) {
            return ServiceResult::fail('Demande introuvable.', null, 404);
        }

        return ServiceResult::ok('Suivi de la demande.', $enrollment);
    }

    private function emailMatches(?EnrollmentRequest $enrollment, string $email): bool
    {
        $needleHash = hash('sha256', $this->normalizeEmail($email));
        $rowEmail = $enrollment instanceof EnrollmentRequest ? (string) $enrollment->email : '';
        $submitterEmail = '';
        if ($enrollment instanceof EnrollmentRequest) {
            $submitter = $enrollment->submittedBy;
            if ($submitter instanceof User) {
                $submitterEmail = (string) $submitter->email;
            }
        }

        $rowOk = hash_equals(
            hash('sha256', $this->normalizeEmail($rowEmail)),
            $needleHash,
        );
        $submitterOk = hash_equals(
            hash('sha256', $this->normalizeEmail($submitterEmail)),
            $needleHash,
        );

        if (! $enrollment instanceof EnrollmentRequest) {
            return false;
        }

        if ($rowOk && $this->normalizeEmail((string) $enrollment->email) !== '') {
            return true;
        }

        return $enrollment->isPersonneMorale()
            && $enrollment->submittedBy !== null
            && $submitterOk;
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}
