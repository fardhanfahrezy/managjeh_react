<?php

namespace App\Policies;

use App\Models\AiChatConversation;
use App\Models\User;

class AiChatConversationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->exists;
    }

    public function view(User $user, AiChatConversation $conversation): bool
    {
        return $user->id === $conversation->user_id;
    }

    public function create(User $user): bool
    {
        return $user->exists;
    }

    public function update(User $user, AiChatConversation $conversation): bool
    {
        return $user->id === $conversation->user_id;
    }

    public function delete(User $user, AiChatConversation $conversation): bool
    {
        return $user->id === $conversation->user_id;
    }
}
