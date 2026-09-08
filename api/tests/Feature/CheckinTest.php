<?php

namespace Tests\Feature;

use App\Models\CheckinDevice;
use App\Models\Checkin;
use App\Models\Event;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ActsAsStorefront;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Door operations, including the cases staff actually hit: a ticket for the wrong night, a refunded
 * ticket, a scanner that was offline, and the same QR presented twice.
 */
class CheckinTest extends TestCase
{
    use ActsAsStorefront, BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function an_unknown_token_is_invalid_rather_than_an_error(): void
    {
        $ctx = $this->makeSellableEvent();
        $device = $this->pairDevice($ctx['tenant'], $ctx['event']);

        $this->withToken($device['token'])
            ->postJson('/v1/checkin/scan', ['token' => 'TKTNOTAREALTOKEN', 'event_id' => $ctx['event']->id])
            ->assertOk()
            ->assertJsonPath('result', 'invalid');
    }

    #[Test]
    public function a_ticket_for_another_event_reports_wrong_event(): void
    {
        $ctx = $this->sellableOrder('wc_5001');
        $token = $this->confirmAndTakeToken('wc_5001');

        // A second event at the same venue, and a device allowed to scan both.
        $other = $this->asTenant($ctx['tenant'], fn () => Event::create([
            'venue_id' => $ctx['venue']->id,
            'seat_map_id' => $ctx['map']->id,
            'seat_map_version_id' => $ctx['map']->published_version_id,
            'public_id' => 'evt_'.Str::lower(Str::random(20)),
            'name' => 'Second night',
            'status' => 'published',
            'starts_at' => now()->addWeeks(2),
            'currency' => 'EUR',
        ]));

        $device = $this->pairDevice($ctx['tenant'], [$ctx['event'], $other]);

        $this->withToken($device['token'])
            ->postJson('/v1/checkin/scan', ['token' => $token, 'event_id' => $other->id])
            ->assertOk()
            ->assertJsonPath('result', 'wrong_event');

        // The ticket must remain usable at the night it was actually sold for.
        $this->withToken($device['token'])
            ->postJson('/v1/checkin/scan', ['token' => $token, 'event_id' => $ctx['event']->id])
            ->assertOk()
            ->assertJsonPath('result', 'valid');
    }

    #[Test]
    public function a_refunded_ticket_is_reported_as_refunded_not_merely_invalid(): void
    {
        // The distinction matters at the door: "refunded" is a different conversation with the
        // customer than "we have never seen this ticket".
        $ctx = $this->sellableOrder('wc_5002');
        $token = $this->confirmAndTakeToken('wc_5002');

        $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_5002/refund')->assertOk();

        $device = $this->pairDevice($ctx['tenant'], $ctx['event']);

        $this->withToken($device['token'])
            ->postJson('/v1/checkin/scan', ['token' => $token, 'event_id' => $ctx['event']->id])
            ->assertOk()
            ->assertJsonPath('result', 'refunded');
    }

    #[Test]
    public function the_second_scan_names_the_door_that_admitted_the_ticket(): void
    {
        $ctx = $this->sellableOrder('wc_5003');
        $token = $this->confirmAndTakeToken('wc_5003');

        $north = $this->pairDevice($ctx['tenant'], $ctx['event'], 'North door');
        $south = $this->pairDevice($ctx['tenant'], $ctx['event'], 'South door');

        $this->withToken($north['token'])
            ->postJson('/v1/checkin/scan', ['token' => $token, 'event_id' => $ctx['event']->id])
            ->assertOk()->assertJsonPath('result', 'valid');

        $second = $this->withToken($south['token'])
            ->postJson('/v1/checkin/scan', ['token' => $token, 'event_id' => $ctx['event']->id])
            ->assertOk();

        $this->assertSame('already_used', $second->json('result'));
        $this->assertSame('North door', $second->json('first_scan.device'));
        $this->assertNotNull($second->json('first_scan.scanned_at'));
    }

    #[Test]
    public function an_offline_batch_is_idempotent_and_resolves_to_the_earliest_scan(): void
    {
        $ctx = $this->sellableOrder('wc_5004');
        $token = $this->confirmAndTakeToken('wc_5004');

        $device = $this->pairDevice($ctx['tenant'], $ctx['event']);

        // Uploaded out of order, as a flaky connection would deliver them.
        $batch = ['scans' => [
            [
                'token' => $token, 'event_id' => $ctx['event']->id,
                'scanned_at' => now()->subMinutes(5)->toIso8601String(), 'client_scan_id' => 'scan-late',
            ],
            [
                'token' => $token, 'event_id' => $ctx['event']->id,
                'scanned_at' => now()->subMinutes(30)->toIso8601String(), 'client_scan_id' => 'scan-early',
            ],
        ]];

        $first = $this->withToken($device['token'])->postJson('/v1/checkin/sync', $batch)->assertOk();

        // Results come back in request order, but the earlier scan is the one that admitted them.
        $this->assertSame('already_used', $first->json('results.0.result'));
        $this->assertSame('valid', $first->json('results.1.result'));

        // Replaying the whole batch — the device never saw the ack — must not double-count.
        $this->withToken($device['token'])->postJson('/v1/checkin/sync', $batch)->assertOk();

        $this->asTenant($ctx['tenant'], function () use ($ctx) {
            $this->assertSame(1, Checkin::where('event_id', $ctx['event']->id)->where('result', 'valid')->count());
            $this->assertSame(2, Checkin::where('event_id', $ctx['event']->id)->count());
        });
    }

    #[Test]
    public function door_statistics_reflect_scans(): void
    {
        $ctx = $this->sellableOrder('wc_5005');
        $token = $this->confirmAndTakeToken('wc_5005');
        $device = $this->pairDevice($ctx['tenant'], $ctx['event']);

        $this->withToken($device['token'])
            ->postJson('/v1/checkin/scan', ['token' => $token, 'event_id' => $ctx['event']->id])->assertOk();

        $stats = $this->withToken($device['token'])
            ->getJson("/v1/checkin/events/{$ctx['event']->id}/stats")->assertOk();

        $this->assertSame(15, $stats->json('seats_total'));
        $this->assertSame(2, $stats->json('allocated'));
        $this->assertSame(2, $stats->json('tickets_issued'));
        $this->assertSame(1, $stats->json('checked_in'));
        $this->assertSame(0.5, $stats->json('checkin_rate'));
    }

    #[Test]
    public function a_pairing_code_works_once_and_then_expires(): void
    {
        $ctx = $this->makeSellableEvent();

        $code = 'pair-'.Str::random(10);

        $this->asTenant($ctx['tenant'], function () use ($ctx, $code) {
            CheckinDevice::factory()->create([
                'tenant_id' => $ctx['tenant']->id,
                'status' => 'pending',
                'pairing_code_hash' => hash('sha256', $code),
                'pairing_expires_at' => now()->addMinutes(30),
            ]);
        });

        $this->postJson('/v1/checkin/auth/token', ['pairing_code' => $code, 'device_name' => 'Door'])
            ->assertOk();

        // A code left on a printout must not enrol a second scanner.
        $this->postJson('/v1/checkin/auth/token', ['pairing_code' => $code, 'device_name' => 'Rogue'])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_pairing_code');
    }

    private function confirmAndTakeToken(string $orderId): string
    {
        return $this->storefront('POST', "/v1/integrations/woocommerce/orders/{$orderId}/confirm")
            ->assertOk()
            ->json('tickets.0.token');
    }

    /**
     * @param  Event|list<Event>  $events  Events this device may scan.
     * @return array{token: string, name: string}
     */
    private function pairDevice(Tenant $tenant, Event|array $events, string $name = 'Main door'): array
    {
        $events = is_array($events) ? $events : [$events];

        return $this->asTenant($tenant, function () use ($tenant, $events, $name) {
            $code = 'pair-'.Str::random(12);

            $device = CheckinDevice::factory()->create([
                'tenant_id' => $tenant->id,
                'status' => 'pending',
                'pairing_code_hash' => hash('sha256', $code),
                'pairing_expires_at' => now()->addMinutes(30),
            ]);

            foreach ($events as $event) {
                $device->grantAccessTo($event);
            }

            $token = $this->postJson('/v1/checkin/auth/token', [
                'pairing_code' => $code,
                'device_name' => $name,
            ])->assertOk()->json('token');

            return ['token' => $token, 'name' => $name];
        });
    }
}
