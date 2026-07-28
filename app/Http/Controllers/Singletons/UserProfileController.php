<?php

declare(strict_types=1);

namespace App\Http\Controllers\Singletons;

use App\Http\Controllers\BaseController;
use App\Http\Resources\UserResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Client Auth')]
class UserProfileController extends BaseController
{
    /**
     * Authenticated user profile
     */
    public function __invoke(Request $request): JsonResponse
    {
        return $this->sendResponse('Profil utilisateur.', new UserResource($request->user()));
    }
}
