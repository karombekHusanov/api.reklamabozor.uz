<?php

namespace App\Enums;

enum PassMode: string
{
    case DailyPass = 'daily_pass';
    case PerResponse = 'per_response';
}
