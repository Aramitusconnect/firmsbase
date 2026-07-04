<?php

namespace App\Enums;

enum ConsentChannel: string
{
    case Email = 'email';
    case Sms = 'sms';
    case WhatsApp = 'whatsapp';
    case Portal = 'portal';
}
