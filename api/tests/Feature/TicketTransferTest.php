<?php

namespace Tests\Feature;

use App\Domain\Sites\SiteProvisioner;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\Ticket;
use App\Models\TicketTransfer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Handing one ticket to somebody else.
 *
 * The property that matters is that there is exactly one working code at every instant: two live
 * codes for one seat is a queue at the door and an argument about who the real holder is. So these
 * check that the old code stops working the moment the ticket is given away, and that a ticket
 * somebody has already come in on cannot be given away at all.
 */
class TicketTransferTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function giving_a_ticket_away_kills_the_old_code_and_makes_a_new_one(): void
    {
        Mail::fake();

        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $reference = $this->buy($fixture, 2);

        $before = $this->ticketHashes($fixture);

        $this->signIn('amina@example.test');

        $this->post('http://northgate.test/account/orders/'.$reference.'/transfer', [
            'allocation_id' => $this->allocationId($fixture, 0),
            'name' => 'Reza Ahmadi',
            'email' => 'reza@example.test',
        ])->assertRedirect('/account');

        $after = $this->ticketHashes($fixture);

        // One code changed, the other did not: giving one ticket away does not disturb the rest of
        // the party.
        $changed = array_keys(array_diff_assoc($after, $before));

        $this->assertCount(1, $changed);

        $ticket = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => Ticket::findOrFail($changed[0])
        );

        $this->assertSame('Reza Ahmadi', $ticket->holder_name);
        $this->assertSame('reza@example.test', $ticket->holder_email);
        $this->assertSame('issued', $ticket->status);

        $record = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => TicketTransfer::firstOrFail()
        );

        $this->assertSame('amina@example.test', $record->from_email);
        $this->assertSame('reza@example.test', $record->to_email);
    }

    #[Test]
    public function the_new_holder_is_emailed_and_the_rest_of_the_party_is_not(): void
    {
        Mail::fake();

        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $reference = $this->buy($fixture, 3);

        Mail::fake(); // Forget the confirmation that went out with the purchase.

        $this->signIn('amina@example.test');

        $this->post('http://northgate.test/account/orders/'.$reference.'/transfer', [
            'allocation_id' => $this->allocationId($fixture, 0),
            'name' => 'Reza Ahmadi',
            'email' => 'reza@example.test',
        ])->assertRedirect();

        Mail::assertSent(\App\Mail\TicketsIssued::class, function ($mail) {
            // One ticket, not the whole booking: the rest of the party's codes are not this
            // person's business.
            return $mail->hasTo('reza@example.test') && 1 === count($mail->tickets);
        });

        Mail::assertNotSent(\App\Mail\TicketsIssued::class, fn ($mail) => $mail->hasTo('amina@example.test'));
    }

    #[Test]
    public function a_ticket_somebody_has_already_come_in_on_cannot_be_given_away(): void
    {
        Mail::fake();

        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $reference = $this->buy($fixture, 1);

        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            Ticket::query()->firstOrFail()->forceFill([
                'status' => 'used',
                'used_at' => now(),
            ])->save();
        });

        $this->signIn('amina@example.test');

        $this->post('http://northgate.test/account/orders/'.$reference.'/transfer', [
            'allocation_id' => $this->allocationId($fixture, 0),
            'name' => 'Reza Ahmadi',
            'email' => 'reza@example.test',
        ])->assertStatus(409);

        $this->assertSame(0, app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => TicketTransfer::count()
        ));
    }

    #[Test]
    public function somebody_elses_booking_cannot_be_given_away(): void
    {
        Mail::fake();

        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $reference = $this->buy($fixture, 1);

        // Signed in as a different person, holding a reference printed on a confirmation page.
        $this->signIn('somebody@example.test');

        $this->post('http://northgate.test/account/orders/'.$reference.'/transfer', [
            'allocation_id' => $this->allocationId($fixture, 0),
            'name' => 'Reza Ahmadi',
            'email' => 'reza@example.test',
        ])->assertNotFound();
    }

    #[Test]
    public function signing_in_is_required(): void
    {
        Mail::fake();

        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $reference = $this->buy($fixture, 1);

        $this->flushSession();

        $this->post('http://northgate.test/account/orders/'.$reference.'/transfer', [
            'allocation_id' => $this->allocationId($fixture, 0),
            'name' => 'Reza Ahmadi',
            'email' => 'reza@example.test',
        ])->assertNotFound();
    }

    /* ------------------------------------------------------------------------------ helpers */

    private function signIn(string $email): void
    {
        $this->withSession(['seatmap_buyer' => ['email' => $email, 'name' => 'A Buyer']]);
    }

    private function allocationId(array $fixture, int $index): string
    {
        return app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => \App\Models\Allocation::orderBy('seat_label')->get()[$index]->id
        );
    }

    /** @return array<string, string> ticket id => the hash of its current code */
    private function ticketHashes(array $fixture): array
    {
        return app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => Ticket::pluck('token_hash', 'id')->all()
        );
    }

    private function buy(array $fixture, int $seats): string
    {
        $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => collect($fixture['seats'])->take($seats)->pluck('id')->all(),
        ])->assertCreated();

        $this->post('http://northgate.test/checkout', [
            'name' => 'Amina Farsi',
            'email' => 'amina@example.test',
            'gateway' => 'offline',
        ])->assertRedirect();

        return (string) session('seatmap_order');
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

            $site->update(['status' => 'live', 'google_signin' => true]);

            return $site->fresh();
        });
    }
}
