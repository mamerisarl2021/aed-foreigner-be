<?php

namespace App\Jobs;

use App\DataTransferObjects\EmailNotificationData;
use App\Enums\NotificationPlatform;
use App\Enums\NotificationTemplate;
use App\Jobs\Notifications\SendEmailNotificationJob;
use App\Models\Revocation;
use App\Services\PKI\TrustedXClientService;
use App\Support\NotificationRecipient;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessRevocationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $revocationId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(TrustedXClientService $trustedXClient): void
    {
        $revocation = Revocation::with('user')->find($this->revocationId);

        if (! $revocation) {
            Log::warning("ProcessRevocationJob aborted: Revocation {$this->revocationId} not found.");

            return;
        }

        try {
            $postData = ['sign_identities_group_id' => $revocation->group_id];
            $tokenResponse = $trustedXClient->getToken('urn:safelayer:eidas:sign:identity:manage');

            if (! $tokenResponse['status']) {
                throw new Exception('Nous n\'avons pas pu récupérer le token d\'authentification.');
            }

            $token = $tokenResponse['token'];

            // POST — Create the revocation process on TrustedX
            $postResponse = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$token,
            ])->post('https://'.config('trustedx.base_url').'/trustedx-resources/rap/v2/revocation_processes', $postData);

            if ($postResponse->failed()) {
                throw new Exception('Erreur lors de la création du processus de révocation. Status: '.$postResponse->status());
            }

            // DELETE — Clean up the revocation process
            Http::withHeaders([
                'Content-Type' => 'application/x-www-form-urlencoded',
            ])->delete('https://'.config('trustedx.base_url').'/trustedx-resources/rap/v2/revocation_processes/'.$revocation->id);

            // Send notification to the user
            $user = $revocation->user;
            if ($user && $user->email) {
                SendEmailNotificationJob::dispatch(new EmailNotificationData(
                    subject: 'Demande révocation traitée.',
                    template: NotificationTemplate::Revocated,
                    recipients: [
                        NotificationRecipient::email($user->email, [
                            'user' => $user->email,
                        ]),
                    ],
                    variables: [
                        'user' => $user->email,
                    ],
                    type: 'REVOCATED_EMAIL',
                    platform: NotificationPlatform::from(config('notifications.platform')),
                ));
            }
        } catch (Exception $e) {
            Log::error("ProcessRevocationJob failed for Revocation {$this->revocationId}: ".$e->getMessage());
            throw $e;
        }
    }
}
