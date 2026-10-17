<?php

namespace Ordering\Domain;

/**
 * Akcje, które UI może zaproponować przy zamówieniu. Kolejność przypadków jest kontraktem: `Order::allowedActionsFor` zwraca je w tej kolejności.
 */
enum OrderAction: string
{
    case Pay = 'pay';
    case Cancel = 'cancel';
    case Ship = 'ship';
    case CancelBySeller = 'cancel_by_seller';
}
