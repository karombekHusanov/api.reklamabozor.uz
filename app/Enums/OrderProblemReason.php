<?php

namespace App\Enums;

/**
 * Why an order was flagged into the problem-orders queue.
 */
enum OrderProblemReason: string
{
    /** Client disputed the delivered work and the correction window elapsed. */
    case QualityUnresolved = 'quality_unresolved';
    /** Deal is paid and running, but the agent never reported starting the work. */
    case AgentNoStart = 'agent_no_start';
}
