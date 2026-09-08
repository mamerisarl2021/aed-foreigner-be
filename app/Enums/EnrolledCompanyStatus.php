<?php

declare(strict_types=1);

namespace App\Enums;

enum EnrolledCompanyStatus: string
{
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Revoked = 'REVOKED';
}
