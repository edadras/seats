<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Support\Locale\Calendars;
use App\Support\Locale\Dates;
use App\Support\Locale\Locales;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Which calendar a venue writes its dates in.
 *
 * The distinction this pins is the whole feature: the *language* is a fact about the reader, and the
 * *calendar* is a decision by the organisation. A Persian page is read by somebody who reads
 * Persian; a Jalali date is printed by a venue that programmes its season in Jalali, and an Iranian
 * theatre with an English page still does.
 *
 * Nothing here converts an instant. Everything is UTC in the database and a wall clock in the
 * venue's own zone on the way out; a calendar is a way of writing a moment down.
 */
class CalendarTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    protected function tearDown(): void
    {
        Calendars::forget();

        parent::tearDown();
    }

    #[Test]
    public function by_default_the_calendar_follows_the_language(): void
    {
        Calendars::forget();

        $this->assertSame('persian', Calendars::current('fa'));
        $this->assertSame('gregory', Calendars::current('en'));
    }

    #[Test]
    public function a_venue_that_keeps_jalali_prints_jalali_on_an_english_page(): void
    {
        $when = new \DateTimeImmutable('2026-09-30 20:30', new \DateTimeZone('UTC'));

        Calendars::use('persian');

        $written = Dates::longWhen($when, 'en');

        $this->assertStringContainsString('Mehr', $written);
        $this->assertStringContainsString('1405', $written);
        $this->assertStringNotContainsString('September', $written);
    }

    #[Test]
    public function and_a_venue_that_keeps_gregorian_prints_it_to_a_persian_reader(): void
    {
        $when = new \DateTimeImmutable('2026-09-30 20:30', new \DateTimeZone('UTC'));

        Calendars::use('gregory');

        $written = Dates::longWhen($when, 'fa');

        // Persian words and Persian digits, and the year every Gregorian reader knows.
        $this->assertStringContainsString('۲۰۲۶', $written);
        $this->assertStringNotContainsString('۱۴۰۵', $written);
    }

    #[Test]
    public function what_people_call_it_is_accepted_and_nonsense_is_not(): void
    {
        $this->assertSame('persian', Calendars::clean('jalali'));
        $this->assertSame('persian', Calendars::clean('SHAMSI'));
        $this->assertSame('gregory', Calendars::clean('gregorian'));
        $this->assertNull(Calendars::clean('mayan'));
        $this->assertNull(Calendars::clean(null));
    }

    /**
     * The instant is the same instant.
     *
     * Worth pinning because it is the thing a reader of this feature will worry about: nothing
     * stored moves, and two calendars are two ways of writing the same moment down.
     */
    #[Test]
    public function the_same_moment_is_written_two_ways_and_is_one_moment(): void
    {
        $when = new \DateTimeImmutable('2026-09-30 20:30', new \DateTimeZone('UTC'));

        $jalali = Calendars::runAs('persian', fn () => Dates::pattern($when, 'HH:mm', 'en'));
        $gregorian = Calendars::runAs('gregory', fn () => Dates::pattern($when, 'HH:mm', 'en'));

        $this->assertSame('20:30', $jalali);
        $this->assertSame($jalali, $gregorian);
    }

    #[Test]
    public function a_site_decides_what_its_visitors_read(): void
    {
        ['tenant' => $tenant] = $this->makeSellableEvent();
        $owner = $this->makeUser($tenant, 'owner');

        $site = app(TenantContext::class)->runAs(
            $tenant,
            fn () => app(\App\Domain\Sites\SiteProvisioner::class)->create($tenant->name),
        );

        $this->asMember($owner)
            ->patchJson('/v1/sites/'.$site->id, ['calendar' => 'jalali'])
            ->assertOk()
            ->assertJsonPath('calendar', 'persian');

        $this->assertSame('persian', $site->fresh()->calendar);
    }

    #[Test]
    public function a_calendar_nobody_has_heard_of_falls_back_rather_than_refusing(): void
    {
        ['tenant' => $tenant] = $this->makeSellableEvent();
        $owner = $this->makeUser($tenant, 'owner');

        $site = app(TenantContext::class)->runAs(
            $tenant,
            fn () => app(\App\Domain\Sites\SiteProvisioner::class)->create($tenant->name),
        );

        $this->asMember($owner)
            ->patchJson('/v1/sites/'.$site->id, ['calendar' => 'mayan'])
            ->assertOk()
            ->assertJsonPath('calendar', 'auto');
    }

    #[Test]
    public function the_account_decides_what_its_own_staff_read(): void
    {
        ['tenant' => $tenant] = $this->makeSellableEvent();
        $owner = $this->makeUser($tenant, 'owner');

        $this->asMember($owner)
            ->patchJson('/v1/account/calendar', ['calendar' => 'persian'])
            ->assertOk()
            ->assertJsonPath('calendar', 'persian');

        // And the panel is told, at sign-in, so its own JavaScript writes dates the same way.
        $this->asMember($owner)
            ->getJson('/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('tenant.calendar', 'persian');
    }

    #[Test]
    public function moving_the_whole_organisations_dates_is_not_a_clerks_decision(): void
    {
        ['tenant' => $tenant] = $this->makeSellableEvent();

        $this->asMember($this->makeUser($tenant, 'box_office'))
            ->patchJson('/v1/account/calendar', ['calendar' => 'persian'])
            ->assertForbidden();
    }

    /**
     * The language catalogue says nothing about an account.
     *
     * It is cached for a day and marked `public`, so anything account-shaped in it would reach the
     * next account through a shared cache — one venue's calendar applied to another's panel.
     */
    #[Test]
    public function the_cached_language_catalogue_carries_no_venues_choice(): void
    {
        Calendars::use('persian');

        $body = $this->getJson('/v1/i18n/en')->assertOk()->json();

        $this->assertStringContainsString('-ca-gregory', $body['icu']);
        $this->assertStringNotContainsString('persian', $body['icu']);
    }

    #[Test]
    public function the_locale_string_handed_to_a_browser_carries_the_calendar_in_force(): void
    {
        $this->assertStringContainsString(
            '-ca-persian',
            Calendars::runAs('persian', fn () => Locales::icu('en')),
        );
        $this->assertStringContainsString(
            '-ca-gregory',
            Calendars::runAs('gregory', fn () => Locales::icu('fa')),
        );
    }

    /**
     * A booking's confirmation is written in the site's calendar, not in the last one bound.
     *
     * Mail leaves from a queue worker, where a hundred confirmations for four venues go out in one
     * process and nothing has bound anything.
     */
    #[Test]
    public function work_for_one_venue_does_not_leave_its_calendar_on_the_next(): void
    {
        Calendars::use('gregory');

        $inside = Calendars::runAs('persian', fn () => Calendars::chosen());

        $this->assertSame('persian', $inside);
        $this->assertSame('gregory', Calendars::chosen());
    }

    #[Test]
    public function binding_an_account_binds_the_calendar_it_keeps(): void
    {
        ['tenant' => $tenant] = $this->makeSellableEvent();

        $tenant->forceFill(['calendar' => 'persian'])->saveQuietly();

        app(TenantContext::class)->runAs($tenant->fresh(), function () {
            $this->assertSame('persian', Calendars::chosen());
        });
    }

    #[Test]
    public function a_site_that_says_nothing_leaves_its_visitors_with_their_own_calendar(): void
    {
        ['tenant' => $tenant] = $this->makeSellableEvent();

        $site = app(TenantContext::class)->runAs(
            $tenant,
            fn () => app(\App\Domain\Sites\SiteProvisioner::class)->create($tenant->name),
        );

        $this->assertSame('auto', $site->calendar);
        $this->assertInstanceOf(Site::class, $site);
    }
}
