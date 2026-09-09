<?php

namespace Tests\Feature;

use App\Domain\Sites\SiteProvisioner;
use App\Models\EventQuestion;
use App\Models\ExternalOrder;
use App\Models\MessageDelivery;
use App\Models\QuestionAnswer;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\Ticket;
use App\Models\WaitingListEntry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * What is held about one person, and taking it away.
 *
 * Two obligations that pull against each other: somebody may ask for a copy and may ask to be
 * forgotten, and an organiser still has to be able to tell a tax authority what last March came to.
 * So the test that matters is that erasure removes the person from the record and leaves the
 * record: the amounts, the dates and the seats stay, and everything that says who it was goes.
 */
class PersonalDataTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function the_export_holds_everything_that_was_collected(): void
    {
        $fixture = $this->makeSellableEvent(amount: 3000);
        $owner = $this->makeUser($fixture['tenant']);
        $this->makeSite($fixture['tenant']);

        $question = $this->ask($fixture, 'Any access requirements?');
        $this->buy($fixture, 2, ['q_'.$question->id => 'A wheelchair space']);
        $this->joinWaitingList($fixture);

        $data = $this->actingAs($owner)
            ->getJson('/v1/customers/'.$this->key().'/personal-data')
            ->assertOk()
            ->json();

        $this->assertSame('amina@example.test', $data['person']['email']);
        $this->assertSame(['Amina Farsi'], $data['person']['names']);
        $this->assertCount(1, $data['orders']);
        $this->assertCount(2, $data['orders'][0]['seats']);
        $this->assertSame(6000, $data['orders'][0]['total_amount']);
        $this->assertSame('A wheelchair space', $data['answers'][0]['answer']);
        $this->assertCount(1, $data['waiting_lists']);
        $this->assertNotEmpty($data['messages']);
    }

    #[Test]
    public function erasing_removes_the_person_and_keeps_the_takings(): void
    {
        $fixture = $this->makeSellableEvent(amount: 3000);
        $owner = $this->makeUser($fixture['tenant']);
        $this->makeSite($fixture['tenant']);

        $question = $this->ask($fixture, 'Guest name');
        $this->buy($fixture, 2, ['q_'.$question->id => 'Dana Scully']);
        $this->joinWaitingList($fixture);

        $key = $this->key();

        $this->actingAs($owner)->postJson('/v1/customers/'.$key.'/erase', ['confirm' => 'erase'])
            ->assertOk();

        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            $order = ExternalOrder::firstOrFail();

            // The person is gone.
            $this->assertSame('[erased]', $order->buyer['name']);
            $this->assertNull($order->buyer['email']);
            $this->assertNull($order->buyer['phone']);
            $this->assertSame(0, QuestionAnswer::count());
            $this->assertSame(0, WaitingListEntry::count());
            $this->assertSame('[erased]', MessageDelivery::firstOrFail()->recipient);
            $this->assertNull(Ticket::firstOrFail()->holder_name);

            // The record is not.
            $this->assertSame(6000, (int) $order->total_amount);
            $this->assertSame('confirmed', $order->status);
            $this->assertSame(2, $order->allocations()->count());
            $this->assertSame(2, Ticket::count());
        });

        // And they fall out of the directory rather than becoming one anonymous person who bought
        // everything.
        $this->actingAs($owner)->getJson('/v1/customers/'.$key)->assertNotFound();
    }

    #[Test]
    public function erasing_takes_the_word_and_nothing_less(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $this->makeSite($fixture['tenant']);

        $this->buy($fixture, 1);

        $this->actingAs($owner)->postJson('/v1/customers/'.$this->key().'/erase')
            ->assertStatus(422);

        $this->actingAs($owner)->postJson('/v1/customers/'.$this->key().'/erase', ['confirm' => 'yes'])
            ->assertStatus(422);

        // Nothing happened.
        $this->assertSame('amina@example.test', app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => ExternalOrder::firstOrFail()->buyer['email']
        ));
    }

    #[Test]
    public function a_box_office_can_find_a_booking_and_not_hand_over_a_life_history(): void
    {
        $fixture = $this->makeSellableEvent();
        $box = $this->makeUser($fixture['tenant'], 'box_office');
        $this->makeSite($fixture['tenant']);

        $this->buy($fixture, 1);

        // Finding a booking is their job; being handed every address, answer and message in one
        // document is a different thing to be trusted with.
        $this->actingAs($box)->getJson('/v1/customers/'.$this->key())->assertOk();
        $this->actingAs($box)->getJson('/v1/customers/'.$this->key().'/personal-data')->assertForbidden();
        $this->actingAs($box)->postJson('/v1/customers/'.$this->key().'/erase', ['confirm' => 'erase'])
            ->assertForbidden();
    }

    #[Test]
    public function the_audit_entry_does_not_record_who_asked_to_be_forgotten(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $this->makeSite($fixture['tenant']);

        $this->buy($fixture, 1);

        $this->actingAs($owner)->postJson('/v1/customers/'.$this->key().'/erase', ['confirm' => 'erase'])
            ->assertOk();

        $entry = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => \App\Models\AuditLog::where('action', 'privacy.erased')->firstOrFail()
        );

        // An audit log recording who was erased would be a list of exactly the people who asked
        // not to be on one.
        $this->assertStringNotContainsString('amina', json_encode($entry->context));
    }

    /* ------------------------------------------------------------------------------ helpers */

    private function key(): string
    {
        return hash('sha256', 'amina@example.test');
    }

    private function ask(array $fixture, string $label): EventQuestion
    {
        return app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => EventQuestion::create([
                'tenant_id' => $fixture['tenant']->id,
                'event_id' => $fixture['event']->id,
                'label' => $label,
                'kind' => 'text',
                'scope' => 'order',
                'status' => 'active',
            ])
        );
    }

    private function joinWaitingList(array $fixture): void
    {
        $this->post('http://northgate.test/events/'.$fixture['event']->public_id.'/waiting-list', [
            'name' => 'Amina Farsi',
            'email' => 'amina@example.test',
            'quantity' => 2,
        ])->assertRedirect();
    }

    private function buy(array $fixture, int $seats, array $extra = []): void
    {
        $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => collect($fixture['seats'])->take($seats)->pluck('id')->all(),
        ])->assertCreated();

        $this->post('http://northgate.test/checkout', [
            'name' => 'Amina Farsi',
            'email' => 'amina@example.test',
            'phone' => '+44 7700 900000',
            'gateway' => 'offline',
        ] + $extra)->assertRedirect();
    }

    private function makeSite($tenant): Site
    {
        return app(TenantContext::class)->runAs($tenant, function () use ($tenant) {
            $site = app(SiteProvisioner::class)->create($tenant->name);

            SiteDomain::create([
                'site_id' => $site->id,
                'hostname' => 'northgate.test',
                'is_primary' => true,
                'verification_token' => SiteDomain::newToken(),
                'verified_at' => now(),
            ]);

            $site->update(['status' => 'live']);

            return $site->fresh();
        });
    }
}
