<?php

namespace Tests\Feature;

use App\Domain\Sites\SiteProvisioner;
use App\Models\EventQuestion;
use App\Models\QuestionAnswer;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * The organiser's own questions, asked at checkout.
 *
 * The two things worth guarding: a required answer stops a purchase *before* the money moves —
 * otherwise it is collected on a page nobody comes back to — and a per-seat question lands against
 * the right seat, because the door list reads it beside a name.
 */
class CheckoutQuestionsTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function an_order_question_is_asked_once_and_kept_on_the_booking(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);

        $question = $this->ask($fixture, [
            'label' => 'Any access requirements?',
            'scope' => 'order',
        ]);

        $this->hold($fixture, 2);

        $page = $this->get('http://northgate.test/checkout')->assertOk();

        $page->assertSee('Any access requirements?');
        // Asked once, however many seats are in the basket.
        $this->assertSame(1, substr_count($page->getContent(), 'name="q_'.$question->id.'"'));

        $this->pay(['q_'.$question->id => 'A wheelchair space, please']);

        $answers = $this->answers($fixture);

        $this->assertCount(1, $answers);
        $this->assertSame('A wheelchair space, please', $answers[0]->value);
        $this->assertNull($answers[0]->allocation_id, 'It was asked of the booking, not of a seat.');
    }

    #[Test]
    public function a_ticket_question_is_asked_of_every_seat_and_lands_on_the_right_one(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $this->makeSite($fixture['tenant']);

        $question = $this->ask($fixture, ['label' => 'Guest name', 'scope' => 'ticket']);

        $this->hold($fixture, 2);
        $this->get('http://northgate.test/checkout')->assertOk();

        $this->pay([
            'q_'.$question->id.'_'.$fixture['seats'][0]->id => 'Dana Scully',
            'q_'.$question->id.'_'.$fixture['seats'][1]->id => 'Fox Mulder',
        ]);

        $answers = $this->answers($fixture);

        $this->assertCount(2, $answers);
        $this->assertNotNull($answers[0]->allocation_id);

        // And the door reads each against the seat it belongs to.
        $list = $this->actingAs($owner)
            ->getJson("/v1/events/{$fixture['event']->id}/door-list")
            ->assertOk()
            ->json('data');

        $named = collect($list)->flatMap(fn ($row) => collect($row['answers'])->pluck('value'))->all();

        $this->assertEqualsCanonicalizing(['Dana Scully', 'Fox Mulder'], $named);
    }

    #[Test]
    public function a_required_answer_stops_the_purchase_before_the_money_moves(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);

        $this->ask($fixture, [
            'label' => 'Car registration',
            'scope' => 'order',
            'required' => true,
        ]);

        $this->hold($fixture, 1);

        $this->post('http://northgate.test/checkout', [
            'name' => 'Amina Farsi',
            'email' => 'amina@example.test',
            'gateway' => 'offline',
        ])->assertSessionHasErrors();

        // Nothing sold, and the seats are still theirs to complete with.
        $this->assertSame(0, app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => \App\Models\ExternalOrder::count()
        ));

        $this->pay(['q_'.$this->questionId($fixture) => 'YR26 ABC']);

        $this->assertSame(1, app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => \App\Models\ExternalOrder::count()
        ));
    }

    #[Test]
    public function an_answer_that_was_never_offered_is_not_an_answer(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);

        $question = $this->ask($fixture, [
            'label' => 'Which coach?',
            'scope' => 'order',
            'kind' => 'choice',
            'options' => ['Coach A', 'Coach B'],
        ]);

        $this->hold($fixture, 1);
        // A browser sending whatever it liked.
        $this->pay(['q_'.$question->id => 'Coach Z']);

        $this->assertCount(0, $this->answers($fixture));
    }

    #[Test]
    public function a_question_somebody_answered_cannot_be_removed(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $this->makeSite($fixture['tenant']);

        $question = $this->ask($fixture, ['label' => 'Any access requirements?', 'scope' => 'order']);

        $this->hold($fixture, 1);
        $this->pay(['q_'.$question->id => 'None']);

        $this->actingAs($owner)
            ->putJson("/v1/events/{$fixture['event']->id}/questions", ['questions' => []])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'question_answered');

        // Hiding it is the way to stop asking, and the answer stays readable.
        $this->actingAs($owner)->putJson("/v1/events/{$fixture['event']->id}/questions", [
            'questions' => [[
                'id' => $question->id,
                'label' => 'Any access requirements?',
                'kind' => 'text',
                'scope' => 'order',
                'status' => 'hidden',
            ]],
        ])->assertOk();

        $this->assertCount(1, $this->answers($fixture));
    }

    #[Test]
    public function the_wording_is_kept_beside_the_answer(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $this->makeSite($fixture['tenant']);

        $question = $this->ask($fixture, ['label' => 'Dietary needs', 'scope' => 'order']);

        $this->hold($fixture, 1);
        $this->pay(['q_'.$question->id => 'Vegetarian']);

        $this->actingAs($owner)->putJson("/v1/events/{$fixture['event']->id}/questions", [
            'questions' => [[
                'id' => $question->id,
                'label' => 'Allergies and dietary needs',
                'kind' => 'text',
                'scope' => 'order',
            ]],
        ])->assertOk();

        // Rewording the question must not change what a past answer appears to be an answer to.
        $this->assertSame('Dietary needs', $this->answers($fixture)[0]->label);
    }

    /* ------------------------------------------------------------------------------ helpers */

    private function ask(array $fixture, array $attributes): EventQuestion
    {
        return app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => EventQuestion::create($attributes + [
                'tenant_id' => $fixture['tenant']->id,
                'event_id' => $fixture['event']->id,
                'kind' => 'text',
                'status' => 'active',
            ])
        );
    }

    private function questionId(array $fixture): string
    {
        return app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => EventQuestion::firstOrFail()->id
        );
    }

    /** @return \Illuminate\Support\Collection<int, QuestionAnswer> */
    private function answers(array $fixture)
    {
        return app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => QuestionAnswer::orderBy('created_at')->get()
        );
    }

    private function hold(array $fixture, int $seats): void
    {
        $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => collect($fixture['seats'])->take($seats)->pluck('id')->all(),
        ])->assertCreated();
    }

    private function pay(array $extra = []): void
    {
        $this->post('http://northgate.test/checkout', [
            'name' => 'Amina Farsi',
            'email' => 'amina@example.test',
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
