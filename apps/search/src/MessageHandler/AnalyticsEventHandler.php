<?php

namespace App\MessageHandler;

use App\Analytics\AnalyticsBulkWriter;
use App\Analytics\AnalyticsDocumentMapper;
use App\Analytics\BulkOutcome;
use App\Message\IntegrationEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Handler\Acknowledger;
use Symfony\Component\Messenger\Handler\BatchHandlerInterface;
use Symfony\Component\Messenger\Handler\BatchHandlerTrait;

/**
 * Konsument kolejki `search.analytics.ingest` (ETAP 12, spec sekcja 5).
 *
 * Zbiera wiadomości w paczki po 500 i wysyła jednym `_bulk` do data streamów.
 * Paczka spływa, gdy jest pełna albo gdy worker nie ma więcej wiadomości
 * (idle timeout z BatchHandlerTrait). Deduplikacja = 409 na `create`.
 *
 * Wynik per wiadomość: sukces i duplikat -> ack; błąd mapowania ->
 * UnrecoverableMessageHandlingException (od razu `failed`, bez retry);
 * 429/5xx -> RecoverableMessageHandlingException (retry Messengera).
 * Odrzucony impression psuje wiadomość `user.searched`, z której powstał.
 */
#[AsMessageHandler(fromTransport: 'analytics')]
final class AnalyticsEventHandler implements BatchHandlerInterface
{
    use BatchHandlerTrait;

    public function __construct(
        private readonly AnalyticsDocumentMapper $mapper,
        private readonly AnalyticsBulkWriter $writer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(IntegrationEvent $event, ?Acknowledger $ack = null): mixed
    {
        return $this->handle($event, $ack);
    }

    /**
     * @param  list<array{0: IntegrationEvent, 1: Acknowledger}>  $jobs
     */
    private function process(array $jobs): void
    {
        // Każdy job dostaje dokładnie jedno ack/nack na KAŻDEJ ścieżce: trait opróżnił już
        // $this->jobs, a nieużyty Acknowledger rzuca LogicException w destruktorze.
        $done = [];
        $settle = function (int $i, ?\Throwable $nack) use ($jobs, &$done): void {
            if (isset($done[$i])) {
                return;
            }
            $done[$i] = true;
            $nack === null ? $jobs[$i][1]->ack() : $jobs[$i][1]->nack($nack);
        };

        try {
            $this->processJobs($jobs, $settle);
        } catch (\Throwable $e) {
            // Ostatnia deska ratunku: cokolwiek poleci po drodze, niezałatwione wiadomości wracają do retry.
            $this->logger->error('Nieoczekiwany błąd przetwarzania paczki analityki.', ['batch' => \count($jobs), 'error' => $e->getMessage()]);
            foreach (array_keys($jobs) as $i) {
                $settle($i, new RecoverableMessageHandlingException($e->getMessage(), previous: $e, forceRetry: false));
            }
        }
    }

    /**
     * @param  list<array{0: IntegrationEvent, 1: Acknowledger}>  $jobs
     * @param  \Closure(int, ?\Throwable): void  $settle
     */
    private function processJobs(array $jobs, \Closure $settle): void
    {
        $items = [];
        $refsByJob = [];
        foreach ($jobs as $i => [$event]) {
            try {
                $mapped = $this->mapper->map($event);
            } catch (\Throwable $e) {
                // Zniekształcone `data` (np. tablica tam, gdzie miał być skalar): ponawianie nic nie zmieni.
                $this->logger->warning('Nie udało się zmapować zdarzenia analityki.', ['event_id' => $event->id, 'type' => $event->type, 'error' => $e->getMessage()]);
                $settle($i, new UnrecoverableMessageHandlingException('Nie udało się zmapować zdarzenia analityki: '.$e->getMessage(), previous: $e));

                continue;
            }
            if ($mapped === []) {
                $this->logger->debug('Zdarzenie spoza katalogu analityki, pomijam.', ['event_id' => $event->id, 'type' => $event->type]);
            }
            foreach ($mapped as $item) {
                $items[] = $item;
                $refsByJob[$i][] = BulkOutcome::ref($item['index'], $item['id']);
            }
        }

        try {
            $outcome = $this->writer->write($items);
        } catch (\Throwable $e) {
            // Błąd całego żądania (sieć, 5xx, zła odpowiedź): cała paczka wraca do retry;
            // duplikaty z ponowienia odsieje 409. forceRetry: false — patrz RUNBOOK #027.
            $this->logger->warning('Zapis paczki analityki nie powiódł się.', ['batch' => \count($jobs), 'error' => $e->getMessage()]);
            foreach (array_keys($jobs) as $i) {
                $settle($i, new RecoverableMessageHandlingException($e->getMessage(), previous: $e, forceRetry: false));
            }

            return;
        }

        $rejected = array_flip($outcome->rejected);
        $retryable = array_flip($outcome->retryable);

        foreach ($jobs as $i => [$event]) {
            $refs = $refsByJob[$i] ?? [];

            if ($bad = array_values(array_filter($refs, fn (string $r) => isset($rejected[$r])))) {
                // Ref + błąd ES (`type: reason`), żeby z `messenger:failed:show` było widać przyczynę.
                $reasons = array_map(fn (string $r) => isset($outcome->rejectedErrors[$r]) ? "{$r} ({$outcome->rejectedErrors[$r]})" : $r, $bad);
                $settle($i, new UnrecoverableMessageHandlingException(\sprintf('Elasticsearch odrzucił dokument analityki (%s): %s', $event->type, implode(', ', $reasons))));
            } elseif ($again = array_values(array_filter($refs, fn (string $r) => isset($retryable[$r])))) {
                $settle($i, new RecoverableMessageHandlingException(\sprintf('Elasticsearch chwilowo nie przyjął dokumentu analityki (%s): %s', $event->type, implode(', ', $again)), forceRetry: false));
            } else {
                $settle($i, null);
            }
        }

        $this->logger->info('Zapisano paczkę zdarzeń analityki.', [
            'messages' => \count($jobs),
            'created' => $outcome->created,
            'duplicates' => $outcome->duplicates,
            'rejected' => \count($outcome->rejected),
            'retryable' => \count($outcome->retryable),
        ]);
    }

    private function getBatchSize(): int
    {
        return 500;
    }
}
