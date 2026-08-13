<?php

declare(strict_types=1);

namespace App\Http\Controllers\Singletons;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Auth\MeProfileRequest;
use App\Http\Resources\UserResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Client Auth')]
class UserProfileController extends BaseController
{
    /**
     * Authenticated user profile
     */
    public function __invoke(MeProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user?->loadMissing(['roles', 'identities']);

        return $this->sendResponse('Profil utilisateur.', new UserResource($user));
    }
}
