<?php

namespace App\Enums;

enum Ecosystem: string
{
    case Go = 'go';
    case Npm = 'npm';
    case Runtime = 'runtime';
    case Image = 'image';
}
