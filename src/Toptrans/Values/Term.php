<?php

declare(strict_types=1);

namespace Ages\ShippingGateway\Toptrans\Values;

/**
 * Termín – základní přepravní služba (číselník register/term).
 */
enum Term: int
{
    case Standard = 1;
    case TopTime = 2;
    /** Doručení soukromé osobě. */
    case TopPrivat = 3;
    /** Osobní odběr na středisku (discharge_personal_branch_id). */
    case PersonalPickup = 5;
    case TopExpress = 7;
    case DeliveryAfterAdvice = 16;
}
