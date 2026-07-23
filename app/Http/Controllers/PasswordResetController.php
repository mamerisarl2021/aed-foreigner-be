<?php

namespace App\Http\Controllers;

use App\Jobs\SendLinkJob;
use App\Jobs\SendSmsJob;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Services\PKI\TrustedXClientService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PasswordResetController extends BaseController
{
    public function __construct(
        private readonly TrustedXClientService $trustedXClient,
    ) {}

    public function sendResetLink(Request $request)
    {
        $request->validate(['npi' => 'required|string', 'type' => 'required|string']);

        $npi = $request->input('npi');
        $type = $request->input('type');
        $token = Str::random(60);

        PasswordResetToken::updateOrCreate(
            ['npi' => $npi],
            ['token' => $token, 'created_at' => Carbon::now(), 'type' => $type]
        );

        $user = $this->trustedXClient->getUserWithNPI($request->input('npi'));
        if ($user['status']) {
            $type = $request->input('type') == 'password' ? 'mot de passe' : 'pin';
            $link = config('app.frontend_url')."/reset/{$request->input('type')}/$token/$npi";

            $phoneNumber = User::whereNpi($request->input('npi'))->first()->phonenumber;
            $email = User::whereNpi($request->input('npi'))->first()->email;
            // SendSmsJob::dispatch($phoneNumber, "Une demande de mise à jour de votre $type à été initialisée pour votre compte. Utilisez ce lien pour le mettre à jour : \n $link");
            SendLinkJob::dispatch('anagoarmandine@gmail.com', $link);
            SendLinkJob::dispatch($email, $link);

            $final = $this->sendResponse(
                "Un lien vous a été envoyé par MAIL consultez le pour mettre à jour votre $type.",
                []
            );
        } else {
            $final = $this->sendError($user['message'], null, 400);
        }

        return $final;
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'password' => 'required|string',
            'npi' => 'required|string',
            'type' => 'required|string',
        ]);
        $type = $request->input('type') == 'password' ? 'mot de passe' : 'pin';

        $tokenData = PasswordResetToken::where('token', $request->input('token'))->first();

        if (! $tokenData) {
            return response()->json(['message' => "Le lien de mise à jour du $type est invalide"], 404);
        }

        if (Carbon::parse($tokenData->created_at)->addMinutes(60)->isPast()) {
            return response()->json(['message' => "Le lien de mise à jour du $type est expiré."], 400);
        }

        $user = User::where('npi', $tokenData->npi)->first();

        if ($user) {
            $user = $this->trustedXClient->getUserWithNPI($request->input('npi'));
            if ($user['status']) {
                $output = $this->trustedXClient->setDefaultPassword(['id' => $user['data']['id'], 'password' => $request->input('password')], $request->input('type'));
                if ($output['status']) {
                    $phoneNumber = User::whereNpi($request->input('npi'))->first()->phonenumber;
                    // SendSmsJob::dispatch($phoneNumber, "Votre $type vient d'être modifié si vous n'êtes pas à l'origine de cette modification; nous vous prions de signaler cette opération et de procéder à la mise à jour de vos informations.");

                    $final = $this->sendResponse(
                        "Votre $type a bien été mis à jour.",
                        [...$user['data'], ...$output['data']]
                    );
                } else {
                    $final = $this->sendError($output['message'], null, 400);
                }
            } else {
                $final = $this->sendError($user['message'], null, 400);
            }

            return $final;
        }

        return $this->sendError('Aucun utilisateur ne correspond à ce npi', null, 404);
    }

    public function resetSome(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'password' => 'sometimes|string',
            'pin' => 'sometimes|string',
            'npi' => 'required|string',
        ]);
        $tokenData = PasswordResetToken::where('token', $request->input('token'))->first();

        if (! $tokenData) {
            return response()->json(['message' => 'Le lien de mise à jour des identifiants est invalide'], 404);
        }

        if (Carbon::parse($tokenData->created_at)->addMinutes(60)->isPast()) {
            return response()->json(['message' => 'Le lien de mise à jour  des identifiants est expiré.'], 400);
        }

        $localUser = User::where('npi', $tokenData->npi)->first();

        if ($localUser) {
            $user = $this->trustedXClient->getUserWithNPI($request->input('npi'));
            if ($user['status']) {
                $passwordOutput = $request->input('password') != null ? $this->trustedXClient->setDefaultPassword(['id' => $user['data']['id'], 'password' => $request->input('password')], 'password') : ['status' => true, 'data' => []];
                $pinOutput = $request->input('pin') != null ? $this->trustedXClient->setDefaultPassword(['id' => $user['data']['id'], 'password' => $request->input('pin')], 'pin') : ['status' => true, 'data' => []];
                if ($passwordOutput['status'] && $pinOutput['status']) {
                    $phoneNumber = User::whereNpi($request->input('npi'))->first()->phonenumber;
                    // SendSmsJob::dispatch($phoneNumber, "Votre $type vient d'être modifié si vous n'êtes pas à l'origine de cette modification; nous vous prions de signaler cette opération et de procéder à la mise à jour de vos informations.");

                    $final = $this->sendResponse(
                        'Vos identifiants ont bien été mis à jour.',
                        [...json_decode(json_encode($localUser->load('identities')), true), ...$user['data'], ...$passwordOutput['data']]
                    );
                } else {
                    $final = $this->sendError($passwordOutput['message'].' '.$pinOutput['message'], null, 400);
                }
            } else {
                $final = $this->sendError($user['message'], null, 400);
            }

            return $final;
        }

        return $this->sendError('Aucun utilisateur ne correspond à ce npi', null, 404);
    }
}
