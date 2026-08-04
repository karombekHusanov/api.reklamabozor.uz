<?php

namespace App\Jobs;

use App\Services\Rating\RatingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecalculateRating implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $userId,
    ) {}

    public function handle(RatingService $ratings): void
    {
        $ratings->recomputeForUser($this->userId);
    }
}
