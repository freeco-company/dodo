<?php

namespace App\Services;

enum IdentifierKind: string
{
    case Uuid = 'uuid';
    case Email = 'email';
}
