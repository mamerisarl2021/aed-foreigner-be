<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\ActivityLogAction;
use App\Models\User;
use App\Services\ActivityLog\ActivityLogService;
use App\Services\PKI\TrustedXClientService;
use App\Services\ServiceResult;
use App\Support\ClientLocalCredentials;
use App\Support\SecurityQuestions;
use InvalidArgumentException;

final class ClientSecurityService
{
    public function __construct(
        private readonly TrustedXClientService $trustedXClient,
        private readonly ActivityLogService $activityLog,
    ) {}

    public function logout(User $user): ServiceResult
    {
        $user->currentAccessToken()->delete();

        $this->activityLog->record(
            ActivityLogAction::DeconnexionClient,
            sprintf('%s s\'est déconnecté(e) de l\'espace client.', ActivityLogService::actorLabel($user)),
            $user->id,
        );

        return ServiceResult::ok('Déconnexion réussie.', []);
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword): ServiceResult
    {
        if (! ClientLocalCredentials::passwordIsStored($user)) {
            return ServiceResult::fail(ClientLocalCredentials::MISSING_HASH_MESSAGE, null, 422);
        }

        if (! ClientLocalCredentials::passwordMatches($user, $currentPassword)) {
            return ServiceResult::fail('Mot de passe actuel incorrect.', null, 400);
        }

        $pushed = $this->pushTrustedXSecret($user, $newPassword, 'password');
        if (! $pushed->success) {
            return $pushed;
        }

        ClientLocalCredentials::apply($user, 'password', $newPassword);
        $user->save();
        $user->tokens()->delete();

        $this->activityLog->record(
            ActivityLogAction::MotDePasseChange,
            sprintf('%s a modifié son mot de passe client.', ActivityLogService::actorLabel($user)),
            $user->id,
            null,
            ['type' => 'password', 'context' => 'client_change'],
        );

        return ServiceResult::ok('Mot de passe mis à jour avec succès. Veuillez vous reconnecter.', []);
    }

    public function changePin(User $user, string $currentPin, string $newPin): ServiceResult
    {
        if (! ClientLocalCredentials::pinIsStored($user)) {
            return ServiceResult::fail(ClientLocalCredentials::MISSING_HASH_MESSAGE, null, 422);
        }

        if (! ClientLocalCredentials::pinMatches($user, $currentPin)) {
            return ServiceResult::fail('PIN actuel incorrect.', null, 400);
        }

        $pushed = $this->pushTrustedXSecret($user, $newPin, 'pin');
        if (! $pushed->success) {
            return $pushed;
        }

        ClientLocalCredentials::apply($user, 'pin', $newPin);
        $user->save();

        $this->activityLog->record(
            ActivityLogAction::MotDePasseChange,
            sprintf('%s a modifié son PIN client.', ActivityLogService::actorLabel($user)),
            $user->id,
            null,
            ['type' => 'pin', 'context' => 'client_change'],
        );

        return ServiceResult::ok('PIN mis à jour avec succès.', []);
    }

    /**
     * @return array{configure: bool, questions: list<array{question: string}>}
     */
    public function listSecurityQuestions(User $user): array
    {
        return [
            'configure' => ClientLocalCredentials::securityQuestionsConfigured($user),
            'questions' => SecurityQuestions::questionsForDisplay($user->security_questions),
        ];
    }

    /**
     * @param  list<array{question: string, answer: string}>  $securityQuestions
     */
    public function updateSecurityQuestions(User $user, string $currentPassword, array $securityQuestions): ServiceResult
    {
        if (! ClientLocalCredentials::passwordIsStored($user)) {
            return ServiceResult::fail(ClientLocalCredentials::MISSING_HASH_MESSAGE, null, 422);
        }

        if (! ClientLocalCredentials::passwordMatches($user, $currentPassword)) {
            return ServiceResult::fail('Mot de passe actuel incorrect.', null, 400);
        }

        try {
            $user->security_questions = SecurityQuestions::persist($securityQuestions);
        } catch (InvalidArgumentException $e) {
            return ServiceResult::fail($e->getMessage(), null, 422);
        }

        $user->save();

        $this->activityLog->record(
            ActivityLogAction::UtilisateurModifie,
            sprintf('%s a mis à jour ses questions secrètes.', ActivityLogService::actorLabel($user)),
            $user->id,
            null,
            ['context' => 'security_questions'],
        );

        return ServiceResult::ok('Questions de sécurité mises à jour.', $this->listSecurityQuestions($user->fresh() ?? $user));
    }

    private function pushTrustedXSecret(User $user, string $plain, string $type): ServiceResult
    {
        $npi = $user->npi;
        if (! is_string($npi) || $npi === '') {
            return ServiceResult::fail('NPI introuvable.', null, 422);
        }

        $trustedXUser = $this->trustedXClient->getUserWithNPI($npi);
        if (! ($trustedXUser['status'] ?? false)) {
            return ServiceResult::fail((string) ($trustedXUser['message'] ?? 'Utilisateur TrustedX introuvable.'), null, 400);
        }

        $trustedXUserId = $trustedXUser['data']['id'] ?? null;
        if (! is_string($trustedXUserId) || $trustedXUserId === '') {
            return ServiceResult::fail('Identifiant TrustedX introuvable.', null, 400);
        }

        $output = $this->trustedXClient->setDefaultPassword(
            ['id' => $trustedXUserId, 'password' => $plain],
            $type
        );

        if (! ($output['status'] ?? false)) {
            return ServiceResult::fail((string) ($output['message'] ?? 'Échec de la mise à jour TrustedX.'), null, 400);
        }

        return ServiceResult::ok('ok');
    }
}
