<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Rating\RatingService;
use Illuminate\Console\Command;

class RecomputeUserRating extends Command
{
    protected $signature = 'ratings:recompute {user : User ID}';

    protected $description = 'Recompute Stars + Grade for a single user';

    public function handle(RatingService $ratings): int
    {
        $userId = (int) $this->argument('user');
        $user = User::find($userId);

        if ($user === null) {
            $this->error("User #{$userId} not found.");

            return self::FAILURE;
        }

        $ratings->recomputeForUser($userId);
        $this->info("Recomputed ratings for user #{$userId} ({$user->first_name}).");

        return self::SUCCESS;
    }
}
