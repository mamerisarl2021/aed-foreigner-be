<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Support\ValidatedUpload;
use Illuminate\Http\UploadedFile;

/**
 * Validated payload for POST /enrolements/etrangers.
 */
final readonly class PhysiqueEnrollmentSubmission
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            email: strtolower(trim((string) $validated['email'])),
            phonenumber: (string) $validated['phonenumber'],
            name: (string) $validated['name'],
            firstName: (string) $validated['first_name'],
            sexe: (string) $validated['sexe'],
            dateOfBirth: (string) $validated['date_of_birth'],
            placeOfBirth: (string) $validated['place_of_birth'],
            nationality: (string) $validated['nationality'],
            countryOfResidence: (string) $validated['country_of_residence'],
            address: (string) $validated['address'],
            documentType: (string) $validated['document_type'],
            documentNumber: (string) $validated['document_number'],
            selfie: ValidatedUpload::file($validated, 'selfie'),
            recto: ValidatedUpload::file($validated, 'recto'),
            verso: ValidatedUpload::file($validated, 'verso'),
            profile: ValidatedUpload::file($validated, 'profile'),
            liveness: isset($validated['liveness']) ? (string) $validated['liveness'] : null,
            similarity: isset($validated['similarity']) ? (string) $validated['similarity'] : null,
            captureLe: isset($validated['capture_le']) ? (string) $validated['capture_le'] : null,
        );
    }

    public function __construct(
        public string $email,
        public string $phonenumber,
        public string $name,
        public string $firstName,
        public string $sexe,
        public string $dateOfBirth,
        public string $placeOfBirth,
        public string $nationality,
        public string $countryOfResidence,
        public string $address,
        public string $documentType,
        public string $documentNumber,
        public ?UploadedFile $selfie,
        public ?UploadedFile $recto,
        public ?UploadedFile $verso,
        public ?UploadedFile $profile,
        public ?string $liveness,
        public ?string $similarity,
        public ?string $captureLe,
    ) {}
}
