<?php

namespace Ordering\Domain;

use DateInterval;
use DateTimeImmutable;
use Ordering\Domain\Event\LatePaymentReceived;
use Ordering\Domain\Event\OrderAwaitingPayment;
use Ordering\Domain\Event\OrderCancelled;
use Ordering\Domain\Event\OrderExpired;
use Ordering\Domain\Event\OrderLineReserved;
use Ordering\Domain\Event\OrderPaid;
use Ordering\Domain\Event\OrderPaymentFailed;
use Ordering\Domain\Event\OrderPlaced;
use Ordering\Domain\Event\OrderRejected;
use Ordering\Domain\Event\OrderShipped;
use Ordering\Domain\Event\RefundCompleted;
use Ordering\Domain\Event\RefundRequested;
use SharedKernel\AggregateRoot;
use SharedKernel\Money;

/**
 * Agregat zamówienia: pozycje jednego sprzedawcy z zamrożoną ceną, termin
 * płatności, wpłata i zwroty.
 *
 * Maszyna stanów:
 *   Placed --wszystkie pozycje zarezerwowane--> AwaitingPayment --wpłata--> Paid --wysyłka--> Shipped
 *   Placed --odmowa rezerwacji--> Rejected
 *   Placed | AwaitingPayment --termin + karencja--> Expired
 *   Placed | AwaitingPayment --kupujący--> Cancelled
 *   Paid --sprzedawca (+ zwrot całej wpłaty)--> Cancelled
 * Shipped, Rejected, Expired i Cancelled są końcowe dla statusu; zwroty
 * mogą się w nich jeszcze rozliczać.
 *
 * DLACZEGO stan procesu (sagi) żyje w zamówieniu (ADR-0007, process manager
 * ze stanem w zamówieniu): każdy krok procesu jest też stanem biznesowym,
 * który widzi kupujący (`Placed` = „sprawdzamy dostępność”, `AwaitingPayment`
 * = „zapłać do…”). Osobna tabela sagi byłaby drugą kopią tej samej prawdy,
 * a dwie kopie się rozjeżdżają. Process manager tylko reaguje na zdarzenia
 * i wraca do zamówienia z wynikiem (np. `markLineReserved`, `reject`).
 *
 * DLACZEGO `recordPayment` nigdy nie rzuca dla „złego” statusu: pieniądze
 * już przyszły i wyjątek ich nie cofnie. Odmowa zostawiłaby wpłatę bez
 * właściciela (webhook ponawiany w nieskończoność albo zgubiony), więc
 * w każdym statusie agregat albo ją przyjmuje, albo rozpoznaje duplikat,
 * albo zleca kompensację: zwrot (`RefundRequested`) zamiast błędu (H6, H10).
 *
 * DLACZEGO karencja (`WEBHOOK_GRACE`) przy wygaszaniu: bramka nie przyjmie
 * wpłaty po `paymentDeadline`, ale jej webhook może przyjść chwilę później.
 * Sweep czeka jeszcze 5 minut, żeby wpłata przyjęta w terminie nie trafiła
 * w wygaśnięcie i nie skończyła się zwrotem (R3).
 *
 * DLACZEGO domena nadaje `RefundId` sama (`refund-{orderId}-{n}`): to klucz
 * idempotencji w bramce. Losowy identyfikator dawałby nowy klucz przy każdym
 * ponowieniu, a deterministyczny sprawia, że ten sam zwrot zlecony dwa razy
 * to dla bramki jedna operacja. Domena też niczego nie losuje (testy).
 *
 * DLACZEGO sprzedawca i kupujący są tylko ID: to inne agregaty (i inne
 * konteksty). „Jeden sprzedawca na zamówienie” zapewnia składanie z koszyka,
 * które grupuje pozycje po sprzedawcy.
 *
 * DLACZEGO `version` jest tylko odczytywana: należy do optymistycznej
 * blokady w repozytorium. Nowe zamówienie ma wersję 0 (repozytorium
 * rozpoznaje po niej INSERT), jak `Offer::draft`.
 */
final class Order extends AggregateRoot
{
    public const string PAYMENT_WINDOW = 'PT15M';

    public const string WEBHOOK_GRACE = 'PT5M';

    public const int MAX_LINES = 20;

    /**
     * @param  list<OrderLine>  $lines
     * @param  list<Refund>  $refunds
     */
    private function __construct(
        private readonly OrderId $id,
        private readonly BuyerId $buyerId,
        private readonly SellerId $sellerId,
        private array $lines,
        private OrderStatus $status,
        private readonly DateTimeImmutable $placedAt,
        private readonly DateTimeImmutable $paymentDeadline,
        private ?Payment $payment,
        private array $refunds,
        private ?Rejection $rejection,
        private ?Cancellation $cancellation,
        private ?Shipment $shipment,
        private readonly int $version,
    ) {}

    /**
     * Cena w pozycjach jest już zamrożona (H5): sprawdziło ją składanie
     * z koszyka, a rezerwacja sprawdzi jeszcze raz.
     *
     * @param  list<OrderLine>  $lines
     *
     * @throws InvalidOrderLines
     */
    public static function place(OrderId $id, BuyerId $buyerId, SellerId $sellerId, array $lines, DateTimeImmutable $now): self
    {
        self::guardLines($lines);

        $order = new self(
            $id, $buyerId, $sellerId, array_values($lines), OrderStatus::Placed,
            $now, $now->add(new DateInterval(self::PAYMENT_WINDOW)),
            null, [], null, null, null, 0,
        );
        $order->recordThat(new OrderPlaced($id, $buyerId, $sellerId, $order->total(), $now));

        return $order;
    }

    /**
     * Odtworzenie z bazy: bez zdarzeń, bo tylko wczytujemy stan, który już
     * raz został opisany zdarzeniami.
     *
     * @param  list<OrderLine>  $lines
     * @param  list<Refund>  $refunds
     */
    public static function reconstitute(
        OrderId $id,
        BuyerId $buyerId,
        SellerId $sellerId,
        array $lines,
        OrderStatus $status,
        DateTimeImmutable $placedAt,
        DateTimeImmutable $paymentDeadline,
        ?Payment $payment,
        array $refunds,
        ?Rejection $rejection,
        ?Cancellation $cancellation,
        ?Shipment $shipment,
        int $version,
    ): self {
        return new self($id, $buyerId, $sellerId, $lines, $status, $placedAt, $paymentDeadline, $payment, $refunds, $rejection, $cancellation, $shipment, $version);
    }

    /**
     * Krok sagi po udanej rezerwacji w ofercie. Ponowienie dla już
     * zarezerwowanej pozycji to no-op (ponowiony job, H10). Poza `Placed`
     * rzuca: saga sprawdza status przed wołaniem i przy innym statusie sama
     * zwalnia rezerwację (R1), więc wyjątek tu oznacza błąd w sadze.
     *
     * @throws InvalidOrderTransition
     * @throws InvalidOrderLines gdy oferty nie ma w zamówieniu
     */
    public function markLineReserved(OfferId $offerId, DateTimeImmutable $now): void
    {
        $this->requireStatus([OrderStatus::Placed], 'mark_line_reserved');

        $index = $this->lineIndex($offerId);

        if ($this->lines[$index]->isReserved()) {
            return;
        }

        $this->lines[$index] = $this->lines[$index]->reserved();
        $this->recordThat(new OrderLineReserved($this->id, $offerId, $now));

        foreach ($this->lines as $line) {
            if (! $line->isReserved()) {
                return;
            }
        }

        $this->status = OrderStatus::AwaitingPayment;
        $this->recordThat(new OrderAwaitingPayment($this->id, $this->paymentDeadline, $now));
    }

    /**
     * Pierwsza odmowa rezerwacji kończy całe zamówienie: kupujący nie dostaje
     * „połowy” zamówienia, o którą nie prosił. Zwolnienie pozostałych pozycji
     * robi saga po `OrderRejected`.
     *
     * @throws InvalidOrderTransition
     * @throws InvalidOrderLines gdy oferty nie ma w zamówieniu
     */
    public function reject(RejectionReason $reason, OfferId $offerId, DateTimeImmutable $now): void
    {
        $this->requireStatus([OrderStatus::Placed], 'reject');
        $this->lineIndex($offerId);

        $this->status = OrderStatus::Rejected;
        $this->rejection = new Rejection($reason, $offerId);
        $this->recordThat(new OrderRejected($this->id, $reason, $offerId, $now));
    }

    /**
     * Działa w każdym statusie (zob. docblock klasy). Kolejność warunków jest
     * istotna: najpierw duplikaty (no-op), potem jedyna ścieżka przyjęcia,
     * a cała reszta to zwrot tej wpłaty.
     */
    public function recordPayment(PaymentId $paymentId, Money $amount, DateTimeImmutable $now): void
    {
        if ($this->payment !== null && $this->payment->paymentId->equals($paymentId)) {
            return;
        }

        if ($this->hasRefundFor($paymentId)) {
            return;
        }

        if ($this->status === OrderStatus::AwaitingPayment && $amount->equals($this->total())) {
            $this->status = OrderStatus::Paid;
            $this->payment = new Payment($paymentId, $amount, $now);
            $this->recordThat(new OrderPaid($this->id, $paymentId, $amount, $now));

            return;
        }

        $reason = match (true) {
            $this->payment !== null => RefundReason::DuplicatePayment,
            $this->status === OrderStatus::AwaitingPayment => RefundReason::AmountMismatch,
            default => RefundReason::LatePayment,
        };

        $this->recordThat(new LatePaymentReceived($this->id, $paymentId, $amount, $now));
        $this->requestRefund($paymentId, $amount, $reason, $now);
    }

    /**
     * DLACZEGO nieudana płatność nie zmienia statusu (H7): odrzucona karta
     * to nie koniec zamówienia, kupujący może ponowić do terminu. Poza
     * `AwaitingPayment` to no-op: spóźniona informacja o porażce nie ma już
     * czego zmieniać.
     */
    public function recordPaymentFailure(PaymentId $paymentId, DateTimeImmutable $now): void
    {
        if ($this->status !== OrderStatus::AwaitingPayment) {
            return;
        }

        $this->recordThat(new OrderPaymentFailed($this->id, $paymentId, $now));
    }

    /**
     * No-op w innym statusie i przed terminem z karencją: woła ją sweep,
     * który mógł przegrać wyścig z webhookiem (R3) albo wczytać zamówienie
     * z nieświeżego indeksu. Wygaśnięcie dotyczy też `Placed` (R10: saga
     * mogła utknąć przed rezerwacją).
     */
    public function expire(DateTimeImmutable $now): void
    {
        if (! $this->isDueForExpiry($now)) {
            return;
        }

        $this->status = OrderStatus::Expired;
        $this->recordThat(new OrderExpired($this->id, $now));
    }

    /**
     * @throws InvalidOrderTransition
     */
    public function cancelByBuyer(DateTimeImmutable $now): void
    {
        $this->requireStatus([OrderStatus::Placed, OrderStatus::AwaitingPayment], OrderAction::Cancel);

        $this->status = OrderStatus::Cancelled;
        $this->cancellation = new Cancellation(CancelledBy::Buyer, null);
        $this->recordThat(new OrderCancelled($this->id, CancelledBy::Buyer, null, $now));
    }

    /**
     * Anulowanie opłaconego zamówienia oddaje całą wpłatę. Stanu ofert nie
     * zwraca (H8): sprzedawca anuluje zwykle dlatego, że towaru nie ma.
     *
     * @throws InvalidOrderTransition
     */
    public function cancelBySeller(Reason $reason, DateTimeImmutable $now): void
    {
        $this->requireStatus([OrderStatus::Paid], OrderAction::CancelBySeller);

        /** @var Payment $payment `Paid` zawsze ma zaksięgowaną wpłatę */
        $payment = $this->payment;

        $this->status = OrderStatus::Cancelled;
        $this->cancellation = new Cancellation(CancelledBy::Seller, $reason);
        $this->recordThat(new OrderCancelled($this->id, CancelledBy::Seller, $reason, $now));
        $this->requestRefund($payment->paymentId, $payment->amount, RefundReason::CancelledBySeller, $now);
    }

    /**
     * „Wysłać można tylko opłacone”: jedyny niezmiennik sprzedawcy przy wysyłce.
     *
     * @throws InvalidOrderTransition
     */
    public function ship(TrackingNumber $trackingNumber, DateTimeImmutable $now): void
    {
        $this->requireStatus([OrderStatus::Paid], OrderAction::Ship);

        $this->status = OrderStatus::Shipped;
        $this->shipment = new Shipment($trackingNumber, $now);
        $this->recordThat(new OrderShipped($this->id, $trackingNumber, $now));
    }

    /**
     * W dowolnym statusie: zwrot rozlicza się niezależnie od losu zamówienia.
     * Ponowione potwierdzenie to no-op. Nieznany zwrot to błąd, bo saga
     * zleca tylko zwroty, które to zamówienie samo zażądało.
     *
     * @throws InvalidOrderTransition
     */
    public function markRefundCompleted(RefundId $refundId, DateTimeImmutable $now): void
    {
        foreach ($this->refunds as $index => $refund) {
            if (! $refund->refundId->equals($refundId)) {
                continue;
            }

            if ($refund->isCompleted()) {
                return;
            }

            $this->refunds[$index] = $refund->complete($now);
            $this->recordThat(new RefundCompleted($this->id, $refundId, $now));

            return;
        }

        throw InvalidOrderTransition::from($this->status, 'mark_refund_completed');
    }

    /**
     * Strażnik przed otwarciem sesji płatności w bramce: sesja nic nie
     * zapisuje w zamówieniu, ale nie ma sensu płacić za coś, czego zamówienie
     * nie przyjmie.
     *
     * @throws InvalidOrderTransition
     */
    public function requirePayable(): void
    {
        $this->requireStatus([OrderStatus::AwaitingPayment], OrderAction::Pay);
    }

    public function isDueForExpiry(DateTimeImmutable $now): bool
    {
        return in_array($this->status, [OrderStatus::Placed, OrderStatus::AwaitingPayment], true)
            && $now >= $this->paymentDeadline->add(new DateInterval(self::WEBHOOK_GRACE));
    }

    /**
     * Jedyne źródło prawdy dla UI: co wolno zrobić z tym zamówieniem temu
     * aktorowi (jak `Offer::allowedActionsFor`). Kolejność = kolejność
     * przypadków `OrderAction`.
     *
     * @return list<OrderAction>
     */
    public function allowedActionsFor(Actor $actor): array
    {
        $allowed = match ($actor) {
            Actor::Buyer => match ($this->status) {
                OrderStatus::Placed => [OrderAction::Cancel],
                OrderStatus::AwaitingPayment => [OrderAction::Pay, OrderAction::Cancel],
                default => [],
            },
            Actor::Seller => match ($this->status) {
                OrderStatus::Paid => [OrderAction::Ship, OrderAction::CancelBySeller],
                default => [],
            },
        };

        return array_values(array_filter(
            OrderAction::cases(),
            static fn (OrderAction $action): bool => in_array($action, $allowed, true),
        ));
    }

    /**
     * Pochodna pozycji, nie pole: suma nie ma jak rozjechać się z pozycjami.
     */
    public function total(): Money
    {
        $cents = array_sum(array_map(static fn (OrderLine $line): int => $line->lineTotal()->cents(), $this->lines));

        return Money::fromCents($cents, $this->lines[0]->unitPrice()->currency());
    }

    public function id(): OrderId
    {
        return $this->id;
    }

    public function buyerId(): BuyerId
    {
        return $this->buyerId;
    }

    public function sellerId(): SellerId
    {
        return $this->sellerId;
    }

    /**
     * @return list<OrderLine>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function status(): OrderStatus
    {
        return $this->status;
    }

    public function placedAt(): DateTimeImmutable
    {
        return $this->placedAt;
    }

    public function paymentDeadline(): DateTimeImmutable
    {
        return $this->paymentDeadline;
    }

    public function payment(): ?Payment
    {
        return $this->payment;
    }

    /**
     * @return list<Refund>
     */
    public function refunds(): array
    {
        return $this->refunds;
    }

    public function rejection(): ?Rejection
    {
        return $this->rejection;
    }

    public function cancellation(): ?Cancellation
    {
        return $this->cancellation;
    }

    public function shipment(): ?Shipment
    {
        return $this->shipment;
    }

    public function version(): int
    {
        return $this->version;
    }

    /**
     * @param  list<OrderLine>  $lines
     *
     * @throws InvalidOrderLines
     */
    private static function guardLines(array $lines): void
    {
        if ($lines === []) {
            throw InvalidOrderLines::empty();
        }

        if (count($lines) > self::MAX_LINES) {
            throw InvalidOrderLines::tooMany(self::MAX_LINES);
        }

        $currency = $lines[array_key_first($lines)]->unitPrice()->currency();
        $seen = [];

        foreach ($lines as $line) {
            $offerId = $line->offerId()->value();

            if (isset($seen[$offerId])) {
                throw InvalidOrderLines::duplicateOffer($line->offerId());
            }

            if ($line->unitPrice()->currency() !== $currency) {
                throw InvalidOrderLines::mixedCurrencies();
            }

            $seen[$offerId] = true;
        }
    }

    private function requestRefund(PaymentId $paymentId, Money $amount, RefundReason $reason, DateTimeImmutable $now): void
    {
        $refundId = RefundId::fromString('refund-'.$this->id->value().'-'.(count($this->refunds) + 1));

        $this->refunds[] = Refund::request($refundId, $paymentId, $amount, $reason, $now);
        $this->recordThat(new RefundRequested($this->id, $refundId, $paymentId, $amount, $reason, $now));
    }

    private function hasRefundFor(PaymentId $paymentId): bool
    {
        foreach ($this->refunds as $refund) {
            if ($refund->paymentId->equals($paymentId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws InvalidOrderLines
     */
    private function lineIndex(OfferId $offerId): int
    {
        foreach ($this->lines as $index => $line) {
            if ($line->offerId()->equals($offerId)) {
                return $index;
            }
        }

        throw InvalidOrderLines::unknownOffer($offerId);
    }

    /**
     * @param  list<OrderStatus>  $allowed
     *
     * @throws InvalidOrderTransition
     */
    private function requireStatus(array $allowed, OrderAction|string $action): void
    {
        if (! in_array($this->status, $allowed, true)) {
            throw InvalidOrderTransition::from($this->status, $action);
        }
    }
}
