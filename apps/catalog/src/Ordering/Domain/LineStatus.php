<?php

namespace Ordering\Domain;

/**
 * Los pozycji w kroku rezerwacji: Pending = saga jeszcze nie zarezerwowała sztuk w ofercie.
 */
enum LineStatus: string
{
    case Pending = 'pending';
    case Reserved = 'reserved';
}
