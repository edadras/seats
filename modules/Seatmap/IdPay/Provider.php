<?php

namespace Modules\Seatmap\IdPay;

use App\Modules\ModuleProvider;

/** IDPay, an Iranian gateway that aggregates the bank PSPs behind one API. */
class Provider extends ModuleProvider
{
    public function payments(): array
    {
        return [new IdPayGateway($this->context)];
    }
}
