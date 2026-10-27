<?php

namespace App\Console\Commands;

use App\Models\Outbox;
use App\Support\ErrorTracking\LoopIterationScope;
use App\Support\Outbox\OutboxMessageFactory;
use App\Support\Tracing\TraceId;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Publikator Transactional Outbox (docs/05-SPOJNOSC-DANYCH.md, sekcja 3).
 *
 * `php artisan outbox:publish`         — jeden przebieg (np. z crona)
 * `php artisan outbox:publish --loop`  — długo żyjący proces (kontener `catalog-outbox`)
 *
 * GWARANCJA: wiersz w `outbox` zostaje oznaczony jako opublikowany DOPIERO
 * po potwierdzeniu przez broker (publisher confirm), nie po samym
 * `basic_publish`. To jest różnica między "wysłałem" a "broker to przyjął"
 * — bez tego rozróżnienia można zgubić wiadomość przy zerwanym połączeniu.
 *
 * UWAGA architektoniczna, świadomie zaakceptowana: `FOR UPDATE SKIP LOCKED`
 * trzyma blokadę wierszy Postgresa przez CAŁY czas oczekiwania na potwierdzenia
 * z RabbitMQ (sieć lokalna, więc to pojedyncze milisekundy). Przy bardzo dużym
 * wolumenie rozdzieliłoby się to na dwa etapy (rezerwacja + publikacja), ale
 * dla tego projektu prostota jednej transakcji jest warta tego kompromisu —
 * i tak można uruchomić kilka instancji tej komendy równolegle (SKIP LOCKED).
 */
class PublishOutboxCommand extends Command
{
    protected $signature = 'outbox:publish
        {--loop : Nie kończ po jednej partii — czekaj na kolejne zdarzenia}
        {--batch=500 : Ile wierszy pobrać na raz}
        {--sleep=1 : Sekund odczekania, gdy outbox jest pusty (tylko z --loop)}
        {--timeout=5 : Sekund oczekiwania na potwierdzenie brokera}';

    protected $description = 'Publikuje niewysłane zdarzenia z tabeli outbox do RabbitMQ';

    private bool $shouldStop = false;

    public function handle(): int
    {
        $this->trap([SIGTERM, SIGINT], function () {
            $this->shouldStop = true;
            $this->info('Otrzymano sygnał zatrzymania — kończę po bieżącej partii...');
        });

        $connection = new AMQPStreamConnection(
            config('services.rabbitmq.host'),
            config('services.rabbitmq.port'),
            config('services.rabbitmq.user'),
            config('services.rabbitmq.password'),
            config('services.rabbitmq.vhost'),
        );
        $channel = $connection->channel();
        // Bez tego basic_publish to "wyślij i zapomnij" — nie wiadomo,
        // czy broker w ogóle przyjął wiadomość (docs/05, sekcja 3, [2]).
        $channel->confirm_select();

        $batchSize = (int) $this->option('batch');
        $timeout = (int) $this->option('timeout');
        $loop = (bool) $this->option('loop');
        $sleepSeconds = (int) $this->option('sleep');

        do {
            LoopIterationScope::reset();
            $published = $this->publishBatch($channel, $batchSize, $timeout);

            if ($published > 0) {
                $this->info("Opublikowano {$published} zdarzeń.");
            } elseif ($loop && ! $this->shouldStop) {
                sleep($sleepSeconds);
            }
        } while ($loop && ! $this->shouldStop);

        $channel->close();
        $connection->close();

        return self::SUCCESS;
    }

    private function publishBatch(AMQPChannel $channel, int $batchSize, int $timeout): int
    {
        return DB::transaction(function () use ($channel, $batchSize, $timeout) {
            $rows = Outbox::whereNull('published_at')
                ->orderBy('id')
                ->limit($batchSize)
                ->lock('for update skip locked')
                ->get();

            if ($rows->isEmpty()) {
                return 0;
            }

            $confirmedIds = [];
            $messageToRowId = [];

            // Handlery są wywoływane synchronicznie wewnątrz wait_for_pending_acks()
            // poniżej — to nie jest osobny wątek, więc bezpiecznie domykają
            // zmienne z tego zakresu przez referencję.
            $channel->set_ack_handler(function (AMQPMessage $msg) use (&$confirmedIds, &$messageToRowId) {
                $key = spl_object_id($msg);
                if (isset($messageToRowId[$key])) {
                    $confirmedIds[] = $messageToRowId[$key];
                }
            });

            $channel->set_nack_handler(function (AMQPMessage $msg) use (&$messageToRowId) {
                $rowId = $messageToRowId[spl_object_id($msg)] ?? '?';
                // NIE oznaczamy jako opublikowane — zostanie podjęte ponownie
                // w kolejnej partii. Broker odrzucający potwierdzenie to
                // rzadkie zdarzenie (np. brak miejsca na dysku brokera).
                $this->warn("Broker odrzucił (nack) outbox#{$rowId} — spróbuję ponownie.");
            });

            foreach ($rows as $row) {
                $message = OutboxMessageFactory::make($row);

                $messageToRowId[spl_object_id($message)] = $row->id;

                $channel->basic_publish(
                    $message,
                    config('services.rabbitmq.events_exchange'),
                    $row->event_type,
                );

                // Log z trace_id wiersza (nie publishera), żeby wysyłka
                // wpadła w ten sam ślad co żądanie, które utworzyło zdarzenie.
                // Niepotwierdzone zdarzenie wróci z tym samym trace_id.
                if ($row->trace_id !== null) {
                    Context::add(TraceId::CONTEXT_KEY, $row->trace_id);
                }
                try {
                    Log::debug('Opublikowano zdarzenie do RabbitMQ', [
                        'event_id' => $row->event_id,
                        'type' => $row->event_type,
                        'aggregate_id' => $row->aggregate_id,
                    ]);
                } finally {
                    Context::forget(TraceId::CONTEXT_KEY);
                }
            }

            try {
                $channel->wait_for_pending_acks($timeout);
            } catch (AMQPTimeoutException) {
                // Oczekiwany retry w kolejnej partii, nie awaria: bez report(), więc bez
                // issue w GlitchTipie. Wyjątek, który zatrzyma pętlę, zgłosi handler konsoli.
                $this->warn('Timeout oczekiwania na potwierdzenia — część wiadomości spróbuję ponownie w kolejnej partii.');
            }

            if ($confirmedIds !== []) {
                Outbox::whereIn('id', $confirmedIds)->update(['published_at' => now()]);
            }

            return count($confirmedIds);
        });
    }
}
