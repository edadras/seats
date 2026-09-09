<?php

namespace Modules\Seatmap\Stripe;

use App\Modules\ModuleProvider;

/** Stripe, through Checkout Sessions. */
class Provider extends ModuleProvider
{
    public function payments(): array
    {
        return [new StripeGateway($this->context)];
    }
}
