<?php

namespace App\Console\Commands;

use App\Support\ErrorTracking\ErrorsSmokeJob;
use App\Support\ErrorTracking\SmokeMarker;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Wywołuje błąd NA ŻYCZENIE, żeby `make errors-smoke` sprawdziło drogę do GlitchTipa
 * z komendy i z kolejki. Tylko w APP_ENV=local.
 */
class ErrorsSmokeCommand extends Command
{
    protected $signature = 'errors:smoke {marker : Znacznik szukany potem w GlitchTipie} {--queue : Rzuć z zadania kolejki zamiast z komendy}';

    protected $description = 'Wywołuje testowy błąd (tylko local), żeby sprawdzić drogę do GlitchTipa';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('errors:smoke działa tylko w APP_ENV=local.');

            return self::FAILURE;
        }

        $marker = SmokeMarker::clean($this->argument('marker'));

        if ($this->option('queue')) {
            dispatch(new ErrorsSmokeJob($marker));
            $this->info("Zadanie errors-smoke:queue:{$marker} wrzucone do kolejki.");

            return self::SUCCESS;
        }

        throw new RuntimeException("errors-smoke:cli:{$marker}");
    }
}
