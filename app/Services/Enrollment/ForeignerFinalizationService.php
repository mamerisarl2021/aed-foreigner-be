<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Models\EnrollmentRequest;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Services\PKI\TrustedXClientService;
use App\Services\ServiceResult;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ForeignerFinalizationService
{
    public function __construct(
        private readonly TrustedXClientService $trustedXClient,
    ) {}

    /**
     * PDF §4: after supervisor approval invite, foreigner sets password/PIN (+ optional security questions),
     * then TrustedX enrollment is triggered.
     *
     * @param  array<string, mixed>|null  $securityQuestions
     */
    public function finalize(string $token, string $npi, string $password, string $pin, ?array $securityQuestions = null): ServiceResult
    {
        $tokenData = PasswordResetToken::where('token', $token)->first();

        if (! $tokenData) {
            return ServiceResult::fail('Le lien de mise à jour des identifiants est invalide', null, 404);
        }

        if (Carbon::parse($tokenData->created_at)->addMinutes(60)->isPast()) {
            return ServiceResult::fail('Le lien de mise à jour des identifiants est expiré.', null, 400);
        }

        if ($tokenData->npi !== $npi) {
            return ServiceResult::fail('Le NPI ne correspond pas au lien fourni.', null, 400);
        }

        $localUser = User::where('npi', $npi)->first();
        if (! $localUser) {
            return ServiceResult::fail('Aucun utilisateur ne correspond à ce npi', null, 404);
        }

        DB::beginTransaction();
        try {
            $trustedXUserId = null;

            if ($localUser->trustedx_registered_at === null) {
                $registerResult = $this->trustedXClient->register(['data' => ['npi' => $npi]]);
                if (! ($registerResult['status'] ?? false)) {
                    DB::rollBack();

                    return ServiceResult::fail(
                        $registerResult['message'] ?? 'Échec de l\'enrôlement TrustedX.',
                        null,
                        400
                    );
                }

                $trustedXUserId = $registerResult['data']['id'] ?? null;
                if (! $trustedXUserId) {
                    $lookup = $this->trustedXClient->getUserWithNPI($npi);
                    if (! ($lookup['status'] ?? false)) {
                        DB::rollBack();

                        return ServiceResult::fail(
                            $lookup['message'] ?? 'Impossible de récupérer le compte TrustedX.',
                            null,
                            400
                        );
                    }
                    $trustedXUserId = $lookup['data']['id'] ?? null;
                }

                $localUser->trustedx_registered_at = now();
            } else {
                $lookup = $this->trustedXClient->getUserWithNPI($npi);
                if (! ($lookup['status'] ?? false)) {
                    DB::rollBack();

                    return ServiceResult::fail(
                        $lookup['message'] ?? 'Impossible de récupérer le compte TrustedX.',
                        null,
                        400
                    );
                }
                $trustedXUserId = $lookup['data']['id'] ?? null;
            }

            if (! $trustedXUserId) {
                DB::rollBack();

                return ServiceResult::fail('Identifiant TrustedX introuvable.', null, 400);
            }

            $passwordOutput = $this->trustedXClient->setDefaultPassword(
                ['id' => $trustedXUserId, 'password' => $password],
                'password'
            );
            $pinOutput = $this->trustedXClient->setDefaultPassword(
                ['id' => $trustedXUserId, 'password' => $pin],
                'pin'
            );

            if (! ($passwordOutput['status'] ?? false) || ! ($pinOutput['status'] ?? false)) {
                DB::rollBack();

                $message = trim(
                    ($passwordOutput['message'] ?? '').' '.($pinOutput['message'] ?? '')
                );

                return ServiceResult::fail(
                    $message !== '' ? $message : 'Échec de la définition du mot de passe / PIN.',
                    null,
                    400
                );
            }

            if ($securityQuestions !== null) {
                $localUser->security_questions = $securityQuestions;
            }

            $localUser->status = 'ACTIVE';
            $localUser->save();

            EnrollmentRequest::query()
                ->where('email', $localUser->email)
                ->where('status', 'APPROVED')
                ->update(['status' => 'FINALIZED']);

            PasswordResetToken::where('token', $token)->delete();

            DB::commit();

            $lookup = $this->trustedXClient->getUserWithNPI($npi);
            $txData = ($lookup['status'] ?? false) ? ($lookup['data'] ?? []) : [];

            return ServiceResult::ok('Vos identifiants ont bien été mis à jour. Enrôlement finalisé.', [
                ...json_decode(json_encode($localUser->load('identities')), true),
                ...$txData,
                ...($passwordOutput['data'] ?? []),
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Foreigner finalization failed: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return ServiceResult::fail('Erreur lors de la finalisation.', null, 500);
        }
    }
}
