<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\User;
use App\Support\ValidatedUpload;
use Illuminate\Http\UploadedFile;

/**
 * Validated payload for POST /kyc/verify.
 */
final readonly class KycVerifyInput
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated, ?User $actor): self
    {
        return new self(
            email: strtolower(trim((string) $validated['email'])),
            phonenumber: (string) $validated['phonenumber'],
            actor: $actor,
            liveness: isset($validated['liveness']) ? (string) $validated['liveness'] : null,
            livenessTransactionId: isset($validated['liveness_transaction_id'])
                ? (string) $validated['liveness_transaction_id']
                : null,
            captureLe: isset($validated['capture_le']) ? (string) $validated['capture_le'] : null,
            selfie: ValidatedUpload::file($validated, 'selfie'),
            recto: ValidatedUpload::file($validated, 'recto'),
            verso: ValidatedUpload::file($validated, 'verso'),
        );
    }

    public function __construct(
        public string $email,
        public string $phonenumber,
        public ?User $actor,
        public ?string $liveness,
        public ?string $livenessTransactionId,
        public ?string $captureLe,
        public ?UploadedFile $selfie,
        public ?UploadedFile $recto,
        public ?UploadedFile $verso,
    ) {}
}
