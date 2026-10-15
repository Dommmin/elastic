<?php

namespace SellerPortal\Domain\Offer;

/**
 * Akcje, które UI może zaproponować przy ofercie. Kolejność przypadków
 * jest kontraktem: `Offer::allowedActionsFor` zwraca je w tej kolejności.
 */
enum OfferAction: string
{
    case Publish = 'publish';
    case ChangePrice = 'change_price';
    case CancelPendingPriceChange = 'cancel_pending_price_change';
    case ChangeStock = 'change_stock';
    case ChangeDetails = 'change_details';
    case Withdraw = 'withdraw';
    case Suspend = 'suspend';
    case Reinstate = 'reinstate';
    case ApprovePriceChange = 'approve_price_change';
    case RejectPriceChange = 'reject_price_change';
}
