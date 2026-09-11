<?php

namespace Tests\Feature;

use App\Models\CheckinDevice;
use App\Models\Event;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ActsAsStorefront;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * The list a scanner takes with it, so it can say no with no signal.
 *
 * Until this existed the scanner kept working offline only in the sense that it lost nothing: every
 * scan went into a queue and everybody was admitted, and the forgeries turned up in the morning.
 * At a door that is the same as having no check at all.
 *
 * Three claims, and the first is the one that makes the feature safe to ship:
 *
 *   1. **Hashes travel, codes never do.** A scanner left in a taxi is a list of names and seat
 *      numbers — what a printed door list has always been — and not a machine for minting tickets.
 *   2. **A refunded ticket is on the list, marked refunded.** One that simply failed to appear
 *      would read as a forgery, and "you were refunded on Tuesday" is a different conversation.
 *   3. **A device only ever gets the nights it was given.** The list is the whole house; a device
 *      authorised for Tuesday must not be able to take Wednesday's.
 */
class OfflineDoorTest extends TestCase
{
    use ActsAsStorefront, BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function the_list_carries_the_hash_of_a_ticket_and_never_the_ticket(): void
    {
        $ctx = $this->sellableOrder('wc_door_1');
        $token = $this->confirmAndTakeToken('wc_door_1');
        $device = $this->pairDevice($ctx['tenant'], $ctx['event']);

        $list = $this->withToken($device['token'])
            ->getJson('/v1/checkin/events/'.$ctx['event']->id.'/door-list')
            ->assertOk()
            ->json();

        $this->assertSame(2, $list['count']);
        $this->assertNotEmpty($list['version']);
        $this->assertNotEmpty($list['taken_at']);

        $hashes = array_column($list['tickets'], 'h');

        $this->assertContains(hash('sha256', $token), $hashes);

        // The whole security argument, asserted rather than asserted-in-a-comment: nothing in the
        // body can be presented at a door.
        $this->assertStringNotContainsString($token, json_encode($list));

        foreach ($list['tickets'] as $row) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $row['h']);
        }
    }

    #[Test]
    public function a_seat_and_a_name_are_there_because_that_is_what_the_door_reads(): void
    {
        $ctx = $this->sellableOrder('wc_door_2');
        $this->confirmAndTakeToken('wc_door_2');
        $device = $this->pairDevice($ctx['tenant'], $ctx['event']);

        $rows = $this->withToken($device['token'])
            ->getJson('/v1/checkin/events/'.$ctx['event']->id.'/door-list')
            ->assertOk()
            ->json('tickets');

        $this->assertSame(['issued', 'issued'], array_column($rows, 's'));

        // Section, row and seat, in reading order and with the empty parts left out — a standing
        // place has none of them and gets no key at all. Asserted as a set: which of the two
        // tickets was written first is not a promise this makes.
        $seats = array_column($rows, 'p');

        sort($seats);

        $this->assertSame([['Stalls', 'A', '1'], ['Stalls', 'A', '2']], $seats);
    }

    #[Test]
    public function a_refunded_ticket_is_on_the_list_and_says_so(): void
    {
        $ctx = $this->sellableOrder('wc_door_3');
        $this->confirmAndTakeToken('wc_door_3');
        $device = $this->pairDevice($ctx['tenant'], $ctx['event']);

        $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_door_3/refund')->assertOk();

        $states = array_column(
            $this->withToken($device['token'])
                ->getJson('/v1/checkin/events/'.$ctx['event']->id.'/door-list')
                ->assertOk()
                ->json('tickets'),
            's'
        );

        $this->assertSame(['refunded', 'refunded'], $states);
    }

    #[Test]
    public function a_ticket_already_used_carries_the_time_it_was_used(): void
    {
        $ctx = $this->sellableOrder('wc_door_4');
        $token = $this->confirmAndTakeToken('wc_door_4');
        $device = $this->pairDevice($ctx['tenant'], $ctx['event']);

        $this->withToken($device['token'])
            ->postJson('/v1/checkin/scan', ['token' => $token, 'event_id' => $ctx['event']->id])
            ->assertOk()
            ->assertJsonPath('result', 'valid');

        $used = collect(
            $this->withToken($device['token'])
                ->getJson('/v1/checkin/events/'.$ctx['event']->id.'/door-list')
                ->assertOk()
                ->json('tickets')
        )->firstWhere('h', hash('sha256', $token));

        $this->assertSame('used', $used['s']);
        $this->assertNotEmpty($used['t']);
    }

    #[Test]
    public function asking_again_for_a_list_that_has_not_moved_costs_nothing(): void
    {
        $ctx = $this->sellableOrder('wc_door_5');
        $this->confirmAndTakeToken('wc_door_5');
        $device = $this->pairDevice($ctx['tenant'], $ctx['event']);

        $first = $this->withToken($device['token'])
            ->getJson('/v1/checkin/events/'.$ctx['event']->id.'/door-list')
            ->assertOk();

        $tag = $first->headers->get('ETag');

        $this->assertNotEmpty($tag);

        $this->withToken($device['token'])
            ->withHeaders(['If-None-Match' => $tag])
            ->getJson('/v1/checkin/events/'.$ctx['event']->id.'/door-list')
            ->assertStatus(304);
    }

    #[Test]
    public function a_ticket_sold_since_moves_the_version(): void
    {
        $ctx = $this->sellableOrder('wc_door_6');
        $this->confirmAndTakeToken('wc_door_6');
        $device = $this->pairDevice($ctx['tenant'], $ctx['event']);

        $before = $this->withToken($device['token'])
            ->getJson('/v1/checkin/events/'.$ctx['event']->id.'/door-list')
            ->json('version');

        // Somebody buys while the doors are open, which is the case that makes a stale list
        // dangerous rather than merely out of date: their ticket is real and is not on the copy
        // the scanner is carrying.
        $hold = $this->postJson("/v1/embed/events/{$ctx['event']->public_id}/holds", [
            'seat_ids' => [$ctx['seats'][2]->id],
            'session_id' => 'latecomer',
        ])->assertCreated();

        $this->storefront('POST', '/v1/integrations/woocommerce/orders', [
            'external_order_id' => 'wc_door_6b',
            'hold_token' => $hold->json('hold_token'),
        ])->assertCreated();

        $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_door_6b/confirm')->assertOk();

        $after = $this->withToken($device['token'])
            ->getJson('/v1/checkin/events/'.$ctx['event']->id.'/door-list')
            ->json('version');

        $this->assertNotSame($before, $after);
    }

    #[Test]
    public function taking_the_list_is_recorded_on_the_device(): void
    {
        $ctx = $this->sellableOrder('wc_door_7');
        $this->confirmAndTakeToken('wc_door_7');
        $device = $this->pairDevice($ctx['tenant'], $ctx['event']);

        $this->assertNull(CheckinDevice::first()->door_list_taken_at);

        $this->withToken($device['token'])
            ->getJson('/v1/checkin/events/'.$ctx['event']->id.'/door-list')
            ->assertOk();

        // "When did that tablet last take a copy" is the one question a manager cannot answer by
        // looking at the tablet.
        $this->assertNotNull(CheckinDevice::first()->door_list_taken_at);
    }

    #[Test]
    public function a_device_cannot_take_the_list_for_a_night_it_was_not_given(): void
    {
        $ctx = $this->sellableOrder('wc_door_8');
        $this->confirmAndTakeToken('wc_door_8');

        $other = $this->asTenant($ctx['tenant'], fn () => Event::create([
            'venue_id' => $ctx['venue']->id,
            'seat_map_id' => $ctx['map']->id,
            'seat_map_version_id' => $ctx['map']->published_version_id,
            'public_id' => 'evt_'.Str::lower(Str::random(20)),
            'name' => 'Another night',
            'status' => 'published',
            'starts_at' => now()->addWeeks(2),
            'currency' => 'EUR',
        ]));

        $device = $this->pairDevice($ctx['tenant'], $ctx['event']);

        $this->withToken($device['token'])
            ->getJson('/v1/checkin/events/'.$other->id.'/door-list')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    }

    #[Test]
    public function nobody_without_a_paired_device_gets_a_list_of_the_audience(): void
    {
        $ctx = $this->sellableOrder('wc_door_9');
        $this->confirmAndTakeToken('wc_door_9');

        $this->getJson('/v1/checkin/events/'.$ctx['event']->id.'/door-list')->assertUnauthorized();
    }

    /* --------------------------------------------------------------------------- helpers */

    /**
     * Swap this test from a signed-in person to a paired device.
     *
     * The guard has to be forgotten as well as the header replaced: Sanctum resolves a user once
     * per request lifecycle and caches it, so without this a request carrying a device token is
     * still answered as the member who made the device a moment ago.
     */
    private function asDevice(string $token): static
    {
        app('auth')->forgetGuards();

        return $this->withToken($token);
    }

    private function confirmAndTakeToken(string $orderId): string
    {
        return $this->storefront('POST', "/v1/integrations/woocommerce/orders/{$orderId}/confirm")
            ->assertOk()
            ->json('tickets.0.token');
    }

    /** @return array{token: string, name: string} */
    private function pairDevice(Tenant $tenant, Event $event, string $name = 'Main door'): array
    {
        return $this->asTenant($tenant, function () use ($tenant, $event, $name) {
            $code = 'pair-'.Str::random(12);

            $device = CheckinDevice::factory()->create([
                'tenant_id' => $tenant->id,
                'status' => 'pending',
                'pairing_code_hash' => hash('sha256', $code),
                'pairing_expires_at' => now()->addMinutes(30),
            ]);

            $device->grantAccessTo($event);

            $token = $this->postJson('/v1/checkin/auth/token', [
                'pairing_code' => $code,
                'device_name' => $name,
            ])->assertOk()->json('token');

            return ['token' => $token, 'name' => $name];
        });
    }

    /* ---------------------------------------------- the screen that issues the code at all */

    #[Test]
    public function a_manager_can_make_a_scanner_and_pair_it(): void
    {
        $ctx = $this->makeSellableEvent();
        $user = $this->makeUser($ctx['tenant']);

        // `devices.manage` has existed since permissions did, granted to four roles and used by
        // nothing: until this screen there was no way on the platform to issue a pairing code, so
        // the scanner could not be put into anybody's hand.
        $made = $this->asMember($user)
            ->postJson('/v1/scanners', [
                'name' => 'Front of house',
                'event_ids' => [$ctx['event']->id],
            ])
            ->assertCreated();

        $code = $made->json('pairing_code');

        $this->assertNotEmpty($code);
        $this->assertSame('pending', $made->json('data.status'));
        $this->assertSame([$ctx['event']->id], $made->json('data.event_ids'));

        // And it is the code the scanner's own pairing endpoint accepts.
        app('auth')->forgetGuards();

        $paired = $this->postJson('/v1/checkin/auth/token', [
            'pairing_code' => $code,
            'device_name' => 'Front of house',
        ])->assertOk();

        $this->assertNotEmpty($paired->json('token'));

        // Which is enough to take the door list, which is the whole point of the pairing.
        $this->asDevice($paired->json('token'))
            ->getJson('/v1/checkin/events/'.$ctx['event']->id.'/door-list')
            ->assertOk();
    }

    #[Test]
    public function the_code_is_shown_once_and_never_read_back(): void
    {
        $ctx = $this->makeSellableEvent();
        $user = $this->makeUser($ctx['tenant']);

        $this->asMember($user)->postJson('/v1/scanners', ['name' => 'Side door'])->assertCreated();

        $listed = $this->asMember($user)->getJson('/v1/scanners')->assertOk();

        $this->assertSame('Side door', $listed->json('data.0.name'));
        // Like every other credential this platform issues.
        $this->assertStringNotContainsString('pairing_code', $listed->getContent());
    }

    #[Test]
    public function a_new_code_signs_the_old_device_out(): void
    {
        $ctx = $this->makeSellableEvent();
        $user = $this->makeUser($ctx['tenant']);

        $code = $this->asMember($user)
            ->postJson('/v1/scanners', ['name' => 'Lost tablet', 'event_ids' => [$ctx['event']->id]])
            ->json('pairing_code');

        $token = $this->postJson('/v1/checkin/auth/token', [
            'pairing_code' => $code,
            'device_name' => 'Lost tablet',
        ])->json('token');

        $device = CheckinDevice::first();

        $this->asMember($user)->postJson('/v1/scanners/'.$device->id.'/code')->assertOk();

        // A scanner being re-paired is a scanner somebody has lost track of. Leaving the old token
        // working is how a phone in a drawer keeps admitting people.
        $this->asDevice($token)->getJson('/v1/checkin/events')->assertUnauthorized();
    }

    #[Test]
    public function removing_a_scanner_stops_it_and_keeps_what_it_scanned(): void
    {
        $ctx = $this->sellableOrder('wc_door_10');
        $token = $this->confirmAndTakeToken('wc_door_10');
        $user = $this->makeUser($ctx['tenant']);

        $code = $this->asMember($user)
            ->postJson('/v1/scanners', ['name' => 'Door 2', 'event_ids' => [$ctx['event']->id]])
            ->json('pairing_code');

        app('auth')->forgetGuards();

        $deviceToken = $this->postJson('/v1/checkin/auth/token', [
            'pairing_code' => $code,
            'device_name' => 'Door 2',
        ])->json('token');

        $this->asDevice($deviceToken)
            ->postJson('/v1/checkin/scan', ['token' => $token, 'event_id' => $ctx['event']->id])
            ->assertOk()
            ->assertJsonPath('result', 'valid');

        $this->asMember($user)
            ->deleteJson('/v1/scanners/'.CheckinDevice::first()->id)
            ->assertOk();

        $this->asDevice($deviceToken)->getJson('/v1/checkin/events')->assertUnauthorized();
        // Those are check-ins, and they belong to the event rather than to a phone somebody
        // handed back.
        $this->assertDatabaseCount('checkins', 1);
    }

    #[Test]
    public function somebody_who_may_not_run_the_doors_cannot_issue_a_scanner(): void
    {
        $ctx = $this->makeSellableEvent();

        $this->asMember($this->makeUser($ctx['tenant'], 'marketing'))
            ->postJson('/v1/scanners', ['name' => 'Not yours'])
            ->assertStatus(403);
    }
}
