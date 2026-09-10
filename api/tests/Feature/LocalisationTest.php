<?php

namespace Tests\Feature;

use App\Domain\Sites\SiteProvisioner;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Support\Locale\Dates;
use App\Support\Locale\Locales;
use App\Support\Locale\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * What the six languages have to actually do (ADR-0005).
 *
 * These are not "does the catalogue exist" tests — `tools/i18n-check.mjs` answers that, on every
 * push, better than a test could. These cover the behaviour: that the language is chosen from the
 * right person, that money follows the reader without following the reader's currency, and that a
 * refusal is refused in a language the person who was refused can read.
 */
class LocalisationTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function a_visitor_gets_the_sites_language_without_asking_for_it(): void
    {
        $site = $this->hostedSite(['locale' => 'fa']);

        $response = $this->get('http://northgate.localhost/');

        $response->assertOk();
        $response->assertHeader('Content-Language', 'fa');
        // Direction is a property of the language, applied once, at the root.
        $response->assertSee('dir="rtl"', false);
        $response->assertSee(__('site.skipToContent', [], 'fa'), false);
    }

    #[Test]
    public function a_visitor_can_correct_the_guess(): void
    {
        $this->hostedSite(['locale' => 'fa']);

        $response = $this->get('http://northgate.localhost/?lang=de');

        $response->assertOk();
        $response->assertHeader('Content-Language', 'de');
        $response->assertSee('dir="ltr"', false);
        $response->assertSee(__('site.skipToContent', [], 'de'), false);
    }

    #[Test]
    public function an_unknown_language_falls_back_rather_than_showing_keys(): void
    {
        $this->hostedSite(['locale' => 'en']);

        // `zz` is nobody's language. The page must still be a page.
        $response = $this->get('http://northgate.localhost/?lang=zz');

        $response->assertOk();
        $response->assertHeader('Content-Language', 'en');
        $response->assertDontSee('site.skipToContent');
    }

    #[Test]
    public function the_browsers_preference_is_honoured_when_nothing_closer_says_otherwise(): void
    {
        // A request with no site and nobody signed in: the browser is the nearest person there is.
        $response = $this->withHeaders(['Accept-Language' => 'pt-BR,de-DE;q=0.9,en;q=0.5'])
            ->getJson('/v1/i18n');

        // Portuguese is not offered, German is, and German outranks English by its q value.
        $response->assertHeader('Content-Language', 'de');
        $response->assertJsonPath('current', 'de');
    }

    #[Test]
    public function the_site_outranks_the_browser_and_the_visitor_outranks_them_both(): void
    {
        $this->hostedSite(['locale' => 'fa']);

        // The site is written in Persian, so a German browser still gets Persian: the content is
        // closer to the visitor than their header is.
        $this->withHeaders(['Accept-Language' => 'de'])
            ->get('http://northgate.localhost/')
            ->assertHeader('Content-Language', 'fa');

        // Until they say otherwise, at which point nothing outranks them.
        $this->withHeaders(['Accept-Language' => 'de'])
            ->get('http://northgate.localhost/?lang=it')
            ->assertHeader('Content-Language', 'it');
    }

    #[Test]
    public function a_language_chosen_in_the_footer_survives_the_next_click(): void
    {
        $this->hostedSite(['locale' => 'fa']);

        // Choosing a language and then following an ordinary link must not undo the choice.
        $this->get('http://northgate.localhost/?lang=de');

        $this->get('http://northgate.localhost/')
            ->assertHeader('Content-Language', 'de');
    }

    #[Test]
    public function accept_language_is_read_by_quality_not_by_order(): void
    {
        // Straight at the parser: q values decide, a language we do not ship is skipped, and the
        // first mention of a language is the one that counts.
        $this->assertSame('de', Locales::fromAcceptLanguage('pt-BR,de-DE;q=0.9,en;q=0.5'));
        $this->assertSame('en', Locales::fromAcceptLanguage('de;q=0.2,en;q=0.8'));
        $this->assertSame('fa', Locales::fromAcceptLanguage('fa-IR,fa;q=0.5'));
        $this->assertNull(Locales::fromAcceptLanguage('pt,ja,ko'));
        $this->assertNull(Locales::fromAcceptLanguage(null));
    }

    #[Test]
    public function an_api_refusal_is_written_in_the_readers_language(): void
    {
        $response = $this->withHeaders(['X-Seatmap-Locale' => 'fa'])
            ->postJson('/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'wrong']);

        $response->assertStatus(401);
        // The code is machine-readable and never translated; the message is for the person.
        $response->assertJsonPath('error.code', 'invalid_credentials');
        $response->assertJsonPath('error.message', __('errors.invalid_credentials', [], 'fa'));
    }

    #[Test]
    public function a_refusal_from_deep_in_the_domain_is_translated_too(): void
    {
        $site = $this->hostedSite(['locale' => 'fa']);
        $event = app(TenantContext::class)->runUnscoped(
            fn () => \App\Models\Event::withoutGlobalScope('tenant')->orderBy('created_at')->firstOrFail()
        );

        // Not a sign-in refusal, which is the one everybody remembers to translate: this one is
        // thrown four layers down, in HoldService, and read by a buyer mid-checkout.
        $response = $this->postJson('http://northgate.localhost/_store/hold', [
            'event_public_id' => $event->public_id,
            'seat_ids' => [],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'no_seats');
        $response->assertJsonPath('error.message', __('errors.no_seats', [], 'fa'));
        // And it is really Persian, not the English literal the call site wrote.
        $this->assertStringNotContainsString('seat', $response->json('error.message'));
    }

    #[Test]
    public function a_translated_refusal_still_carries_its_numbers(): void
    {
        // A translation that drops :count says "at most tickets may be bought at once", which is
        // worse than English. tools/i18n-check.mjs guards the placeholder; this guards the filling.
        $filled = __('errors.ticket_type_max', ['count' => 4, 'type' => 'Child'], 'fa');

        $this->assertStringContainsString('4', $filled);
        $this->assertStringContainsString('Child', $filled);
        $this->assertStringNotContainsString(':count', $filled);
    }

    #[Test]
    public function a_language_we_do_not_speak_still_gets_a_readable_refusal(): void
    {
        $response = $this->withHeaders(['X-Seatmap-Locale' => 'zz'])
            ->postJson('/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'wrong']);

        $response->assertStatus(401);
        $response->assertJsonPath('error.message', __('errors.invalid_credentials', [], 'en'));
    }

    #[Test]
    public function the_browser_catalogue_is_served_for_every_language_we_claim(): void
    {
        foreach (Locales::codes() as $code) {
            $response = $this->getJson('/v1/i18n/'.$code);

            $response->assertOk();
            $response->assertJsonPath('locale', $code);
            $response->assertJsonPath('dir', Locales::direction($code));
            // The picker's vocabulary reaches the browser, or every seat is labelled in English.
            $this->assertNotEmpty($response->json('messages.site.picker.selectSeats'));
        }

        $this->getJson('/v1/i18n/zz')->assertStatus(404);
    }

    #[Test]
    public function money_follows_the_reader_and_the_currency_follows_the_event(): void
    {
        // Same amount, same currency, two readers. Only the writing changes.
        // Spaces are normalised before comparing: ICU separates an amount from its symbol with a
        // non-breaking space, which is correct typography and invisible in a diff.
        $this->assertSame('1.234,50 €', $this->plainSpaces(Money::format(123450, 'EUR', 'de')));
        $this->assertSame('€1,234.50', $this->plainSpaces(Money::format(123450, 'EUR', 'en')));

        // And a Persian reader looking at a euro-priced show sees euros, in Persian digits —
        // not tomans, and not the German way of writing a euro.
        $persian = Money::format(123450, 'EUR', 'fa');
        $this->assertStringContainsString('€', $persian);
        $this->assertStringContainsString('۱', $persian, 'Persian readers expect Persian digits.');
    }

    #[Test]
    public function a_currency_with_no_minor_unit_is_not_divided_by_a_hundred(): void
    {
        // 500,000 rial is 500,000 rial. The old symbol-and-divide-by-100 formatter called it 5,000,
        // which is the kind of bug that gets found at a box office rather than in a test.
        $this->assertSame(0, Money::exponent('IRR'));
        $this->assertStringContainsString('۵۰۰٬۰۰۰', Money::format(500000, 'IRR', 'fa'));

        // Three-decimal currencies exist too, and rounding them to two loses a real fils.
        $this->assertSame(3, Money::exponent('KWD'));
        $this->assertSame(1.234, Money::toDecimal(1234, 'KWD'));

        // And the round trip does not drift: 0.1 + 0.2 must not become 29 cents.
        $this->assertSame(30, Money::toMinorUnits(0.1 + 0.2, 'EUR'));
    }

    #[Test]
    public function a_date_follows_the_readers_calendar_as_well_as_their_language(): void
    {
        $when = new \DateTimeImmutable('2026-09-29 21:30', new \DateTimeZone('Europe/London'));

        // Persian is not "September in Persian words". It is a different calendar, and an Iranian
        // reader given 29 September has to convert it before it names a day they could turn up on.
        $persian = Dates::longWhen($when, 'fa');
        $this->assertStringContainsString('مهر', $persian);
        $this->assertStringContainsString('۱۴۰۵', $persian);
        $this->assertStringNotContainsString('2026', $persian);

        // Arabic-speaking countries keep the Gregorian calendar for civil dates, so Arabic gets
        // Arabic words and Arabic digits over the same year everyone else is using.
        $arabic = Dates::longWhen($when, 'ar');
        $this->assertStringContainsString('٢٠٢٦', $arabic);

        $this->assertStringContainsString('September', Dates::longWhen($when, 'en'));
        $this->assertStringContainsString('septembre', Dates::longWhen($when, 'fr'));
        $this->assertStringContainsString('settembre', Dates::longWhen($when, 'it'));

        // And no locale prints a month name in someone else's language.
        $this->assertStringNotContainsString('September', Dates::longWhen($when, 'fr'));

        $this->assertSame('', Dates::longWhen(null, 'en'), 'A missing date is blank, not a crash.');
    }

    #[Test]
    public function every_language_we_claim_has_a_direction_and_an_icu_locale(): void
    {
        foreach (Locales::codes() as $code) {
            $this->assertContains(Locales::direction($code), ['ltr', 'rtl']);
            // Numbering system and calendar are pinned, so formatting does not depend on which
            // server answered.
            $this->assertStringContainsString('-u-nu-', Locales::icu($code));
            $this->assertStringContainsString('-ca-', Locales::icu($code));
            $this->assertNotSame('', Dates::longWhen(new \DateTimeImmutable('2026-01-01'), $code));
            $this->assertNotSame('', Money::format(1000, 'EUR', $code));
        }

        $this->assertTrue(Locales::isRtl('fa'));
        $this->assertTrue(Locales::isRtl('ar'));
        $this->assertFalse(Locales::isRtl('de'));
    }

    #[Test]
    public function a_regional_tag_resolves_to_the_language_we_ship(): void
    {
        // We ship one Persian. `fa-IR`, `fa_IR` and `FA` all mean it.
        $this->assertSame('fa', Locales::normalise('fa-IR'));
        $this->assertSame('fa', Locales::normalise('fa_IR'));
        $this->assertSame('fa', Locales::normalise('FA'));
        $this->assertNull(Locales::normalise('zz-ZZ'));
        $this->assertNull(Locales::normalise(''));
    }

    /** ICU uses non-breaking and narrow spaces around currency marks; comparisons should not care. */
    private function plainSpaces(string $value): string
    {
        return str_replace(["\u{00A0}", "\u{202F}", "\u{200F}", "\u{200E}"], [' ', ' ', '', ''], $value);
    }

    /** A live hosted site at northgate.test, with one sellable event on it. */
    private function hostedSite(array $attributes = []): Site
    {
        $fixture = $this->makeSellableEvent();
        $tenant = $fixture['tenant'];

        return app(TenantContext::class)->runAs($tenant, function () use ($tenant, $attributes) {
            $site = app(SiteProvisioner::class)->create($tenant->name);

            SiteDomain::create([
                'site_id' => $site->id,
                'hostname' => 'northgate.localhost',
                'is_primary' => true,
                'verification_token' => SiteDomain::newToken(),
                'verified_at' => now(),
            ]);

            $site->update($attributes + ['status' => 'live']);

            return $site->fresh();
        });
    }
}
