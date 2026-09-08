<?php

namespace Modules\Seatmap\OfflinePayments;

use App\Modules\ModuleProvider;

/**
 * Pay at the box office.
 *
 * This is a first-party module and it is deliberately not privileged: it is discovered, enabled and
 * configured exactly the way anybody else's would be. If the module system could not express the
 * gateway the platform ships with, it would not be good enough to offer to a third party
 * (ADR-0004, consequences).
 */
class Provider extends ModuleProvider
{
    public function payments(): array
    {
        return [new OfflineGateway($this->context)];
    }
}
