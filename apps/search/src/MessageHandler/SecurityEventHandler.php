<?php

namespace App\MessageHandler;

use App\Analytics\AnalyticsBulkWriter;
use App\Analytics\BulkOutcome;
use App\Message\IntegrationEvent;
use App\Security\EventPercolator;
use App\Security\SecurityDocumentMapper;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Handler\Acknowledger;
use Symfony\Component\Messenger\Handler\BatchHandlerInterface;
use Symfony\Component\Messenger\Handler\BatchHandlerTrait;

/**
 * Konsument kolejki `search.security.ingest` (ETAP 15): `offer.price_changed` i `review.created`
 * do data streamu `events-security-default`. Ta sama mechanika co {@see AnalyticsEventHandler}:
 * paczki po 500 albo do idle, `create` z `_id` = id koperty (409 = duplikat = ack), błąd mapowania
 * to Unrecoverable, 429/5xx to Recoverable (`forceRetry: false`, RUNBOOK #027).
 *
 * Po udanym zapisie paczki wołamy {@see EventPercolator} na dokumentach, które trafiły do ES
 * (utworzone albo już istniejące: powtórka po nieudanej percolacji nadrobi alert, a klucz alertu
 * i tak deduplikuje). Błąd percolacji tylko logujemy: zdarzenie jest już zapisane, więc ack zostaje.
 */
#[AsMessageHandler(fromTransport: 'security')]
final class SecurityEventHandler implements BatchHandlerInterface
{
    use BatchHandlerTrait;

    public function __construct(
        private readonly SecurityDocumentMapper $mapper,
        private readonly AnalyticsBulkWriter $writer,
        private readonly EventPercolator $percolator,
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
        // Dokładnie jedno ack/nack na KAŻDEJ ścieżce (nieużyty Acknowledger rzuca w destruktorze).
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
            $this->logger->error('Nieoczekiwany błąd przetwarzania paczki zdarzeń bezpieczeństwa.', ['batch' => \count($jobs), 'error' => $e->getMessage()]);
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
                $this->logger->warning('Nie udało się zmapować zdarzenia bezpieczeństwa.', ['event_id' => $event->id, 'type' => $event->type, 'error' => $e->getMessage()]);
                $settle($i, new UnrecoverableMessageHandlingException('Nie udało się zmapować zdarzenia bezpieczeństwa: '.$e->getMessage(), previous: $e));

                continue;
            }
            if ($mapped === []) {
                $this->logger->debug('Zdarzenie spoza katalogu bezpieczeństwa, pomijam.', ['event_id' => $event->id, 'type' => $event->type]);
            }
            foreach ($mapped as $item) {
                $items[] = $item;
                $refsByJob[$i][] = BulkOutcome::ref($item['index'], $item['id']);
            }
        }

        try {
            $outcome = $this->writer->write($items);
        } catch (\Throwable $e) {
            $this->logger->warning('Zapis paczki zdarzeń bezpieczeństwa nie powiódł się.', ['batch' => \count($jobs), 'error' => $e->getMessage()]);
            foreach (array_keys($jobs) as $i) {
                $settle($i, new RecoverableMessageHandlingException($e->getMessage(), previous: $e, forceRetry: false));
            }

            return;
        }

        $rejected = array_flip($outcome->rejected);
        $retryable = array_flip($outcome->retryable);
        $stored = [];

        foreach ($jobs as $i => [$event]) {
            $refs = $refsByJob[$i] ?? [];

            if ($bad = array_values(array_filter($refs, fn (string $r) => isset($rejected[$r])))) {
                $reasons = array_map(fn (string $r) => isset($outcome->rejectedErrors[$r]) ? "{$r} ({$outcome->rejectedErrors[$r]})" : $r, $bad);
                $settle($i, new UnrecoverableMessageHandlingException(\sprintf('Elasticsearch odrzucił dokument bezpieczeństwa (%s): %s', $event->type, implode(', ', $reasons))));
            } elseif ($again = array_values(array_filter($refs, fn (string $r) => isset($retryable[$r])))) {
                $settle($i, new RecoverableMessageHandlingException(\sprintf('Elasticsearch chwilowo nie przyjął dokumentu bezpieczeństwa (%s): %s', $event->type, implode(', ', $again)), forceRetry: false));
            } else {
                array_push($stored, ...$refs);
                $settle($i, null);
            }
        }

        $this->logger->info('Zapisano paczkę zdarzeń bezpieczeństwa.', [
            'messages' => \count($jobs),
            'created' => $outcome->created,
            'duplicates' => $outcome->duplicates,
            'rejected' => \count($outcome->rejected),
            'retryable' => \count($outcome->retryable),
        ]);

        if ($stored === []) {
            return;
        }
        try {
            $alerts = $this->percolator->percolate($stored);
            $this->logger->info('Percolacja zdarzeń bezpieczeństwa.', ['documents' => \count($stored), 'alerts' => $alerts]);
        } catch (\Throwable $e) {
            // Zdarzenie już jest w ES, więc ack zostaje. Przegapiony alert nadrobi `security:detect`.
            $this->logger->error('Percolacja zdarzeń bezpieczeństwa nie powiodła się.', ['documents' => \count($stored), 'error' => $e->getMessage()]);
        }
    }

    private function getBatchSize(): int
    {
        return 500;
    }
}
