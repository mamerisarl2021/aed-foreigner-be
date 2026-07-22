<?php

namespace App\Http\Controllers;

use App\Http\Requests\User\LoginWithCodeRequest;
use App\Http\Requests\User\SendOtpRequest;
use App\Http\Requests\User\UpdateUserStatusRequest;
use App\Http\Requests\User\VerifyOtpRequest;
use App\Models\User;
use App\Services\Registration\UserRegistrationService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends BaseController
{
    public function __construct(
        private readonly UserRegistrationService $registration,
    ) {}

    public function sendOtp(SendOtpRequest $request)
    {
        return $this->respond($this->registration->sendOtp($request->input('npi')));
    }

    public function verifyOtp(VerifyOtpRequest $request)
    {
        return $this->respond($this->registration->verifyOtp(
            $request->input('npi'),
            $request->input('otp'),
        ));
    }

    public function login(LoginWithCodeRequest $request)
    {
        return $this->respond($this->registration->login($request->input('code')));
    }

    public function loginMobile(LoginWithCodeRequest $request)
    {
        return $this->respond($this->registration->loginMobile($request->input('code')));
    }

    public function show($id)
    {
        try {
            $user = User::findOrFail($id);

            return $this->sendResponse('Utilisateur récupéré.', $user);
        } catch (Exception $e) {
            Log::error('Fetching user failed: '.$e->getMessage());

            return $this->sendError('Fetching user failed.', null, 500);
        }
    }

    public function search(Request $request)
    {
        try {
            $query = $request->input('query');
            $limit = (int) $request->get('limit', 10);

            $users = User::where(function ($q) use ($query) {
                $q->where('email', 'LIKE', "%$query%")
                    ->orWhere('name', 'LIKE', "%$query%")
                    ->orWhere('npi', 'LIKE', "%$query%");
            })
                ->where('id', '!=', auth()->id())
                ->limit($limit)
                ->get();

            return $this->sendResponse('Résultats de recherche.', $users);
        } catch (Exception $e) {
            Log::error('Searching users failed: '.$e->getMessage());

            return $this->sendError('Searching users failed.', null, 500);
        }
    }

    public function searchPost(Request $request)
    {
        try {
            $query = $request->input('email');

            $users = User::where('email', 'LIKE', "%$query%")
                ->where('id', '!=', auth()->id())
                ->get();

            return $this->sendResponse('Résultats de recherche.', $users);
        } catch (Exception $e) {
            Log::error('Searching users failed: '.$e->getMessage());

            return $this->sendError('Searching users failed.', null, 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'profile' => 'nullable|mimes:png,jpeg,jpg|max:2048',
                'email' => [
                    'required',
                    'email',
                    Rule::unique('users')->ignore($id),
                ],
            ]);

            $updateData = [
                'email' => $request->input('email'),
            ];
            $profile = $request->file('profile');

            $result = $this->registration->updateUser((int) $id, $updateData, $profile);

            if (! $result->success) {
                return $this->sendError($result->message, $result->data ?? [], $result->code);
            }

            return $this->sendResponse($result->message, $result->data ?? []);
        } catch (ValidationException $e) {
            Log::warning("Erreur de validation lors de la mise à jour de l'utilisateur : ", $e->errors());

            return $this->sendError('Erreur de validation.', $e->errors(), 422);
        } catch (Exception $e) {
            Log::error("Mise à jour de l'utilisateur échouée : ".$e->getMessage());

            return $this->sendError('Une erreur est survenue lors de la mise à jour de vos informations.', null, 500);
        }
    }

    public function destroy($id)
    {
        try {
            $user = User::findOrFail($id);
            $user->delete();

            return $this->sendResponse('User deleted successfully.', []);
        } catch (Exception $e) {
            Log::error('Deleting user failed: '.$e->getMessage());

            return $this->sendError('Deleting user failed.', null, 500);
        }
    }

    public function updateUserStatus(UpdateUserStatusRequest $request)
    {
        $users = $request->input('users');

        try {
            DB::transaction(function () use ($users) {
                foreach ($users as $userData) {
                    $user = User::findOrFail($userData['id']);
                    $user->update(['status' => $userData['status']]);
                }
            });

            return $this->sendResponse("Le statut de l'utilisateur à bien été mis à jour", $users);
        } catch (Exception $e) {
            Log::error('Failed to update user statuses: '.$e->getMessage());

            return $this->sendError('Failed to update user statuses.', null, 500);
        }
    }

    public function setPassword(Request $request)
    {
        $request->validate([
            'password' => 'required|string',
            'npi' => 'required|string',
            'type' => 'required|string|in:password,pin',
        ]);

        return $this->respond($this->registration->setPassword(
            $request->input('npi'),
            $request->input('password'),
            $request->input('type'),
        ));
    }

    public function sendResetLink(string $npi, string $type)
    {
        return $this->respond($this->registration->sendResetLink($npi, $type));
    }
}
