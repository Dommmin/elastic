<?php

namespace App\Service;

/**
 * Catalog API (Laravel) nie odpowiedziało poprawnie — 5xx albo sieć/timeout.
 * To błąd PRZEJŚCIOWY: handler zamienia go na
 * RecoverableMessageHandlingException, więc Messenger ponowi próbę zgodnie
 * z retry_strategy z messenger.yaml, zamiast wysyłać od razu do DLQ.
 */
final class ProjectionUnavailableException extends \RuntimeException
{
}
