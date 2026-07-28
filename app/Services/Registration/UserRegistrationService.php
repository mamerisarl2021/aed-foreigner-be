<?php

declare(strict_types=1);

namespace App\Services\Registration;

use App\Jobs\SendInitLinkJob;
use App\Jobs\SendOTPJob;
use App\Models\OTP;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Services\ANIP\AnipSimulatorService;
use App\Services\PKI\TrustedXClientService;
use App\Services\ServiceResult;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UserRegistrationService
{
    public function __construct(
        private readonly TrustedXClientService $trustedXClient,
        private readonly AnipSimulatorService $anipSimulator,
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
            $ttlSeconds = Carbon::now()->diffInSeconds(Carbon::parse($existingOTP->valid_until));
            Cache::put('user_'.$npi.'_validate_otp', true, $ttlSeconds > 0 ? $ttlSeconds : 300);

            return ServiceResult::ok('OTP valide.', $cachedData['data']);
        } catch (Exception $e) {
            Log::error('OTP verification failed: '.$e->getMessage());

            return ServiceResult::fail('Erreur lors de la vérification du code OTP.', null, 500);
        }
    }

    public function login(string $code): ServiceResult
    {
        $response = $this->trustedXClient->userInfo($code);

        return $response['status']
            ? ServiceResult::ok('Token obtenu avec succès!', $response['data'])
            : ServiceResult::fail($response['message'], null, 401);
    }

    public function loginMobile(string $code): ServiceResult
    {
        $response = $this->trustedXClient->mobileUserInfo($code);

        return $response['status']
            ? ServiceResult::ok('Token obtenu avec succès!', $response['data'])
            : ServiceResult::fail($response['message'], null, 401);
    }

    public function setPassword(string $npi, string $password, string $type): ServiceResult
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

        return ServiceResult::ok(
            'Votre mot de passe a bien été mis à jour.',
            [...$user['data'], ...$output['data']]
        );
    }

    public function sendResetLink(string $npi, string $type): ServiceResult
    {
        $token = Str::random(60);

        PasswordResetToken::updateOrCreate(
            ['npi' => $npi, 'type' => $type],
            ['token' => hash('sha256', $token), 'created_at' => Carbon::now(), 'type' => $type]
        );

        $user = $this->trustedXClient->getUserWithNPI($npi);
        if (! $user['status']) {
            return ServiceResult::fail($user['message'], null, 400);
        }

        $link = config('app.frontend_url')."/reset/{$type}/$token/$npi";
        $typeLabel = $type === 'password' ? 'mot de passe' : 'pin';
        $email = User::whereNpi($npi)->first()->email;

        SendInitLinkJob::dispatch($email, $link, $typeLabel);

        return ServiceResult::ok(
            "Un lien vous a été envoyé par MAIL consultez le pour mettre à jour votre $typeLabel.",
            []
        );
    }

    /**
     * @param  array<string, mixed>  $updateData
     */
    public function updateUser(string $id, array $updateData, ?UploadedFile $profile): ServiceResult
    {
        DB::beginTransaction();
        try {
            $user = User::findOrFail($id);

            if ($profile) {
                $profilePath = Storage::cloud()->put('images', $profile);
                if (! $profilePath) {
                    DB::rollBack();

                    return ServiceResult::fail("Échec du téléchargement de l'image.", null, 500);
                }
                $updateData['profile'] = $profilePath;
            }

            $user->update($updateData);
            DB::commit();

            return ServiceResult::ok('Vos informations ont bien été mises à jour!', $user->load('identities'));
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Mise à jour de l'utilisateur échouée : ".$e->getMessage());

            return ServiceResult::fail('Une erreur est survenue lors de la mise à jour de vos informations.', null, 500);
        }
    }
}
