<?php

namespace Modules\Seatmap\WhatsApp;

use App\Modules\ModuleProvider;

/** WhatsApp, through the Cloud API. */
class Provider extends ModuleProvider
{
    public function messaging(): array
    {
        return [new WhatsAppChannel($this->context)];
    }
}
