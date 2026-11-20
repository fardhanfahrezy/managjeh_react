<?php

namespace App\Policies;

use App\Models\AiActionProposal;
use App\Models\User;

class AiActionProposalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->exists;
    }

    public function view(User $user, AiActionProposal $proposal): bool
    {
        return $user->id === $proposal->user_id;
    }

    public function update(User $user, AiActionProposal $proposal): bool
    {
        return $user->id === $proposal->user_id;
    }
}
