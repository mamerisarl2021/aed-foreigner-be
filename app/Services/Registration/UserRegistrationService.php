<?php

declare(strict_types=1);

namespace App\Services\Registration;

use App\Enums\ActivityLogAction;
use App\Jobs\SendOTPJob;
use App\Jobs\UploadUserProfileImageJob;
use App\Models\OTP;
use App\Models\User;
use App\Services\ActivityLog\ActivityLogService;
use App\Services\ANIP\AnipSimulatorService;
use App\Services\PKI\TrustedXClientService;
use App\Services\ServiceResult;
use App\Support\ClientLocalCredentials;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserRegistrationService
{
    public function __construct(
        private readonly TrustedXClientService $trustedXClient,
        private readonly AnipSimulatorService $anipSimulator,
        private readonly ActivityLogService $activityLog,
    ) {}

    private const OTP_MAX_ATTEMPTS = 5;

    public function sendOtp(string $npi): ServiceResult
    {
        $otp = (string) random_int(100000, 999999);
        $validUntil = Carbon::now()->addMinutes(5);

        $anipData = $this->anipSimulator->getUserData($npi);
        if (! ($anipData['status'] ?? false)) {
            Log::error('NPI inexistant');

            return ServiceResult::fail(
                'Le numéro personnel d\'identification renseigné n\'existe pas dans la base de donnée de l\'ANIP vérifiez bien qu\'il s\'agit du bon numéro et reéssayez.',
                null,
                404
            );
        }

        OTP::updateOrCreate(
            ['npi' => $npi],
            ['otp' => hash('sha256', $otp), 'valid_until' => $validUntil]
        );
        Cache::forget('user_'.$npi.'_otp_attempts');

        SendOTPJob::dispatch($anipData['data']['email'], $otp);
        Cache::put('user_'.$npi, ['data' => $anipData], 600);

        $this->activityLog->record(
            ActivityLogAction::OtpEnvoye,
            sprintf('OTP client envoyé par e-mail (NPI %s).', $npi),
            null,
            null,
            ['npi' => $npi, 'context' => 'client_registration', 'channel' => 'email'],
        );

        return ServiceResult::ok('Un code OTP vous a été envoyé par e-mail. Il expire dans 5 minutes.');
    }

    public function verifyOtp(string $npi, string $otp): ServiceResult
    {
        try {
            $attemptsKey = 'user_'.$npi.'_otp_attempts';
            if ((int) Cache::get($attemptsKey, 0) >= self::OTP_MAX_ATTEMPTS) {
                return ServiceResult::fail('Trop de tentatives. Demandez un nouveau code OTP.', null, 429);
            }

            $existingOTP = OTP::where('npi', $npi)
                ->where('valid_until', '>=', Carbon::now())
                ->first();

            if (! $existingOTP || ! hash_equals((string) $existingOTP->otp, hash('sha256', $otp))) {
                Cache::add($attemptsKey, 0, 300);
                Cache::increment($attemptsKey);

                return ServiceResult::fail('OTP invalide ou expiré.', null, 400);
            }

            $cachedData = Cache::get('user_'.$npi);
            if (! $cachedData) {
                return ServiceResult::fail("Le code OTP n'est plus valide veuillez réessayer", null, 404);
            }

            Cache::forget($attemptsKey);
            $ttlSeconds = (int) Carbon::now()->diffInSeconds(Carbon::parse($existingOTP->valid_until));
            Cache::put('user_'.$npi.'_validate_otp', true, $ttlSeconds > 0 ? $ttlSeconds : 300);

            $this->activityLog->record(
                ActivityLogAction::OtpVerifie,
                sprintf('OTP client vérifié (NPI %s).', $npi),
                null,
                null,
                ['npi' => $npi, 'context' => 'client_registration', 'channel' => 'email'],
            );

            return ServiceResult::ok('OTP valide.', $cachedData['data']);
        } catch (Exception $e) {
            Log::error('OTP verification failed: '.$e->getMessage());

            return ServiceResult::fail('Erreur lors de la vérification du code OTP.', null, 500);
        }
    }

    public function login(string $code, ?string $redirectUri = null): ServiceResult
    {
        $response = $this->trustedXClient->userInfo($code, $redirectUri);

        if ($response['status']) {
            $actorId = $this->touchClientLogin($response['data']['user'] ?? null);

            $this->activityLog->record(
                ActivityLogAction::ConnexionClient,
                'Connexion client TrustedX réussie.',
                $actorId,
                null,
                ['channel' => 'web'],
            );

            return ServiceResult::ok('Token obtenu avec succès!', $response['data']);
        }

        return ServiceResult::fail($response['message'], null, 401);
    }

    public function loginMobile(string $code): ServiceResult
    {
        $response = $this->trustedXClient->mobileUserInfo($code);

        if ($response['status']) {
            $actorId = $this->touchClientLogin($response['data']['user'] ?? null);

            $this->activityLog->record(
                ActivityLogAction::ConnexionClient,
                'Connexion client mobile TrustedX réussie.',
                $actorId,
                null,
                ['channel' => 'mobile'],
            );

            return ServiceResult::ok('Token obtenu avec succès!', $response['data']);
        }

        return ServiceResult::fail($response['message'], null, 401);
    }

    public function setPassword(string $npi, string $password, string $type, ?User $actor = null): ServiceResult
    {
        $user = $this->trustedXClient->getUserWithNPI($npi);
        if (! $user['status']) {
            return ServiceResult::fail($user['message'], null, 400);
        }

        $output = $this->trustedXClient->setDefaultPassword(
            ['id' => $user['data']['id'], 'password' => $password],
            $type
        );

        if (! $output['status']) {
            return ServiceResult::fail($output['message'], null, 400);
        }

        $localUser = User::where('npi', $npi)->first();
        if ($localUser) {
            ClientLocalCredentials::apply($localUser, $type, $password);
            $localUser->save();
        }

        $typeLabel = $type === 'password' ? 'mot de passe' : 'pin';
        $this->activityLog->record(
            ActivityLogAction::MotDePasseChange,
            sprintf(
                '%s a défini le %s client (NPI).',
                ActivityLogService::actorLabel($actor),
                $typeLabel
            ),
            is_string($actor?->id) ? $actor->id : null,
            null,
            ['npi' => $npi, 'type' => $type, 'context' => 'admin_set_password'],
        );

        return ServiceResult::ok(
            'Votre mot de passe a bien été mis à jour.',
            [...$user['data'], ...$output['data']]
        );
    }

    /**
     * @param  array<string, mixed>  $updateData
     */
    public function updateUser(string $id, array $updateData, ?UploadedFile $profile): ServiceResult
    {
        $user = User::findOrFail($id);
        $localProfilePath = null;
        $cloudPath = null;

        try {
            DB::beginTransaction();

            if ($profile !== null) {
                $stored = $profile->store('tmp/profiles', 'local');
                if (! is_string($stored) || $stored === '') {
                    DB::rollBack();

                    return ServiceResult::fail("Échec du téléchargement de l'image.", null, 500);
                }
                $localProfilePath = $stored;
                $cloudPath = 'images/'.basename($stored);
                $updateData['profile'] = $cloudPath;
            }

            $user->update($updateData);
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Mise à jour de l'utilisateur échouée : ".$e->getMessage());

            return ServiceResult::fail('Une erreur est survenue lors de la mise à jour de vos informations.', null, 500);
        }

        if ($localProfilePath !== null && $cloudPath !== null) {
            UploadUserProfileImageJob::dispatch($user->id, $localProfilePath, $cloudPath)
                ->afterCommit();
        }

        $this->activityLog->record(
            ActivityLogAction::UtilisateurModifie,
            sprintf(
                '%s a mis à jour son profil.',
                ActivityLogService::actorLabel($user)
            ),
            $user->id,
            null,
            ['context' => 'profile_update', 'fields' => array_keys($updateData)],
        );

        return ServiceResult::ok('Vos informations ont bien été mises à jour!', $user->load('identities'));
    }

    private function touchClientLogin(mixed $actor): ?string
    {
        if (! $actor instanceof User) {
            return null;
        }

        $actor->last_login_at = now();
        $actor->save();

        return $actor->id;
    }
}
