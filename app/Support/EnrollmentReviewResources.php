<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Resources\EnrollmentDecisionDetailResource;
use App\Http\Resources\EnrollmentDecisionListResource;
use App\Http\Resources\EnrollmentRequestAgentDetailResource;
use App\Http\Resources\EnrollmentRequestListResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\JsonResource;

final class EnrollmentReviewResources
{
    /**
     * @return class-string<JsonResource>
     */
    public static function listClass(User $user): string
    {
        return $user->hasRole(config('roles.responsable_de_validation'))
            ? EnrollmentDecisionListResource::class
            : EnrollmentRequestListResource::class;
    }

    public static function detail(User $user, mixed $enrollment): JsonResource
    {
        return $user->hasRole(config('roles.responsable_de_validation'))
            ? new EnrollmentDecisionDetailResource($enrollment)
            : new EnrollmentRequestAgentDetailResource($enrollment);
    }
}
