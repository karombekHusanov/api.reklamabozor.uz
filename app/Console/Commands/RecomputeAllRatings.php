<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Services\Rating\RatingService;
use Illuminate\Console\Command;

class RecomputeAllRatings extends Command
{
    protected $signature = 'ratings:recompute-all';

    protected $description = 'Recompute Stars + Grade for every user';

    public function handle(RatingService $ratings): int
    {
        $count = 0;

        User::query()
            ->where('role', '!=', Role::Admin)
            ->chunkById(100, function ($users) use ($ratings, &$count): void {
                foreach ($users as $user) {
                    $ratings->recomputeForUser($user->id);
                    $count++;
                }
            });

        $this->info("Recomputed ratings for {$count} users.");

        return self::SUCCESS;
    }
}
