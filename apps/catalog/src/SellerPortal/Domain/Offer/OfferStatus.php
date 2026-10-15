<?php

namespace SellerPortal\Domain\Offer;

/**
 * Status cyklu życia oferty.
 *
 *   Draft --publish--> Published --suspend--> Suspended --reinstate--> Published
 *   (każdy poza Withdrawn) --withdraw--> Withdrawn (koniec)
 */
enum OfferStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Suspended = 'suspended';
    case Withdrawn = 'withdrawn';
}
