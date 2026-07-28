<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Auth\RegisterUserRequest;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class RegisteredUserController extends BaseController
{
    /**
     * Handle an incoming registration request.
     */
    public function store(RegisterUserRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->input('name'),
            'email' => $request->input('email'),
        ]);
        $user->forceFill(['password' => Hash::make($request->input('password'))])->save();

        event(new Registered($user));

        Auth::login($user);

        return $this->sendResponse('Inscription réussie.', [], 201);
    }
}
