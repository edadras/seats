<?php

namespace Modules\Seatmap\Zarinpal;

use App\Modules\ModuleProvider;

/** Zarinpal, Iran's most widely used gateway. */
class Provider extends ModuleProvider
{
    public function payments(): array
    {
        return [new ZarinpalGateway($this->context)];
    }
}
