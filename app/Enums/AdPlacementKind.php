<?php

namespace App\Enums;

/** Physical type of an advertising placement shown on the map. */
enum AdPlacementKind: string
{
    case LedScreen = 'led_screen';
    case Billboard = 'billboard';
    case Citylight = 'citylight';
    case Other = 'other';
}
