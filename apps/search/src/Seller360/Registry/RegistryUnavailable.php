<?php

namespace App\Seller360\Registry;

/** Rejestr zewnętrzny nie odpowiedział poprawnie (timeout, 5xx, zepsuta odpowiedź) po wszystkich ponowieniach. */
final class RegistryUnavailable extends \RuntimeException
{
}
