<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Support\ValidatedUpload;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Validated payload for POST /kyc/document/read.
 */
final readonly class DocumentReadInput
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $recto = ValidatedUpload::file($validated, 'recto');
        if (! $recto instanceof UploadedFile) {
            throw new InvalidArgumentException('Validated payload missing recto.');
        }

        return new self(
            recto: $recto,
            verso: ValidatedUpload::file($validated, 'verso'),
        );
    }

    public function __construct(
        public UploadedFile $recto,
        public ?UploadedFile $verso,
    ) {}
}
