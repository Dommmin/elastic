<?php

namespace App\Service;

enum IndexOutcome
{
    case Indexed;
    /** 409 — w ES jest już nowsza wersja (albo tombstone nowszego usunięcia). */
    case Stale;
    /** 404 przy usuwaniu — dokumentu nie było (nigdy albo już usunięty). */
    case NotFound;
}
