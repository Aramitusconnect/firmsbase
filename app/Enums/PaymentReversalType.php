<?php

namespace App\Enums;

enum PaymentReversalType: string
{
    case Refund = 'refund';
    case Chargeback = 'chargeback';
}
