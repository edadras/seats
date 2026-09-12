<?php

namespace Tests\Feature;

use App\Domain\Media\MediaLibrary;
use App\Models\MediaFile;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Files an organiser uploaded.
 *
 * What is pinned here is mostly about *what is served back from the venue's own domain*. An upload
 * field is the one place a person outside this codebase hands the server a file and the server
 * hands it to everybody else, so the type is decided from the bytes rather than from anything the
 * request said about them, and the list of acceptable types is a list.
 *
 * The rest is the arithmetic that keeps a library usable: the same poster dragged onto four events
 * is one file, and a photograph off a phone is not served at forty-eight megapixels.
 */
class MediaLibraryTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A disk of its own per test, so nothing is left behind in `storage/` and no test can read
        // a file another one wrote.
        Storage::fake(config('media.disk'));
    }

    #[Test]
    public function a_picture_is_kept_and_comes_back_as_an_address(): void
    {
        ['tenant' => $tenant] = $this->makeSellableEvent();
        $owner = $this->makeUser($tenant, 'owner');

        $body = $this->asMember($owner)
            ->post('/v1/media', ['file' => $this->picture(900, 600)])
            ->assertCreated()
            ->json('media');

        $this->assertSame('image', $body['kind']);
        $this->assertSame(900, $body['width']);
        $this->assertSame(600, $body['height']);
        $this->assertStringContainsString('/media/'.$body['id'].'/', $body['url']);

        // And the address answers, to somebody who is not signed in at all: this is a poster on a
        // website.
        $this->get('/media/'.$body['id'].'/poster.jpg')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * The one that matters.
     *
     * An SVG is a document that can carry script, and it would be served from the same domain the
     * venue's staff sign in to. There is no version of allowing it that is worth what it costs, and
     * renaming one to `.png` is the obvious way somebody would try.
     */
    #[Test]
    public function a_drawing_that_can_carry_script_is_refused_however_it_is_named(): void
    {
        ['tenant' => $tenant] = $this->makeSellableEvent();
        $owner = $this->makeUser($tenant, 'owner');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

        /*
         * A real upload rather than `UploadedFile::fake()`, deliberately.
         *
         * The fake answers `getMimeType()` with whatever it was told, which is precisely the thing
         * under test — a check against a declared type would pass against the double and fail
         * against a browser. This is the shape of the attack: the request says `image/png`, the
         * bytes say otherwise, and the bytes are what gets served back.
         */
        $path = tempnam(sys_get_temp_dir(), 'svg');
        file_put_contents($path, $svg);

        $response = $this->asMember($owner)->post('/v1/media', [
            'file' => new UploadedFile($path, 'logo.png', 'image/png', null, true),
        ]);

        $response->assertStatus(422);
        $this->assertSame('media_type_refused', $response->json('error.code'));
        $this->assertSame(0, MediaFile::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_file_that_is_not_a_picture_or_a_film_is_refused(): void
    {
        ['tenant' => $tenant] = $this->makeSellableEvent();
        $owner = $this->makeUser($tenant, 'owner');

        $this->asMember($owner)
            ->post('/v1/media', [
                'file' => UploadedFile::fake()->createWithContent('takings.csv', "a,b\n1,2\n"),
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function the_same_poster_dragged_on_twice_is_one_file(): void
    {
        ['tenant' => $tenant] = $this->makeSellableEvent();
        $owner = $this->makeUser($tenant, 'owner');

        $first = $this->asMember($owner)
            ->post('/v1/media', ['file' => $this->picture(400, 400, 'poster.jpg')])
            ->assertCreated()->json('media.id');

        $second = $this->asMember($owner)
            ->post('/v1/media', ['file' => $this->picture(400, 400, 'poster-copy.jpg')])
            ->assertCreated()->json('media.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, MediaFile::withoutGlobalScopes()->count());
    }

    /**
     * A photograph off a phone is not served at the size it was taken.
     *
     * The cap is what separates a hero image from nine seconds of loading at a bus stop, and it is
     * applied on the way in rather than by a `width` attribute, because the bytes are what travel.
     */
    #[Test]
    public function a_photograph_is_brought_down_to_a_size_a_page_can_use(): void
    {
        ['tenant' => $tenant] = $this->makeSellableEvent();
        $owner = $this->makeUser($tenant, 'owner');

        config(['media.longest_side' => 800]);

        $body = $this->asMember($owner)
            ->post('/v1/media', ['file' => $this->picture(2400, 1200)])
            ->assertCreated()->json('media');

        $this->assertSame(800, $body['width']);
        $this->assertSame(400, $body['height']);
    }

    #[Test]
    public function a_file_larger_than_the_limit_is_refused_with_the_limit_in_the_sentence(): void
    {
        ['tenant' => $tenant] = $this->makeSellableEvent();
        $owner = $this->makeUser($tenant, 'owner');

        config(['media.max_image_megabytes' => 1]);

        $response = $this->asMember($owner)->post('/v1/media', [
            'file' => $this->picture(400, 400)->size(4096),
        ]);

        $response->assertStatus(422);
        $this->assertSame('media_too_large', $response->json('error.code'));
        $this->assertStringContainsString('1', (string) $response->json('error.message'));
    }

    #[Test]
    public function one_accounts_library_is_not_anothers(): void
    {
        ['tenant' => $mine] = $this->makeSellableEvent();
        ['tenant' => $theirs] = $this->makeSellableEvent();

        $this->asMember($this->makeUser($mine, 'owner'))
            ->post('/v1/media', ['file' => $this->picture(300, 300)])
            ->assertCreated();

        $this->assertCount(0, $this->asMember($this->makeUser($theirs, 'owner'))
            ->getJson('/v1/media')->assertOk()->json('data'));
    }

    #[Test]
    public function a_door_volunteer_has_no_field_to_put_a_picture_in_and_may_not_upload_one(): void
    {
        ['tenant' => $tenant] = $this->makeSellableEvent();

        $this->asMember($this->makeUser($tenant, 'door'))
            ->post('/v1/media', ['file' => $this->picture(300, 300)])
            ->assertForbidden();
    }

    #[Test]
    public function removing_a_file_takes_the_bytes_with_it(): void
    {
        ['tenant' => $tenant] = $this->makeSellableEvent();
        $owner = $this->makeUser($tenant, 'owner');

        $id = $this->asMember($owner)
            ->post('/v1/media', ['file' => $this->picture(300, 300)])
            ->assertCreated()->json('media.id');

        $path = app(TenantContext::class)->runAs(
            $tenant,
            fn () => MediaFile::findOrFail($id)->path,
        );

        Storage::disk(config('media.disk'))->assertExists($path);

        $this->asMember($owner)->deleteJson('/v1/media/'.$id)->assertOk();

        Storage::disk(config('media.disk'))->assertMissing($path);
        $this->assertSame(0, MediaFile::withoutGlobalScopes()->count());
    }

    /**
     * An account that leaves does not leave its posters behind.
     *
     * The rows cascade away with the account; the bytes are on a disk and would stay there for
     * ever. This is the reason every path starts with the account's own id.
     */
    #[Test]
    public function erasing_an_account_takes_its_pictures_with_it(): void
    {
        ['tenant' => $tenant] = $this->makeSellableEvent();
        $owner = $this->makeUser($tenant, 'owner');

        $this->asMember($owner)
            ->post('/v1/media', ['file' => $this->picture(300, 300)])
            ->assertCreated();

        $disk = Storage::disk(config('media.disk'));

        $this->assertNotEmpty($disk->allFiles($tenant->id));

        app(\App\Domain\Accounts\AccountClosure::class)->erase($tenant->fresh());

        $this->assertEmpty($disk->allFiles($tenant->id));
        $this->assertSame(0, MediaFile::withoutGlobalScopes()->count());
    }

    #[Test]
    public function an_address_nobody_uploaded_is_not_found(): void
    {
        $this->get('/media/'.\Illuminate\Support\Str::uuid()->toString().'/poster.jpg')
            ->assertNotFound();
    }

    /** A real JPEG of the size asked for, because the type is decided from the bytes. */
    private function picture(int $width, int $height, string $name = 'poster.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, $width, $height);
    }
}
