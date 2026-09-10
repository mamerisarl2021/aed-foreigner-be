<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Support\ValidatedUpload;
use Illuminate\Http\UploadedFile;

/**
 * Validated payload for PUT /enrolements/morales/{id}.
 */
final readonly class MoraleEnrollmentCorrection
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            legalName: (string) $validated['legal_name'],
            legalForm: isset($validated['legal_form']) ? (string) $validated['legal_form'] : null,
            countryOfIncorporation: (string) $validated['country_of_incorporation'],
            registrationNumber: (string) $validated['registration_number'],
            incorporationDate: isset($validated['incorporation_date']) ? (string) $validated['incorporation_date'] : null,
            headquartersAddress: (string) $validated['headquarters_address'],
            activitySector: (string) $validated['activity_sector'],
            legalRepresentativeName: (string) $validated['legal_representative_name'],
            legalRepresentativeFirstName: (string) $validated['legal_representative_first_name'],
            isLegalRepresentative: filter_var($validated['is_legal_representative'] ?? false, FILTER_VALIDATE_BOOLEAN),
            tradeRegisterExtract: ValidatedUpload::file($validated, 'trade_register_extract'),
            statutes: ValidatedUpload::file($validated, 'statutes'),
            procuration: ValidatedUpload::file($validated, 'procuration'),
        );
    }

    public function __construct(
        public string $legalName,
        public ?string $legalForm,
        public string $countryOfIncorporation,
        public string $registrationNumber,
        public ?string $incorporationDate,
        public string $headquartersAddress,
        public string $activitySector,
        public string $legalRepresentativeName,
        public string $legalRepresentativeFirstName,
        public bool $isLegalRepresentative,
        public ?UploadedFile $tradeRegisterExtract,
        public ?UploadedFile $statutes,
        public ?UploadedFile $procuration,
    ) {}
}
