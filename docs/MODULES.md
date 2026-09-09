# Writing a module

A module adds a way to take money, a way to reach a buyer, a dataset to report on, a block, a theme,
a panel screen, or a reaction to something that happened. It does that through a fixed set of typed
extension points, and through nothing else.

That constraint is the point, and [ADR-0004](adr/0004-module-architecture.md) explains it: the
guarantees in [ADR-0002](adr/0002-seat-inventory-integrity.md) — that a seat is sold exactly once,
that seat state is derived and never stored — are the product. Code that could reach around them
would turn them into opinions.

## The shape of a module

```
modules/
└── Acme/
    └── SmsGateway/
        ├── module.json      the manifest
        ├── Provider.php     what it extends
        └── …                whatever else it needs
```

`Modules\` is mapped to `modules/` by PSR-4, so `modules/Acme/SmsGateway/Provider.php` declares
`namespace Modules\Acme\SmsGateway;`.

## The manifest

```json
{
    "key": "acme/sms-gateway",
    "name": "Acme SMS",
    "version": "1.0.0",
    "provider": "Modules\\Acme\\SmsGateway\\Provider",
    "extends": ["messaging"],
    "auto_enable": false,
    "settings": [
        { "key": "api_key",  "type": "secret",  "required": true },
        { "key": "sender",   "type": "string",  "required": true },
        { "key": "sandbox",  "type": "boolean", "default": false }
    ]
}
```

| Field | What it means |
| --- | --- |
| `key` | `vendor/name`, lowercase and hyphenated. It ends up in a URL, a database column and a translation key, so its shape is checked, not trusted. |
| `provider` | Must extend `App\Modules\ModuleProvider`. Checked when the manifest is read. |
| `extends` | One or more of `payments`, `messaging`, `reports`, `blocks`, `themes`, `panel`, `events`. Anything else is dropped. |
| `auto_enable` | On for an organiser who has never said otherwise. Ignored if any setting is required — "enabled and unconfigured" is worse than off. |
| `settings` | Typed configuration. `string`, `secret`, `boolean`, `integer`, `url`, `select`. |

### Secrets

A setting of type `secret` is encrypted at rest and **never returned by the API**. The panel is told
"set" or "not set" and offers to replace; saving a form that shows the mask leaves the stored value
alone. Your module receives the plaintext through `$this->context->setting('api_key')` and nowhere
else.

A gateway secret that can be read back from a panel session is a gateway secret that a stolen panel
session has. There is no endpoint that returns one, and there will not be.

## The provider

```php
namespace Modules\Acme\SmsGateway;

use App\Modules\Contracts\DeliveryResult;
use App\Modules\Contracts\MessageChannel;
use App\Modules\ModuleProvider;

class Provider extends ModuleProvider
{
    public function messaging(): array
    {
        return [new AcmeSms($this->context)];
    }

    /** Say so before an organiser finds out from a buyer. */
    public function validate(): array
    {
        return $this->context->has('api_key') ? [] : ['modules.errors.required'];
    }
}
```

`$this->context` is a `ModuleContext`: your manifest, the tenant id, your settings, and a logger.
It is deliberately not a service container. A module holding a container could resolve anything, and
"what may a module do" would stop being a question the manifest answers.

`validate()` returns translation keys for anything missing. The platform refuses to enable a module
while it returns anything, so the state where a gateway is switched on and cannot settle never
exists.

## What a module may not do

- **Touch the inventory.** No `Seat`, `Hold`, `Allocation`, `Ticket`, `SeatMapVersion`, `Checkin`,
  no `DB` facade, no Eloquent models of your own. `ModuleBoundaryTest` fails the build if a shipped
  module so much as names one. It is a lint, not a sandbox, and it is honest about that: it catches
  the mistake, not the attacker. What stops an attacker is that nobody can put code here without a
  deploy.
- **Register routes, migrations or bindings.** If your module needs a URL — a payment callback, a
  webhook — that is a new extension point and an ADR amendment, not something to smuggle in through
  a service provider.
- **Fail loudly.** A module that throws is caught, recorded against itself, and skipped. One broken
  module must not take a checkout page down.

## Failure, health, and being switched off

Failures are written to `module_failures` and shown in the panel as that module's health. Past
`SEATMAP_MODULE_MAX_FAILURES` inside `SEATMAP_MODULE_FAILURE_WINDOW` hours, the platform switches
the module off for that tenant and records why.

That threshold exists because the alternative is worse than either extreme. A module that fails
silently forever looks exactly like one that is working, right up to the evening three hundred
people arrive without their tickets. Being off is visible. Being broken is not.

## Translation

First-party modules keep their strings in `api/lang/<locale>/modules.php`, under
`modules.<vendor>.<name>`, so the same CI check that guards the other six languages guards them too
([ADR-0005](adr/0005-internationalisation.md)).

A third-party module registers its own namespace and ships its own catalogues. Every locale the
platform claims must be present, for the same reason: a module whose name renders as
`modules.acme.sms_gateway.name` is a module nobody translated.

## Writing a payment gateway

A gateway implements `App\Domain\Sites\Payments\PaymentGateway`: `begin()` starts a payment and
returns a `PaymentIntent`, `settle()` says what happened. Five first-party ones ship in `modules/`
— Zarinpal, IDPay, NextPay, Stripe, PayPal — and they are the worked examples.

Four things the platform does for you, and one it insists on:

- **Talking out.** Use `App\Domain\Sites\Payments\OutboundHttp`. A module gets a context, not a
  container, so it cannot reach for an HTTP client with whatever timeout it felt like; this one is
  bounded, retried once at the connection level only, and logs failures against your module's key.

- **Coming back.** `$context['callback_url']` is a URL on the organiser's own site that lands in
  `settle()` for your gateway. Do not build it yourself: five modules would have five opinions
  about what a site's address is.

- **Remembering.** Whatever `PaymentIntent` carries as its `reference` — an authority, a session
  id, a token — is written onto the order, and is in `$order->metadata['payment_reference']` when
  `settle()` is called. Settle from *that*, never from what the return request says: a return is a
  URL the buyer's browser followed, and a URL is something anybody can type.

- **Asking again.** `payments:reconcile` runs every ten minutes and calls `settle()` for pending
  orders whose buyer never came back. That is why the contract says settle must be safe to call
  twice — and why "already verified" from a gateway means **paid**, not failed.

- **Money.** Amounts arrive in the currency's minor unit, which is what this platform stores.
  Convert if your gateway wants something else, through `App\Support\Locale\Money`, and never
  by dividing by a hundred: the rial has no minor unit and the dinar has three.

## Writing a messaging channel

A channel implements `App\Modules\Contracts\MessageChannel`: it is handed a recipient, an already
rendered and already translated message, and says what happened. Five ship in `modules/` —
Kavenegar, SMS.ir, Twilio, Telegram, WhatsApp.

The one thing that matters more than the API call is which of the three answers you return:

- `DeliveryResult::sent($reference)` — carry the provider's own id if it gives you one; the
  delivery log is what somebody reads at a window when a buyer says nothing arrived.
- `DeliveryResult::refused($reason)` — the provider understood and said no. **Never retried.** A
  landline is still a landline tomorrow, and asking again is how an account gets rate-limited for
  messaging people who said no.
- `DeliveryResult::unavailable($reason)` — the provider could not be reached. **Always retried**,
  by `messages:retry`, up to four attempts.

Collapsing those two means either giving up on messages that would have gone, or hammering a
provider that has already refused. Read the status code rather than guessing: a 4xx is usually
about the message, a 5xx about the provider.

`addressKind()` says what a recipient looks like — `email`, `phone` or `handle` — so a buyer who
has no address of that kind is simply not sent that one, rather than sent something that cannot
arrive.

## Installing one

There is no upload. A module is installed by putting it in `modules/` and deploying — an operator's
act. Enabling it is a separate act, done per organiser in the panel, and it is theirs.

Conflating those two is how a platform ends up running code one customer chose inside another
customer's request.

## Testing yours

```bash
cd api
./vendor/bin/phpunit --filter ModuleBoundaryTest   # your module may not reach the inventory
./vendor/bin/phpunit --filter ModuleSystemTest     # the contract you are writing against
```

Write tests for your module beside it. `modules/Acme/SmsGateway/tests/` is picked up by the API's
`phpunit.xml`, and a module that arrives without them is a module the operator deploying it has to
trust on your word.
