<?php

namespace Modules\Seatmap\Twilio;

use App\Modules\ModuleProvider;

/** Twilio, for SMS outside Iran. */
class Provider extends ModuleProvider
{
    public function messaging(): array
    {
        return [new TwilioChannel($this->context)];
    }
}
