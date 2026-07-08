<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\Cases;
use App\Models\Identity;
use App\Models\IdRequest;
use App\Models\Message;
use App\Models\OTP;
use App\Models\Structure;
use App\Models\StructurePackage;
use App\Models\StructureSubscription;
use App\Models\User;
use App\Models\UserPackage;
use App\Models\UserSubscription;

class AuthorizationPolicy
{
    // Role-Based Access Control (RBAC)
    public function before(User $user)
    {
        if ($user->hasRole('admin')) {
            return true; // Admin has full access
        }
    }

    // Common Ownership Check
    private function ownsResource(User $user, $resource)
    {
        return $resource->user_id === $user->id || $resource->structure->manager_id === $user->id;
    }

    // Permissions for Attachment
    public function viewAttachment(User $user, Attachment $attachment)
    {
        return $this->ownsResource($user, $attachment->structure);
    }

    public function updateAttachment(User $user, Attachment $attachment)
    {
        return $this->ownsResource($user, $attachment->structure);
    }

    public function deleteAttachment(User $user, Attachment $attachment)
    {
        return $this->ownsResource($user, $attachment->structure);
    }

    // Permissions for Cases
    public function viewCase(User $user, Cases $case)
    {
        return $this->ownsResource($user, $case) || $user->id === $case->user_id;
    }

    public function updateCase(User $user, Cases $case)
    {
        return $this->ownsResource($user, $case) || $user->id === $case->user_id;
    }

    public function deleteCase(User $user, Cases $case)
    {
        return $this->ownsResource($user, $case) || $user->id === $case->user_id;
    }

    // Permissions for Identity
    public function viewIdentity(User $user, Identity $identity)
    {
        return $this->ownsResource($user, $identity) || $user->id === $identity->user_id;
    }

    public function updateIdentity(User $user, Identity $identity)
    {
        return $this->ownsResource($user, $identity) || $user->id === $identity->user_id;
    }

    public function deleteIdentity(User $user, Identity $identity)
    {
        return $this->ownsResource($user, $identity) || $user->id === $identity->user_id;
    }

    // Permissions for IdRequest
    public function viewIdRequest(User $user, IdRequest $idRequest)
    {
        return $this->ownsResource($user, $idRequest->structure);
    }

    public function updateIdRequest(User $user, IdRequest $idRequest)
    {
        return $this->ownsResource($user, $idRequest->structure);
    }

    public function deleteIdRequest(User $user, IdRequest $idRequest)
    {
        return $this->ownsResource($user, $idRequest->structure);
    }

    // Permissions for Message
    public function viewMessage(User $user, Message $message)
    {
        return $this->ownsResource($user, $message->case) || $user->id === $message->user_id;
    }

    public function updateMessage(User $user, Message $message)
    {
        return $this->ownsResource($user, $message->case) || $user->id === $message->user_id;
    }

    public function deleteMessage(User $user, Message $message)
    {
        return $this->ownsResource($user, $message->case) || $user->id === $message->user_id;
    }

    // Permissions for OTP
    public function viewOtp(User $user, OTP $otp)
    {
        return $user->email === $otp->email || $user->npi === $otp->npi;
    }

    // Permissions for Structure
    public function viewStructure(User $user, Structure $structure)
    {
        return $this->ownsResource($user, $structure);
    }

    public function updateStructure(User $user, Structure $structure)
    {
        return $this->ownsResource($user, $structure);
    }

    public function deleteStructure(User $user, Structure $structure)
    {
        return $this->ownsResource($user, $structure);
    }

    // Permissions for StructurePackage
    public function viewStructurePackage(User $user, StructurePackage $structurePackage)
    {
        return $this->ownsResource($user, $structurePackage);
    }

    public function updateStructurePackage(User $user, StructurePackage $structurePackage)
    {
        return $this->ownsResource($user, $structurePackage);
    }

    public function deleteStructurePackage(User $user, StructurePackage $structurePackage)
    {
        return $this->ownsResource($user, $structurePackage);
    }

    // Permissions for StructureSubscription
    public function viewStructureSubscription(User $user, StructureSubscription $structureSubscription)
    {
        return $this->ownsResource($user, $structureSubscription->structure);
    }

    public function updateStructureSubscription(User $user, StructureSubscription $structureSubscription)
    {
        return $this->ownsResource($user, $structureSubscription->structure);
    }

    public function deleteStructureSubscription(User $user, StructureSubscription $structureSubscription)
    {
        return $this->ownsResource($user, $structureSubscription->structure);
    }

    // Permissions for UserPackage
    public function viewUserPackage(User $user, UserPackage $userPackage)
    {
        return $this->ownsResource($user, $userPackage);
    }

    public function updateUserPackage(User $user, UserPackage $userPackage)
    {
        return $this->ownsResource($user, $userPackage);
    }

    public function deleteUserPackage(User $user, UserPackage $userPackage)
    {
        return $this->ownsResource($user, $userPackage);
    }

    // Permissions for UserSubscription
    public function viewUserSubscription(User $user, UserSubscription $userSubscription)
    {
        return $this->ownsResource($user, $userSubscription->user) || $this->ownsResource($user, $userSubscription->structure);
    }

    public function updateUserSubscription(User $user, UserSubscription $userSubscription)
    {
        return $this->ownsResource($user, $userSubscription->user) || $this->ownsResource($user, $userSubscription->structure);
    }

    public function deleteUserSubscription(User $user, UserSubscription $userSubscription)
    {
        return $this->ownsResource($user, $userSubscription->user) || $this->ownsResource($user, $userSubscription->structure);
    }
}
