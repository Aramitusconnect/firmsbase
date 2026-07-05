<?php

namespace App\Enums;

enum EmailMessageDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
