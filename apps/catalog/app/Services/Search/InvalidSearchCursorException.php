<?php

namespace App\Services\Search;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Kursor stronicowania (`?cursor=`) nie dał się odczytać — ucięty przy
 * kopiowaniu linku, ręcznie zmieniony albo po prostu śmieci.
 *
 * Dziedziczy po `BadRequestHttpException` świadomie: to jedyne miejsce, gdzie
 * serwis wyszukiwania "wie" o HTTP — ale kursor JEST danymi wejściowymi
 * z żądania, a Laravel renderuje HttpException jako właściwy status (400) dla
 * Inertii i dla JSON-a bez żadnej dodatkowej konfiguracji. Alternatywa
 * (własny handler w bootstrap/app.php) to więcej kodu dla tego samego efektu.
 */
final class InvalidSearchCursorException extends BadRequestHttpException
{
    public function __construct()
    {
        parent::__construct('Nieprawidłowy kursor stronicowania — zacznij wyszukiwanie od nowa.');
    }
}
