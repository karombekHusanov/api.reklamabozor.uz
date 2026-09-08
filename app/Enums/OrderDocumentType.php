<?php

namespace App\Enums;

/**
 * Accounting documents an order produces once the work is done.
 */
enum OrderDocumentType: string
{
    /** Client ↔ agent: the services were rendered and accepted. */
    case WorkAct = 'work_act';

    /** Platform ↔ agent: the intermediary fee withheld from the payout. */
    case CommissionAct = 'commission_act';

    public function label(): string
    {
        return match ($this) {
            self::WorkAct => 'Bajarilgan ishlar dalolatnomasi',
            self::CommissionAct => 'Vositachilik xizmati dalolatnomasi',
        };
    }

    /** Suffix appended to the order's contract number. */
    public function numberSuffix(): string
    {
        return match ($this) {
            self::WorkAct => 'ACT',
            self::CommissionAct => 'KOM',
        };
    }
}
