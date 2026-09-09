<?php

namespace Modules\Seatmap\NextPay;

use App\Modules\ModuleProvider;

/** NextPay, an Iranian gateway. */
class Provider extends ModuleProvider
{
    public function payments(): array
    {
        return [new NextPayGateway($this->context)];
    }
}
