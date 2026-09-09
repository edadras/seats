<?php

namespace Modules\Seatmap\Telegram;

use App\Modules\ModuleProvider;

/** Telegram, through a bot the organiser owns. */
class Provider extends ModuleProvider
{
    public function messaging(): array
    {
        return [new TelegramChannel($this->context)];
    }
}
