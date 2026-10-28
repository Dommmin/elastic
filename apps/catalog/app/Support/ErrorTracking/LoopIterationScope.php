<?php

namespace App\Support\ErrorTracking;

use Sentry\State\Scope;

use function Sentry\configureScope;

/**
 * Granica iteracji dla długo żyjących pętli (`outbox:publish --loop`).
 *
 * W żądaniu HTTP i w zadaniu kolejki SDK samo zaczyna od czystego scope'u. Pętla, która
 * żyje godzinami, takiej granicy nie ma, więc breadcrumbs (SQL pustego outboxu co sekundę,
 * logi) zalewają limit i wypychają z błędu kontekst partii, w której naprawdę coś padło.
 * Czyścimy je na początku każdej iteracji; tagi procesu zostają.
 */
final class LoopIterationScope
{
    public static function reset(): void
    {
        configureScope(static function (Scope $scope): void {
            $scope->clearBreadcrumbs();
        });
    }
}
