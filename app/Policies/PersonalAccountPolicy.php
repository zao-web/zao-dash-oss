<?php

namespace App\Policies;

use App\Models\PersonalAccount;
use App\Models\User;

class PersonalAccountPolicy
{
    /**
     * Users can view their own personal accounts.
     */
    public function view(User $user, PersonalAccount $personalAccount): bool
    {
        return $user->id === $personalAccount->user_id;
    }

    /**
     * Users can update their own personal accounts.
     */
    public function update(User $user, PersonalAccount $personalAccount): bool
    {
        return $user->id === $personalAccount->user_id;
    }

    /**
     * Users can delete their own personal accounts.
     */
    public function delete(User $user, PersonalAccount $personalAccount): bool
    {
        return $user->id === $personalAccount->user_id;
    }
}
