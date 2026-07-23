<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Services\RegulaService;
use App\Services\ServiceResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class KycVerificationService
{
    private const VALIDITY_MINUTES = 30;

    public function __construct(
        private readonly RegulaService $regulaService,
        private readonly OtpService $otpService,
    ) {}

    public function verify(Request $request): ServiceResult
    {
        $email = strtolower(trim((string) $request->input('email')));
        $phone = $this->otpService->normalizePhone((string) $request->input('phonenumber'));

        if ($email === '' || $phone === '') {
            return ServiceResult::fail('Email et téléphone requis pour la vérification KYC.', null, 422);
        }

        if (! $this->otpService->bothChannelsVerified($email, $phone)) {
            return ServiceResult::fail('Veuillez d\'abord vérifier les OTP email et téléphone.', null, 400);
        }

        $files = array_filter([
            'selfie' => $request->file('selfie')?->getPathname(),
            'recto' => $request->file('recto')?->getPathname(),
            'verso' => $request->file('verso')?->getPathname(),
        ]);

        $analysis = $this->regulaService->analyzeIdentity($files, [
            'email' => $email,
            'liveness' => $request->input('liveness'),
            'similarity' => $request->input('similarity'),
        ]);

        if (($analysis['status'] ?? '') !== 'OK') {
            return ServiceResult::fail('Échec de la vérification KYC.', $analysis, 422);
        }

        Cache::put($this->cacheKey($email, $phone), [
            'verified_at' => now()->toIso8601String(),
            'liveness' => $request->input('liveness'),
            'similarity' => $request->input('similarity'),
            'risk_score' => $analysis['risk_score'] ?? null,
            'analysis_details' => $analysis['details'] ?? null,
        ], now()->addMinutes(self::VALIDITY_MINUTES));

        return ServiceResult::ok('KYC valide.', [
            'kyc_valid' => true,
            'risk_score' => $analysis['risk_score'] ?? null,
        ]);
    }

    public function isVerified(string $email, string $phonenumber): bool
    {
        return Cache::has($this->cacheKey(strtolower(trim($email)), $this->otpService->normalizePhone($phonenumber)));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function consumeVerification(string $email, string $phonenumber): ?array
    {
        $key = $this->cacheKey(strtolower(trim($email)), $this->otpService->normalizePhone($phonenumber));
        $data = Cache::get($key);
        Cache::forget($key);

        return is_array($data) ? $data : null;
    }

    private function cacheKey(string $email, string $phone): string
    {
        return 'enrollment_kyc_verified_'.$email.'_'.$phone;
    }
}
