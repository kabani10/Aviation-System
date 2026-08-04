<?php

namespace App\Domain\Tenancy\Actions\TwoFactor;

use RuntimeException;

class InvalidTwoFactorCodeException extends RuntimeException
{
    protected $message = 'That code is invalid or has expired.';
}
