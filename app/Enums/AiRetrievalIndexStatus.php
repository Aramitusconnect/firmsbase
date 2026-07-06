<?php

namespace App\Enums;

enum AiRetrievalIndexStatus: string
{
    case Provisioned = 'provisioned';
    case Disabled = 'disabled';
}
