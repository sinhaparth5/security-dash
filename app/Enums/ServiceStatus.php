<?php

namespace App\Enums;

enum ServiceStatus: string
{
    case Green = 'green';
    case Yellow = 'yellow';
    case Red = 'red';
    case Unknown = 'unknown';
}
