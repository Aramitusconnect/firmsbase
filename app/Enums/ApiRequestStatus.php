<?php

namespace App\Enums;

enum ApiRequestStatus: string
{
    case Success = 'success';
    case Failed = 'failed';
    case RateLimited = 'rate_limited';
    case Unauthorized = 'unauthorized';
    case Forbidden = 'forbidden';
}
