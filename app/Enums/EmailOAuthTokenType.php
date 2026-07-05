<?php

namespace App\Enums;

enum EmailOAuthTokenType: string
{
    case AccessToken = 'access_token';
    case RefreshToken = 'refresh_token';
}
