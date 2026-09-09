<?php

namespace Tests\Feature;

use App\Models\EmailVerification;
use App\Models\Plan;
use App\Models\Site;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Somebody sets up their own account.
 *
 * The thing worth testing is that one request leaves nothing half done — a person with no
 * organiser, or an organiser with no subscription, is an account that works until the first time
 * somebody looks at it.
 */
class SignupTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Plan::factory()->create(['key' => 'starter', 'name' => 'Starter', 'price_amount' => 0, 'is_active' => true]);
        Plan::factory()->create(['key' => 'pro', 'name' => 'Pro', 'price_amount' => 4900, 'is_active' => true]);
    }

    #[Test]
    public function one_request_leaves_an_account_that_works(): void
    {
        Mail::fake();

        $body = $this->postJson('/v1/signup', [
            'organisation' => 'Harbour Playhouse',
            'name' => 'Mina Karimi',
            'email' => 'Mina@Harbour.test',
            'password' => 'correct horse battery',
            'locale' => 'fa',
            'timezone' => 'Asia/Tehran',
            'plan' => 'pro',
        ])->assertCreated()->json();

        $this->assertFalse($body['email_verified']);
        $this->assertSame('pro', $body['plan']);
        $this->assertSame('owner', $body['role']);

        $user = User::where('email', 'mina@harbour.test')->firstOrFail();
        $tenant = Tenant::where('slug', 'harbour-playhouse')->firstOrFail();

        $this->assertSame('fa', $user->locale, 'They signed up in Persian, so that is their language.');
        $this->assertSame('Asia/Tehran', $tenant->timezone);

        app(TenantContext::class)->runAs($tenant, function () use ($user) {
            $this->assertSame('owner', TenantUser::where('user_id', $user->id)->value('role'));
            $this->assertSame('active', Subscription::first()?->status, 'Nobody is left without a plan.');
            $this->assertSame('draft', Site::first()?->status, 'A website to look at from the first minute.');
        });

        // And the token that came back is a working one.
        $this->withToken($body['token'])->getJson('/v1/events')->assertOk();
    }

    #[Test]
    public function two_venues_with_the_same_name_get_different_addresses(): void
    {
        Mail::fake();

        foreach (['a@example.test', 'b@example.test'] as $email) {
            $this->postJson('/v1/signup', [
                'organisation' => 'The Playhouse',
                'name' => 'Someone',
                'email' => $email,
                'password' => 'correct horse battery',
            ])->assertCreated();
        }

        // Scoped to the two just made rather than to the whole table: the concurrency test commits
        // its fixtures on purpose, so "the database is otherwise empty" is not a fact to lean on.
        $slugs = Tenant::where('name', 'The Playhouse')->orderBy('created_at')->pluck('slug')->all();

        $this->assertSame(['the-playhouse', 'the-playhouse-2'], $slugs);
    }

    #[Test]
    public function an_address_that_already_has_an_account_is_told_so(): void
    {
        Mail::fake();

        $this->postJson('/v1/signup', [
            'organisation' => 'One', 'name' => 'A', 'email' => 'taken@example.test',
            'password' => 'correct horse battery',
        ])->assertCreated();

        $this->postJson('/v1/signup', [
            'organisation' => 'Two', 'name' => 'B', 'email' => 'taken@example.test',
            'password' => 'correct horse battery',
        ])->assertStatus(409)->assertJsonPath('error.code', 'email_taken');

        $this->assertSame(1, User::where('email', 'taken@example.test')->count());
    }

    #[Test]
    public function a_short_password_is_refused(): void
    {
        Mail::fake();

        $this->postJson('/v1/signup', [
            'organisation' => 'Short', 'name' => 'A', 'email' => 'short@example.test',
            'password' => 'hunter2',
        ])->assertStatus(422);
    }

    #[Test]
    public function the_code_verifies_the_address_once(): void
    {
        Mail::fake();

        $this->postJson('/v1/signup', [
            'organisation' => 'Codes', 'name' => 'A', 'email' => 'codes@example.test',
            'password' => 'correct horse battery',
        ])->assertCreated();

        $user = User::where('email', 'codes@example.test')->firstOrFail();
        $record = EmailVerification::where('user_id', $user->id)->firstOrFail();

        // The plaintext is only in the email; the row keeps a hash. So the test does what a
        // person does: takes the code, and checks the hash matches.
        $code = $this->codeMatching($record->code_hash);

        $this->postJson('/v1/signup/verify', ['email' => 'codes@example.test', 'code' => $code])
            ->assertOk()
            ->assertJsonPath('email_verified', true);

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertSame(0, EmailVerification::where('user_id', $user->id)->count(), 'A used code is gone.');
    }

    #[Test]
    public function a_wrong_code_costs_an_attempt_and_six_wrong_ones_end_it(): void
    {
        Mail::fake();

        $this->postJson('/v1/signup', [
            'organisation' => 'Guessers', 'name' => 'A', 'email' => 'guess@example.test',
            'password' => 'correct horse battery',
        ])->assertCreated();

        $user = User::where('email', 'guess@example.test')->firstOrFail();

        for ($i = 0; $i < EmailVerification::MAX_ATTEMPTS; $i++) {
            $this->postJson('/v1/signup/verify', ['email' => 'guess@example.test', 'code' => '000000'])
                ->assertStatus(422);
        }

        // Six digits is guessable given unlimited goes. The cap is what makes it not.
        $this->postJson('/v1/signup/verify', ['email' => 'guess@example.test', 'code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'code_expired');

        $this->assertNull($user->fresh()->email_verified_at);
    }

    #[Test]
    public function asking_for_a_new_code_invalidates_the_old_one(): void
    {
        Mail::fake();

        $this->postJson('/v1/signup', [
            'organisation' => 'Resend', 'name' => 'A', 'email' => 'resend@example.test',
            'password' => 'correct horse battery',
        ])->assertCreated();

        $user = User::where('email', 'resend@example.test')->firstOrFail();
        $first = $this->codeMatching(EmailVerification::where('user_id', $user->id)->value('code_hash'));

        $this->postJson('/v1/signup/resend', ['email' => 'resend@example.test'])->assertOk();

        $this->postJson('/v1/signup/verify', ['email' => 'resend@example.test', 'code' => $first])
            ->assertStatus(422);

        $second = $this->codeMatching(EmailVerification::where('user_id', $user->id)->value('code_hash'));

        $this->postJson('/v1/signup/verify', ['email' => 'resend@example.test', 'code' => $second])->assertOk();
    }

    #[Test]
    public function a_resend_never_says_whether_an_account_exists(): void
    {
        Mail::fake();

        $this->postJson('/v1/signup/resend', ['email' => 'nobody@example.test'])
            ->assertOk()
            ->assertJsonPath('sent', true);
    }

    #[Test]
    public function an_unverified_account_may_build_anything_and_publish_nothing(): void
    {
        Mail::fake();

        $body = $this->postJson('/v1/signup', [
            'organisation' => 'Unverified', 'name' => 'A', 'email' => 'unverified@example.test',
            'password' => 'correct horse battery',
        ])->assertCreated()->json();

        // Building: fine.
        $this->withToken($body['token'])->postJson('/v1/venues', ['name' => 'Main hall'])->assertCreated();

        // Publishing to the public internet: not until we know the address is real.
        $this->withToken($body['token'])
            ->patchJson('/v1/sites/'.$body['site_id'], ['status' => 'live'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'email_unverified');
    }

    #[Test]
    public function the_plans_on_offer_are_public(): void
    {
        $body = $this->getJson('/v1/plans')->assertOk()->json();

        $keys = array_column($body['data'], 'key');

        $this->assertContains('starter', $keys);
        $this->assertContains('pro', $keys);
        $this->assertLessThan(
            array_search('pro', $keys, true),
            array_search('starter', $keys, true),
            'Cheapest first.'
        );
    }

    /** Find the six-digit code behind a hash. Only a test would do this; only a test can. */
    private function codeMatching(string $hash): string
    {
        for ($i = 0; $i < 1000000; $i++) {
            $candidate = str_pad((string) $i, 6, '0', STR_PAD_LEFT);

            if (hash_equals($hash, EmailVerification::hash($candidate))) {
                return $candidate;
            }
        }

        $this->fail('No code matched the stored hash.');
    }
}
