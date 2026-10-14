<?php

namespace App\Enums;

/**
 * When the per-order fee is taken on a Tezkor request. Frozen on the order at
 * creation, so an admin switching the setting never charges an in-flight
 * request twice (or not at all).
 */
enum TezkorBilling: string
{
    /** The agent pays to send the otklik (the client picks among payers). */
    case PayToRespond = 'pay_to_respond';
    /** Otklik is free; the agent the client picked pays to take the job. */
    case PayOnPick = 'pay_on_pick';
}
