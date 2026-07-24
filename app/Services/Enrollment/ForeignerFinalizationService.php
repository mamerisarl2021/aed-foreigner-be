<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Contracts\EnrollmentEventPublisherInterface;
use App\Enums\ActivityLogAction;
use App\Enums\EnrollmentStatus;
use App\Models\EnrollmentRequest;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Services\ActivityLog\ActivityLogService;
use App\Services\PKI\TrustedXClientService;
use App\Services\ServiceResult;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ForeignerFinalizationService
{
    public function __construct(
        private readonly TrustedXClientService $trustedXClient,
        private readonly EnrollmentEventPublisherInterface $events,
        private readonly ActivityLogService $activityLog,
    ) {}

    public function showByToken(string $token): ServiceResult
    {
        $tokenData = PasswordResetToken::where('token', $token)->where('type', 'finalisation')->first();
        if (! $tokenData) {
            return ServiceResult::fail('Lien de finalisation invalide.', null, 404);
        }

        if (Carbon::parse($tokenData->created_at)->addMinutes(60)->isPast()) {
            return ServiceResult::fail('Lien de finalisation expiré.', null, 400);
        }

        $user = User::where('npi', $tokenData->npi)->first();
        if (! $user) {
            return ServiceResult::fail('Utilisateur introuvable.', null, 404);
        }

        $enrollment = EnrollmentRequest::query()
            ->where('email', $user->email)
            ->where('status', EnrollmentStatus::Approuvee->value)
            ->latest('id')
            ->first();

        if (! $enrollment) {
            return ServiceResult::fail('Aucune demande en attente de finalisation.', null, 404);
        }

        return ServiceResult::ok('Demande éligible à la finalisation.', [
            'demande_id' => $enrollment->id,
            'statut' => $enrollment->status,
            'npi' => $user->npi,
            'email' => $user->email,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $securityQuestions
     */
    public function finalize(int $demandeId, string $token, string $password, string $pin, ?array $securityQuestions = null): ServiceResult
    {
        $tokenData = PasswordResetToken::where('token', $token)->where('type', 'finalisation')->first();
        if (! $tokenData) {
            return ServiceResult::fail('Lien de finalisation invalide.', null, 404);
        }

        if (Carbon::parse($tokenData->created_at)->addMinutes(60)->isPast()) {
            return ServiceResult::fail('Lien de finalisation expiré.', null, 400);
        }

        $localUser = User::where('npi', $tokenData->npi)->first();
        if (! $localUser) {
            return ServiceResult::fail('Utilisateur introuvable.', null, 404);
        }

        $enrollment = EnrollmentRequest::findOrFail($demandeId);
        if ($enrollment->email !== $localUser->email || $enrollment->status !== EnrollmentStatus::Approuvee->value) {
            return ServiceResult::fail('Demande non éligible à la finalisation.', null, 422);
        }

        if ($localUser->trustedx_registered_at === null) {
            return ServiceResult::fail('Identité TrustedX non enregistrée. Contactez le support.', null, 422);
        }

        DB::beginTransaction();
        try {
            $lookup = $this->trustedXClient->getUserWithNPI($localUser->npi);
            if (! ($lookup['status'] ?? false)) {
                DB::rollBack();

                return ServiceResult::fail($lookup['message'] ?? 'Impossible de récupérer le compte TrustedX.', null, 400);
            }

            $trustedXUserId = $lookup['data']['id'] ?? null;
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

                return ServiceResult::fail('Échec de la définition du mot de passe / PIN.', null, 400);
            }

            if ($securityQuestions !== null) {
                $localUser->security_questions = $securityQuestions;
            }

            $localUser->status = 'ACTIVE';
            $localUser->save();

            $enrollment->status = EnrollmentStatus::Enrolee->value;
            $enrollment->save();

            PasswordResetToken::where('token', $token)->delete();

            $this->events->publish('completed', [
                'demande_id' => $enrollment->id,
                'npi' => $localUser->npi,
                'statut' => $enrollment->status,
            ]);

            $this->activityLog->record(
                ActivityLogAction::EnrolementFinalise,
                sprintf(
                    '%s %s a finalisé son enrôlement.',
                    $localUser->first_name,
                    $localUser->name
                ),
                $localUser->id,
                $enrollment->id,
            );

            DB::commit();

            return ServiceResult::ok('Enrôlement finalisé.', [
                'demande_id' => $enrollment->id,
                'statut' => $enrollment->status,
                'npi' => $localUser->npi,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Foreigner finalization failed: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return ServiceResult::fail('Erreur lors de la finalisation.', null, 500);
        }
    }
}
