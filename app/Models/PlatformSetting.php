<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = ['key', 'value'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
