<?php

namespace Modules\Seatmap\PayPal;

use App\Modules\ModuleProvider;

/** PayPal, through Orders v2. */
class Provider extends ModuleProvider
{
    public function payments(): array
    {
        return [new PayPalGateway($this->context)];
    }
}
