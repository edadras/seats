<?php

namespace Modules\Seatmap\SmsIr;

use App\Modules\ModuleProvider;

/** SMS.ir, an Iranian SMS provider. */
class Provider extends ModuleProvider
{
    public function messaging(): array
    {
        return [new SmsIrChannel($this->context)];
    }
}
