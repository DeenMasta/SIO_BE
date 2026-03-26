<?php

namespace App\Domain\ReportingAudit\Enums;

enum AuditAction: string
{
    case Create = 'CREATE';
    case Update = 'UPDATE';
    case Delete = 'DELETE';
    case Post = 'POST';
    case Cancel = 'CANCEL';
    case Login = 'LOGIN';
    case Export = 'EXPORT';
}
