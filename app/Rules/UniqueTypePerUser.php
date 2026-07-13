<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\DB;

// TODO: change deprecated Rule class
class UniqueTypePerUser implements Rule
{
    protected $userId;

    protected $level;

    public function __construct($userId, $level)
    {
        $this->userId = $userId;
        $this->level = $level;
    }

    public function passes($attribute, $value)
    {
        // Check if an entry with the same user_id and type already exists
        return ! DB::table('identities')
            ->where('user_id', $this->userId)
            ->where('type', $this->level)
            ->exists();
    }

    public function message()
    {
        return 'Ce niveau d\'identité est déjà activé.';
    }
}
