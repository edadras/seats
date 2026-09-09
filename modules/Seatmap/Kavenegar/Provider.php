<?php

namespace Modules\Seatmap\Kavenegar;

use App\Modules\ModuleProvider;

/** Kavenegar, an Iranian SMS provider. */
class Provider extends ModuleProvider
{
    public function messaging(): array
    {
        return [new KavenegarChannel($this->context)];
    }
}
