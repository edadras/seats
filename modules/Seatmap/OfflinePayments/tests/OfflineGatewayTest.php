<?php

namespace Modules\Seatmap\OfflinePayments\Tests;

use App\Models\ExternalOrder;
use App\Modules\ModuleContext;
use App\Modules\ModuleManifest;
use Modules\Seatmap\OfflinePayments\OfflineGateway;
use Modules\Seatmap\OfflinePayments\Provider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The offline gateway, tested where it lives.
 *
 * A module ships with the evidence that it works rather than only with the claim, and this file is
 * the worked example the module docs point at.
 */
class OfflineGatewayTest extends TestCase
{
    #[Test]
    public function it_settles_immediately_and_says_which_order_it_settled(): void
    {
        $gateway = new OfflineGateway($this->context());

        $order = new ExternalOrder(['external_order_id' => 'ORD-42']);

        $intent = $gateway->begin($order, ['amount' => 2500, 'currency' => 'EUR', 'return_url' => '/', 'buyer' => []]);

        $this->assertSame('paid', $intent->status);
        $this->assertSame('offline:ORD-42', $intent->reference);
        $this->assertNull($intent->redirectUrl, 'Nobody is redirected to a box office.');
    }

    #[Test]
    public function settling_twice_says_the_same_thing_twice(): void
    {
        // Redirect returns and webhooks both arrive more than once. A gateway that changed its
        // answer on the second call would produce an order that flickered between states.
        $gateway = new OfflineGateway($this->context());
        $order = new ExternalOrder(['external_order_id' => 'ORD-42']);

        $first = $gateway->settle($order, []);
        $second = $gateway->settle($order, []);

        $this->assertSame($first->status, $second->status);
        $this->assertSame($first->reference, $second->reference);
    }

    #[Test]
    public function an_organiser_can_replace_the_wording_a_buyer_reads(): void
    {
        $default = new OfflineGateway($this->context());
        $custom = new OfflineGateway($this->context(['instructions' => 'Cash only, from 6pm.']));

        $this->assertSame(__('payments.offline.description'), $default->description());
        $this->assertSame('Cash only, from 6pm.', $custom->description());

        // Whitespace is not a setting: a field someone cleared falls back rather than showing a gap.
        $blank = new OfflineGateway($this->context(['instructions' => '   ']));
        $this->assertSame(__('payments.offline.description'), $blank->description());
    }

    #[Test]
    public function the_provider_contributes_a_gateway_and_nothing_else(): void
    {
        $provider = new Provider($this->context());

        $this->assertCount(1, $provider->payments());
        $this->assertSame([], $provider->messaging());
        $this->assertSame([], $provider->reports());
        $this->assertSame([], $provider->validate(), 'It needs no configuration to work.');
    }

    private function context(array $settings = []): ModuleContext
    {
        $manifest = ModuleManifest::fromArray([
            'key' => 'seatmap/offline-payments',
            'provider' => Provider::class,
            'extends' => ['payments'],
        ], __DIR__.'/..');

        return new ModuleContext($manifest, 'tenant-under-test', $settings);
    }
}
