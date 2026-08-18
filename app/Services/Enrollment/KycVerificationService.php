<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Enums\ActivityLogAction;
use App\Models\User;
use App\Services\ActivityLog\ActivityLogService;
use App\Services\Regula\RegulaService;
use App\Services\ServiceResult;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

final class KycVerificationService
{
    private const VALIDITY_MINUTES = 30;

    public function __construct(
        private readonly RegulaService $regulaService,
        private readonly OtpService $otpService,
        private readonly ActivityLogService $activityLog,
    ) {}

    public function verify(Request $request): ServiceResult
    {
        $client = $this->authenticatedClient($request);

        $email = strtolower(trim((string) $request->input('email')));
        $phone = $this->otpService->normalizePhone((string) $request->input('phonenumber'));

        if ($email === '') {
            return ServiceResult::fail('Email requis pour la vérification KYC.', null, 422);
        }

        if ($phone === '') {
            return ServiceResult::fail('Numéro de téléphone invalide.', null, 422);
        }

        if ($client === null && ! $this->otpService->bothChannelsVerified($email, $phone)) {
            return ServiceResult::fail('Veuillez d\'abord vérifier les OTP email et téléphone.', null, 400);
        }

        $files = array_filter([
            'selfie' => $request->file('selfie')?->getPathname(),
            'recto' => $request->file('recto')?->getPathname(),
            'verso' => $request->file('verso')?->getPathname(),
        ]);

        $analysis = $this->regulaService->analyzeIdentity($files, [
            'email' => $email,
            'liveness' => $request->input('liveness_transaction_id') ?: $request->input('liveness'),
            'liveness_transaction_id' => $request->input('liveness_transaction_id'),
        ]);

        if (($analysis['status'] ?? '') !== 'OK') {
            $this->activityLog->record(
                ActivityLogAction::KycVerifie,
                sprintf('Échec de la vérification KYC pour %s.', $email),
                $client?->id,
                null,
                ['email' => $email, 'phonenumber' => $phone, 'ok' => false],
            );

            return ServiceResult::fail('Échec de la vérification KYC.', $analysis, 422);
        }

        $capturedAt = $this->selfieCapturedAt($request);

        $session = [
            'verified_at' => now()->toIso8601String(),
            'selfie_captured_at' => $capturedAt,
            'liveness' => $analysis['liveness'] ?? null,
            'similarity' => $analysis['similarity'] ?? null,
            'risk_score' => $analysis['risk_score'] ?? null,
            'analysis_details' => $analysis['details'] ?? null,
        ];

        Cache::put(
            $this->sessionKey($client, $email, $phone),
            $session,
            now()->addMinutes(self::VALIDITY_MINUTES)
        );

        $this->activityLog->record(
            ActivityLogAction::KycVerifie,
            sprintf('Vérification KYC réussie pour %s.', $email),
            $client?->id,
            null,
            [
                'email' => $email,
                'phonenumber' => $phone,
                'ok' => true,
                'similarity' => $analysis['similarity'] ?? null,
                'risk_score' => $analysis['risk_score'] ?? null,
            ],
        );

        return ServiceResult::ok('KYC valide.', [
            'kyc_valid' => true,
            'risk_score' => $analysis['risk_score'] ?? null,
            'similarity' => $analysis['similarity'] ?? null,
        ]);
    }

    public function isVerified(string $email, string $phonenumber): bool
    {
        return Cache::has($this->cacheKey(strtolower(trim($email)), $this->otpService->normalizePhone($phonenumber)));
    }

    public function isVerifiedForUser(User $user): bool
    {
        return Cache::has($this->userCacheKey((string) $user->id));
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

    /**
     * @return array<string, mixed>|null
     */
    public function consumeVerificationForUser(User $user): ?array
    {
        $key = $this->userCacheKey((string) $user->id);
        $data = Cache::get($key);
        Cache::forget($key);

        return is_array($data) ? $data : null;
    }

    /**
     * ISO-8601 instant for `analyse_kyc.selfie.capture_le`.
     * Uses the client `capture_le` when valid, otherwise the KYC verification time.
     *
     * @param  array<string, mixed>|null  $session
     */
    public function selfieCapturedAt(Request $request, ?array $session = null): string
    {
        $session ??= [];

        return $this->normalizeCaptureLe($request->input('capture_le'))
            ?? $this->normalizeCaptureLe($session['selfie_captured_at'] ?? null)
            ?? $this->normalizeCaptureLe($session['verified_at'] ?? null)
            ?? now()->toIso8601String();
    }

    public function authenticatedClient(Request $request): ?User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            $plain = $request->bearerToken();
            if (is_string($plain) && $plain !== '') {
                $tokenable = PersonalAccessToken::findToken($plain)?->tokenable;
                $user = $tokenable instanceof User ? $tokenable : null;
            }
        }

        if (! $user instanceof User) {
            return null;
        }

        if (! $user->hasRole(config('roles.client')) || $user->status !== 'ACTIVE') {
            return null;
        }

        return $user;
    }

    private function sessionKey(?User $client, string $email, string $phone): string
    {
        if ($client instanceof User) {
            return $this->userCacheKey((string) $client->id);
        }

        return $this->cacheKey($email, $phone);
    }

    private function cacheKey(string $email, string $phone): string
    {
        return 'enrollment_kyc_verified_'.$email.'_'.$phone;
    }

    private function userCacheKey(string $userId): string
    {
        return 'enrollment_kyc_verified_user_'.$userId;
    }

    private function normalizeCaptureLe(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::parse($value)->toIso8601String();
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }
}
