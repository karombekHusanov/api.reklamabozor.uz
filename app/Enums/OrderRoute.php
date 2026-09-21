<?php

namespace App\Enums;

/**
 * Which rule set an order runs on, fixed by the client at creation time.
 *  - tender: many priced offers, contract, platform-collected payment.
 *  - tezkor: one agent claims it; the parties agree outside the platform.
 */
enum OrderRoute: string
{
    case Tender = 'tender';
    case Tezkor = 'tezkor';
}
