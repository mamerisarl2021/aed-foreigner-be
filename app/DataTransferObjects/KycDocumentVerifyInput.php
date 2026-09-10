<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Support\ValidatedUpload;
use Illuminate\Http\UploadedFile;

/**
 * Validated payload for POST /kyc/document/verify.
 */
final readonly class KycDocumentVerifyInput
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            recto: ValidatedUpload::file($validated, 'recto'),
            verso: ValidatedUpload::file($validated, 'verso'),
        );
    }

    public function __construct(
        public ?UploadedFile $recto,
        public ?UploadedFile $verso,
    ) {}
}
