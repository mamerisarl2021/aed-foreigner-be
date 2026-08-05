<?php

declare(strict_types=1);

namespace App\Services\PasswordReset;

use App\Enums\ActivityLogAction;
use App\Jobs\SendLinkJob;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Services\ActivityLog\ActivityLogService;
use App\Services\PKI\TrustedXClientService;
use App\Services\ServiceResult;
use Carbon\Carbon;
use Illuminate\Support\Str;

final class ClientPasswordResetService
{
    private const TOKEN_TTL_MINUTES = 60;

    public function __construct(
        private readonly TrustedXClientService $trustedXClient,
        private readonly ActivityLogService $activityLog,
    ) {}

    public function sendResetLink(string $npi, string $type): ServiceResult
    {
        $trustedXUser = $this->trustedXClient->getUserWithNPI($npi);
        if (! ($trustedXUser['status'] ?? false)) {
            return ServiceResult::fail((string) ($trustedXUser['message'] ?? 'Utilisateur introuvable.'), null, 400);
        }

        $localUser = User::where('npi', $npi)->first();
        if (! $localUser || ! $localUser->email) {
            return ServiceResult::fail('Aucun utilisateur ne correspond à ce npi', null, 404);
        }

        $token = Str::random(60);

        PasswordResetToken::updateOrCreate(
            ['npi' => $npi],
            ['token' => hash('sha256', $token), 'created_at' => Carbon::now(), 'type' => $type]
        );

        $link = config('app.frontend_url')."/reset/{$type}/{$token}/{$npi}";
        SendLinkJob::dispatch($localUser->email, $link);

        $typeLabel = $type === 'password' ? 'mot de passe' : 'pin';

        $this->activityLog->record(
            ActivityLogAction::MotDePasseReinitialise,
            sprintf('Lien de réinitialisation %s client envoyé (NPI).', $typeLabel),
            is_string($localUser->id) ? $localUser->id : null,
            null,
            ['npi' => $npi, 'type' => $type, 'step' => 'link_sent'],
        );

        return ServiceResult::ok(
            "Un lien vous a été envoyé par MAIL consultez le pour mettre à jour votre {$typeLabel}.",
            []
        );
    }

    public function resetPassword(string $token, string $password, string $npi, string $type): ServiceResult
    {
        $typeLabel = $type === 'password' ? 'mot de passe' : 'pin';

        $tokenData = $this->findValidToken($token, $npi);
        if ($tokenData instanceof ServiceResult) {
            return $tokenData;
        }

        $localUser = User::where('npi', $tokenData->npi)->first();
        if (! $localUser) {
            return ServiceResult::fail('Aucun utilisateur ne correspond à ce npi', null, 404);
        }

        $trustedXUser = $this->trustedXClient->getUserWithNPI($npi);
        if (! ($trustedXUser['status'] ?? false)) {
            return ServiceResult::fail((string) ($trustedXUser['message'] ?? 'Utilisateur introuvable.'), null, 400);
        }

        $output = $this->trustedXClient->setDefaultPassword(
            ['id' => $trustedXUser['data']['id'], 'password' => $password],
            $type
        );

        if (! ($output['status'] ?? false)) {
            return ServiceResult::fail((string) ($output['message'] ?? 'Échec de la mise à jour.'), null, 400);
        }

        $this->invalidateToken($tokenData);

        $this->activityLog->record(
            ActivityLogAction::MotDePasseReinitialise,
            sprintf('Réinitialisation %s client effectuée (NPI).', $typeLabel),
            is_string($localUser->id) ? $localUser->id : null,
            null,
            ['npi' => $npi, 'type' => $type, 'step' => 'reset_done'],
        );

        return ServiceResult::ok("Votre {$typeLabel} a bien été mis à jour.", []);
    }

    public function resetCredentials(string $token, string $npi, ?string $password, ?string $pin): ServiceResult
    {
        $tokenData = $this->findValidToken($token, $npi);
        if ($tokenData instanceof ServiceResult) {
            return $tokenData;
        }

        $localUser = User::where('npi', $tokenData->npi)->first();
        if (! $localUser) {
            return ServiceResult::fail('Aucun utilisateur ne correspond à ce npi', null, 404);
        }

        $trustedXUser = $this->trustedXClient->getUserWithNPI($npi);
        if (! ($trustedXUser['status'] ?? false)) {
            return ServiceResult::fail((string) ($trustedXUser['message'] ?? 'Utilisateur introuvable.'), null, 400);
        }

        $trustedXUserId = $trustedXUser['data']['id'];

        $passwordOutput = $password !== null
            ? $this->trustedXClient->setDefaultPassword(['id' => $trustedXUserId, 'password' => $password], 'password')
            : ['status' => true];
        $pinOutput = $pin !== null
            ? $this->trustedXClient->setDefaultPassword(['id' => $trustedXUserId, 'password' => $pin], 'pin')
            : ['status' => true];

        if (! ($passwordOutput['status'] ?? false) || ! ($pinOutput['status'] ?? false)) {
            return ServiceResult::fail(
                trim(($passwordOutput['message'] ?? '').' '.($pinOutput['message'] ?? '')),
                null,
                400
            );
        }

        $this->invalidateToken($tokenData);

        $this->activityLog->record(
            ActivityLogAction::MotDePasseReinitialise,
            'Réinitialisation des identifiants client effectuée (NPI).',
            is_string($localUser->id) ? $localUser->id : null,
            null,
            ['npi' => $npi, 'type' => 'credentials', 'step' => 'reset_done'],
        );

        return ServiceResult::ok('Vos identifiants ont bien été mis à jour.', []);
    }

    private function findValidToken(string $token, string $npi): PasswordResetToken|ServiceResult
    {
        $tokenData = PasswordResetToken::where('token', hash('sha256', $token))->first();

        if (! $tokenData || ! hash_equals((string) $tokenData->npi, $npi)) {
            return ServiceResult::fail('Le lien de mise à jour est invalide', null, 404);
        }

        if (Carbon::parse($tokenData->created_at)->addMinutes(self::TOKEN_TTL_MINUTES)->isPast()) {
            return ServiceResult::fail('Le lien de mise à jour est expiré.', null, 400);
        }

        return $tokenData;
    }

    private function invalidateToken(PasswordResetToken $tokenData): void
    {
        PasswordResetToken::where('npi', $tokenData->npi)
            ->where('token', $tokenData->token)
            ->delete();
    }
}
