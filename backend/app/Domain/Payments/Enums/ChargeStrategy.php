<?php

namespace App\Domain\Payments\Enums;

enum ChargeStrategy: string
{
    case DestinationCharge = 'destination_charge';
    case SeparateChargeAndTransfer = 'separate_charge_and_transfer';
    case DirectCharge = 'direct_charge';
}
