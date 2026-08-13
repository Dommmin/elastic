<?php

namespace App\Service;

enum IndexOutcome
{
    case Indexed;
    case Stale;
}
