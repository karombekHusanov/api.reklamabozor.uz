<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum writes `last_used_at` on every authenticated request. Once presence
 * runs on Centrifugo nothing reads it, so that per-request UPDATE is skipped
 * (the legacy online heuristic needs it only while realtime is off).
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    public function save(array $options = []): bool
    {
        if ($this->exists
            && config('realtime.enabled')
            && array_keys($this->getDirty()) === ['last_used_at']) {
            $this->syncOriginal();

            return true;
        }

        return parent::save($options);
    }
}
