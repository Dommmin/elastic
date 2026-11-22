<?php

namespace App\Seller360;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Okresowe zadanie procesu `seller360:scheduler`. Nowe zadanie (kurier co 60 s, lag co 30 s)
 * to jedna klasa z tym interfejsem: autokonfiguracja dopisuje ją do iteratora harmonogramu.
 */
#[AutoconfigureTag('app.seller360_job')]
interface Seller360Job
{
    public function name(): string;

    public function intervalSeconds(): int;

    public function run(): void;
}
