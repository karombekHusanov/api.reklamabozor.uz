<?php

namespace App\Enums;

enum OrderDeadline: string
{
    /** Needs to be done today or tomorrow (urgent). */
    case TodayTomorrow = 'today_tomorrow';

    /** Needs to be done within this week. */
    case ThisWeek = 'this_week';
}
