<?php

namespace Ordering\Domain;

/**
 * Requested = zlecony, bramka jeszcze nie potwierdziła; Completed = pieniądze wróciły.
 */
enum RefundStatus: string
{
    case Requested = 'requested';
    case Completed = 'completed';
}
