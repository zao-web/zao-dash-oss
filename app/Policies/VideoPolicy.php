<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Video;

class VideoPolicy
{
    /**
     * Users can view their own videos.
     */
    public function view(User $user, Video $video): bool
    {
        return $user->id === $video->user_id;
    }

    /**
     * Users can update their own videos.
     */
    public function update(User $user, Video $video): bool
    {
        return $user->id === $video->user_id;
    }

    /**
     * Users can delete their own videos.
     */
    public function delete(User $user, Video $video): bool
    {
        return $user->id === $video->user_id;
    }
}
