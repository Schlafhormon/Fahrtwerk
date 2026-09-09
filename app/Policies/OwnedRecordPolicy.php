<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class OwnedRecordPolicy
{
    public function view(User $user, Model $record): bool
    {
        return $user->active && ($user->isAdmin() || $record->user_id === $user->id);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->view($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->view($user, $record);
    }
}
