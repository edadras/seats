<?php

namespace Tests\Feature;

use App\Domain\Auth\TwoFactor;
use App\Support\Auth\Totp;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * A second step at the door of an account that can move money.
 *
 * The properties worth guarding: a half-finished enrolment leaves the account exactly as usable as
 * it was; the challenge handed out mid-sign-in is worth nothing on its own and is spent whatever
 * the answer; and a recovery code works once.
 */
class TwoFactorTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function the_rfc_test_vector_is_reproduced(): void
    {
        // RFC 6238 appendix B: the secret "12345678901234567890" at T=59 gives 94287082, of which
        // six digits is 287082. A homegrown implementation that cannot do this is a homegrown
        // implementation nobody's phone will agree with.
        $this->assertSame('287082', Totp::at('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', intdiv(59, 30)));
    }

    #[Test]
    public function starting_but_not_finishing_leaves_the_account_alone(): void
    {
        $fixture = $this->makeSellableEvent();
        $user = $this->makeUser($fixture['tenant']);

        $this->actingAs($user)->postJson('/v1/auth/two-factor')->assertOk()
            ->assertJsonStructure(['secret', 'uri', 'qr']);

        // Abandoned halfway: still signs in with a password alone.
        $this->postJson('/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('two_factor', false);
    }

    #[Test]
    public function once_confirmed_a_password_is_not_enough(): void
    {
        $fixture = $this->makeSellableEvent();
        $user = $this->makeUser($fixture['tenant']);

        $codes = $this->enrol($user);

        $challenge = $this->postJson('/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('two_factor_required', true)
            ->assertJsonMissing(['token'])
            ->json('challenge');

        // A wrong code does not get in, and spends the challenge: a handle that survived a wrong
        // guess would be a handle worth guessing against.
        $this->postJson('/v1/auth/login/two-factor', [
            'challenge' => $challenge,
            'code' => '000000',
        ])->assertStatus(401);

        $this->postJson('/v1/auth/login/two-factor', [
            'challenge' => $challenge,
            'code' => Totp::at($user->fresh()->totp_secret, intdiv(time(), 30)),
        ])->assertStatus(401)->assertJsonPath('error.code', 'challenge_expired');

        // A fresh sign-in, answered correctly, does.
        $challenge = $this->postJson('/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json('challenge');

        $this->postJson('/v1/auth/login/two-factor', [
            'challenge' => $challenge,
            'code' => Totp::at($user->fresh()->totp_secret, intdiv(time(), 30)),
        ])->assertOk()->assertJsonStructure(['token'])->assertJsonPath('two_factor', true);

        $this->assertCount(TwoFactor::RECOVERY_CODES, $codes);
    }

    #[Test]
    public function a_recovery_code_works_once(): void
    {
        $fixture = $this->makeSellableEvent();
        $user = $this->makeUser($fixture['tenant']);

        $codes = $this->enrol($user);

        $signIn = fn (string $code) => $this->postJson('/v1/auth/login/two-factor', [
            'challenge' => $this->postJson('/v1/auth/login', [
                'email' => $user->email,
                'password' => 'password',
            ])->json('challenge'),
            'code' => $code,
        ]);

        $signIn($codes[0])->assertOk();
        // Spent. Somebody who has lost their phone gets in, and that code cannot let anybody else in.
        $signIn($codes[0])->assertStatus(401);
        $signIn($codes[1])->assertOk();

        $this->assertSame(
            TwoFactor::RECOVERY_CODES - 2,
            count($user->fresh()->recovery_codes),
        );
    }

    #[Test]
    public function turning_it_off_takes_a_code_and_the_password(): void
    {
        $fixture = $this->makeSellableEvent();
        $user = $this->makeUser($fixture['tenant']);

        $this->enrol($user);

        $code = fn () => Totp::at($user->fresh()->totp_secret, intdiv(time(), 30));

        // A borrowed tab has the session and neither of these.
        $this->actingAs($user)->deleteJson('/v1/auth/two-factor', [
            'code' => $code(), 'password' => 'not-the-password',
        ])->assertStatus(422);

        $this->actingAs($user)->deleteJson('/v1/auth/two-factor', [
            'code' => '000000', 'password' => 'password',
        ])->assertStatus(422);

        $this->assertTrue($user->fresh()->hasTwoFactor());

        $this->actingAs($user)->deleteJson('/v1/auth/two-factor', [
            'code' => $code(), 'password' => 'password',
        ])->assertOk();

        $this->assertFalse($user->fresh()->hasTwoFactor());
    }

    #[Test]
    public function an_account_can_require_it_but_not_before_its_owner_has_it(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        // Requiring it of everybody while not having it yourself locks yourself out.
        $this->actingAs($owner)->postJson('/v1/account/two-factor-requirement', ['required' => true])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'set_up_yours_first');

        $this->enrol($owner);

        $this->actingAs($owner)->postJson('/v1/account/two-factor-requirement', ['required' => true])
            ->assertOk()
            ->assertJsonPath('required_by_account', true);

        // And nobody can then switch their own off while the account requires it.
        $this->actingAs($owner)->deleteJson('/v1/auth/two-factor', [
            'code' => Totp::at($owner->fresh()->totp_secret, intdiv(time(), 30)),
            'password' => 'password',
        ])->assertStatus(409)->assertJsonPath('error.code', 'two_factor_required');
    }

    #[Test]
    public function somebody_without_it_is_told_to_set_it_up(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $staff = $this->makeUser($fixture['tenant'], 'manager');

        $this->enrol($owner);
        $this->actingAs($owner)->postJson('/v1/account/two-factor-requirement', ['required' => true])
            ->assertOk();

        // They get in — refusing would leave them no way to comply — and are told to finish.
        $this->postJson('/v1/auth/login', [
            'email' => $staff->email,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('must_set_up_two_factor', true);
    }

    #[Test]
    public function the_secret_never_leaves_in_a_payload(): void
    {
        $fixture = $this->makeSellableEvent();
        $user = $this->makeUser($fixture['tenant']);

        $this->enrol($user);

        $body = $this->actingAs($user)->getJson('/v1/auth/two-factor')->assertOk()->getContent();

        $this->assertStringNotContainsString($user->fresh()->totp_secret, $body);
        $this->assertStringNotContainsString('recovery_codes', str_replace('recovery_codes_left', '', $body));
    }

    /* ------------------------------------------------------------------------------ helpers */

    /** @return list<string> the recovery codes */
    private function enrol($user): array
    {
        $this->actingAs($user)->postJson('/v1/auth/two-factor')->assertOk();

        $secret = app(TenantContext::class)->runUnscoped(fn () => $user->fresh()->totp_secret);

        return $this->actingAs($user)->postJson('/v1/auth/two-factor/confirm', [
            'code' => Totp::at($secret, intdiv(time(), 30)),
        ])->assertOk()->json('recovery_codes');
    }
}
