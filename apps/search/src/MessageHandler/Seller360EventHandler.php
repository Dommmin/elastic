<?php

namespace App\MessageHandler;

use App\Message\IntegrationEvent;
use App\Seller360\RegionResolver;
use App\Seller360\SectionMeta;
use App\Seller360\SectionWriter;
use App\Seller360\Seller360Metrics;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Projektor seller-360 (ETAP 13): konsument kolejki `search.seller360` (`seller.#`, `billing.#`, `offer.#`).
 *
 *  - `seller.*`  -> sekcja `profile` + pola dokumentu `nip`, `region`, `synthetic`;
 *  - `billing.*` -> sekcja `billing` ze snapshotu salda;
 *  - `offer.*`   -> tylko `SADD seller360:dirty:offers <seller_id>`; sekcję `offers` przelicza później
 *                   osobne polecenie, bo zdarzeń ofert jest za dużo na zapis każdego z nich;
 *  - reszta      -> ostrzeżenie w logu i ack.
 *
 * Idempotencja siedzi w strażniku wersji skryptu ES (stara lub powtórzona wersja to `noop`),
 * więc nie ma tu tabeli processed_events. Błąd 4xx z ES (np. mapowanie strict) ponowienie
 * nie naprawi: wiadomość od razu idzie do `failed`. Reszta błędów wraca do retry Messengera.
 */
#[AsMessageHandler(fromTransport: 'seller360')]
final class Seller360EventHandler
{
    public const DIRTY_OFFERS_KEY = 'seller360:dirty:offers';

    public function __construct(
        private readonly SectionWriter $writer,
        private readonly RegionResolver $regions,
        private readonly Seller360Metrics $metrics,
        private readonly \Redis $redis,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(IntegrationEvent $event): void
    {
        $kind = strstr($event->type, '.', true);

        match ($kind) {
            'seller' => $this->guard(fn () => $this->profile($event)),
            'billing' => $this->guard(fn () => $this->billing($event)),
            'offer' => $this->offer($event),
            default => $this->logger->warning('Nieznany typ zdarzenia w kolejce seller360, pomijam.', ['event_id' => $event->id, 'type' => $event->type]),
        };
    }

    private function profile(IntegrationEvent $event): void
    {
        $d = $event->data;
        $sellerId = $this->sellerId($event);
        $meta = $this->meta($event);

        $region = $this->regions->forCity((string) ($d['city'] ?? ''));
        if ($region === null) {
            $this->metrics->regionUnknown();
        }

        $this->writer->apply(
            $sellerId,
            'profile',
            [
                'name' => $d['name'] ?? null,
                'city' => $d['city'] ?? null,
                'status' => $d['status'] ?? null,
                'status_reason' => $d['status_reason'] ?? null,
            ],
            $meta,
            ['nip' => $d['nip'] ?? null, 'region' => $region, 'synthetic' => (bool) ($d['synthetic'] ?? false)],
        );
    }

    private function billing(IntegrationEvent $event): void
    {
        $d = $event->data;

        $this->writer->apply(
            $this->sellerId($event),
            'billing',
            [
                'balance_cents' => $d['balance_cents'] ?? 0,
                'overdue_cents' => $d['overdue_cents'] ?? 0,
                'overdue_since' => $d['overdue_since'] ?? null,
                'open_invoices' => $d['open_invoices'] ?? 0,
                'last_invoice_id' => isset($d['invoice_id']) ? (string) $d['invoice_id'] : null,
            ],
            $this->meta($event),
        );
    }

    private function offer(IntegrationEvent $event): void
    {
        $this->redis->sAdd(self::DIRTY_OFFERS_KEY, (string) $this->sellerId($event));
        $this->metrics->offerEvent();
    }

    private function sellerId(IntegrationEvent $event): int
    {
        $id = $event->data['seller_id'] ?? null;
        if (! \is_int($id) && ! (\is_string($id) && ctype_digit($id))) {
            throw new UnrecoverableMessageHandlingException("Zdarzenie {$event->type} ({$event->id}) bez poprawnego seller_id.");
        }

        return (int) $id;
    }

    private function meta(IntegrationEvent $event): SectionMeta
    {
        $version = $event->data['version'] ?? null;
        if (! \is_int($version)) {
            throw new UnrecoverableMessageHandlingException("Zdarzenie {$event->type} ({$event->id}) bez liczbowej wersji w ładunku.");
        }

        $occurredAt = $event->data['occurred_at'] ?? null;
        try {
            $sourceTs = \is_string($occurredAt) ? new \DateTimeImmutable($occurredAt) : $event->occurredAt;
        } catch (\Exception) {
            $sourceTs = $event->occurredAt;
        }

        return new SectionMeta($version, $sourceTs);
    }

    private function guard(\Closure $write): void
    {
        try {
            $write();
        } catch (ClientResponseException $e) {
            $status = $e->getResponse()->getStatusCode();
            if ($status === 429 || $status === 409) {
                throw $e;
            }
            throw new UnrecoverableMessageHandlingException('Elasticsearch odrzucił zapis sekcji seller-360: '.$e->getMessage(), previous: $e);
        }
    }
}
