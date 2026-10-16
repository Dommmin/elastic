<?php

namespace Ordering\Domain;

/**
 * Dlaczego zamówienie odrzucono i na której ofercie. DLACZEGO oferta: kupujący
 * ma zobaczyć, która pozycja zablokowała całe zamówienie.
 */
final readonly class Rejection
{
    public function __construct(
        public RejectionReason $reason,
        public OfferId $offerId,
    ) {}
}
