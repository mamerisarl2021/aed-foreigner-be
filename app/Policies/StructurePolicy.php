<?php

namespace App\Policies;

use App\Models\Structure;
use App\Models\User;

class StructurePolicy
{
    public function view(User $user, Structure $structure)
    {
        return $user->id === $structure->user_id;
    }

    public function delete(User $user, Structure $structure)
    {
        return $user->id === $structure->user_id;
    }
}
