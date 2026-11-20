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
        $items = [];
        $refsByJob = [];
        foreach ($jobs as $i => [$event]) {
            $mapped = $this->mapper->map($event);
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
            foreach ($jobs as [, $ack]) {
                $ack->nack(new RecoverableMessageHandlingException($e->getMessage(), previous: $e, forceRetry: false));
            }

            return;
        }

        $rejected = array_flip($outcome->rejected);
        $retryable = array_flip($outcome->retryable);

        foreach ($jobs as $i => [$event, $ack]) {
            $refs = $refsByJob[$i] ?? [];

            if ($bad = array_values(array_filter($refs, fn (string $r) => isset($rejected[$r])))) {
                $ack->nack(new UnrecoverableMessageHandlingException(\sprintf('Elasticsearch odrzucił dokument analityki (%s): %s', $event->type, implode(', ', $bad))));
            } elseif ($again = array_values(array_filter($refs, fn (string $r) => isset($retryable[$r])))) {
                $ack->nack(new RecoverableMessageHandlingException(\sprintf('Elasticsearch chwilowo nie przyjął dokumentu analityki (%s): %s', $event->type, implode(', ', $again)), forceRetry: false));
            } else {
                $ack->ack();
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
