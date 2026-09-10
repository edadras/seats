<?php

namespace App\Http\Controllers\Site;

use App\Domain\Privacy\Consents;
use App\Domain\Sites\Themes;
use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "Tell us to stop" — without a password, without an account, without an argument.
 *
 * The commonest way a mailing list becomes a complaint is that leaving it was harder than
 * reporting it. So this page needs no sign-in: the link in the footer of every marketing message
 * carries a signature over the account and the address, which is enough to prove the link came
 * from this platform and not enough to enumerate anybody.
 *
 * What it deliberately does not do:
 *
 * - It never says whether the address has ever bought anything, or ever been written to. A page
 *   that answered that would be a way to ask "is this person a customer of this theatre" about any
 *   address anybody cared to type — with a valid signature, which only the owner of the address
 *   ever receives.
 * - It offers no way to reach anything else. This is not the buyer's account (which exists, behind
 *   a sign-in): it is one question, answered, and a page that quietly became a second front door
 *   would be a front door with no lock on it.
 */
class PreferencesController extends Controller
{
    public function __construct(private readonly Consents $consents) {}

    public function show(Request $request, string $email, string $token)
    {
        [$site, $address] = $this->check($request, $email, $token);

        return $this->render($site, $address, $token, $this->consents->allows($address));
    }

    /**
     * Change the answer.
     *
     * Both directions from the same page: somebody who unsubscribes by mistake at eleven at night
     * should be able to undo it at eleven at night, and a page that only knew how to say no would
     * make them write to the box office to say yes again.
     */
    public function update(Request $request, string $email, string $token)
    {
        [$site, $address] = $this->check($request, $email, $token);

        $wants = $request->boolean('news');

        $this->consents->record(
            $address,
            $wants ? 'in' : 'out',
            'link',
            $request->ip(),
            // What they were shown, in the language they were shown it in — which is what an audit
            // asks for and what a checkbox on its own cannot say.
            __('site.consent.line'),
        );

        return $this->render($site, $address, $token, $wants, true);
    }

    /* --------------------------------------------------------------------------- internals */

    /** @return array{0: Site, 1: string} */
    private function check(Request $request, string $email, string $token): array
    {
        $site = $request->attributes->get('site');
        $address = Consents::normalise(urldecode($email));

        if (! $site || '' === $address) {
            throw new NotFoundHttpException('No such page.');
        }

        /*
         * A bad signature is a 404 rather than a 403.
         *
         * "Wrong token" tells somebody guessing that the address exists and only the token was
         * wrong. Nothing here should confirm that an address is known to this organiser.
         */
        if (! $this->consents->tokenIsGood((string) $site->tenant_id, $address, $token)) {
            throw new NotFoundHttpException('No such page.');
        }

        return [$site, $address];
    }

    private function render(Site $site, string $email, string $token, bool $wants, bool $saved = false)
    {
        return response()->view('site.preferences', [
            'site' => $site,
            'brand' => Themes::forSite($site),
            'title' => __('site.consent.title').' · '.$site->name,
            'description' => null,
            'canonical' => null,
            'image' => null,
            'jsonld' => null,
            'headerMenu' => $site->menuFor('header'),
            'footerMenu' => $site->menuFor('footer'),
            'email' => $email,
            'token' => $token,
            'wants' => $wants,
            'saved' => $saved,
        ]);
    }
}
