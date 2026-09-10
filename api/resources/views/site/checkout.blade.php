@extends('site.layout')

@section('content')
    <section class="shell section checkout">
        <div class="section__head">
            <div>
                <span class="eyebrow">{{ $site->name }}</span>
                <h1 class="section__title">{{ __('site.checkout') }}</h1>
            </div>
        </div>

        <div class="checkout__grid">
            <form class="checkout__form" method="POST" action="/checkout">
                @csrf

                <div class="checkout__step">
                    <h2>{{ __('site.whoFor') }}</h2>

                    <div class="field">
                        <label for="name">{{ __('site.name') }}</label>
                        <input id="name" name="name" value="{{ old('name') }}" required autocomplete="name">
                        @error('name') <p class="field__error">{{ $message }}</p> @enderror
                    </div>

                    <div class="field">
                        <label for="email">{{ __('site.email') }}</label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email">
                        <p class="field__hint">{{ __('site.emailHint') }}</p>
                        @error('email') <p class="field__error">{{ $message }}</p> @enderror
                    </div>

                    <div class="field">
                        <label for="phone">{{ __('site.phone') }} <span class="muted">{{ __('site.optional') }}</span></label>
                        <input id="phone" name="phone" value="{{ old('phone') }}" autocomplete="tel">
                    </div>
                </div>

                @if (count($questions))
                    {{-- The organiser's own questions. Their shape is data, not code, so the venue
                         that needs car registrations and the one that needs dietary requirements
                         get the same feature rather than two bespoke ones. --}}
                    <div class="checkout__step">
                        <h2>{{ __('site.questions.title') }}</h2>

                        @foreach ($questions as $question)
                            <div class="field">
                                <label for="{{ $question['name'] }}">
                                    {{ $question['label'] }}
                                    @if ($question['about'])
                                        <span class="muted">· {{ $question['about'] }}</span>
                                    @endif
                                    @unless ($question['required'])
                                        <span class="muted">{{ __('site.optional') }}</span>
                                    @endunless
                                </label>

                                @if ('choice' === $question['kind'])
                                    <select id="{{ $question['name'] }}" name="{{ $question['name'] }}"
                                            @required($question['required'])>
                                        <option value="">{{ __('site.questions.choose') }}</option>
                                        @foreach ($question['choices'] as $choice)
                                            <option value="{{ $choice }}"
                                                    @selected(old($question['name']) === $choice)>{{ $choice }}</option>
                                        @endforeach
                                    </select>
                                @elseif ('checkbox' === $question['kind'])
                                    <label class="pay">
                                        <input type="checkbox" name="{{ $question['name'] }}" value="1"
                                               @checked(old($question['name'])) @required($question['required'])>
                                        <span>{{ $question['help'] ?: $question['label'] }}</span>
                                    </label>
                                @else
                                    <input id="{{ $question['name'] }}" name="{{ $question['name'] }}"
                                           maxlength="2000" value="{{ old($question['name']) }}"
                                           @required($question['required'])>
                                @endif

                                @if ($question['help'] && 'checkbox' !== $question['kind'])
                                    <p class="field__hint">{{ $question['help'] }}</p>
                                @endif

                                @error($question['name']) <p class="field__error">{{ $message }}</p> @enderror
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($invoices)
                    {{-- A company address is a question most buyers cannot answer, so it is behind
                         a checkbox and closed by default. Native <details>: no JavaScript, and it
                         works on a phone on venue Wi-Fi. --}}
                    <details class="checkout__step invoice-ask">
                        <summary>{{ __('site.invoice.ask') }}</summary>

                        <div class="field">
                            <label for="company">{{ __('site.invoice.company') }}</label>
                            <input id="company" name="company" maxlength="160" value="{{ old('company') }}">
                        </div>

                        <div class="field">
                            <label for="tax_number">{{ __('site.invoice.taxNumberLabel') }}</label>
                            <input id="tax_number" name="tax_number" maxlength="60" value="{{ old('tax_number') }}">
                        </div>

                        <div class="field">
                            <label for="billing_address">{{ __('site.invoice.address') }}</label>
                            <textarea id="billing_address" name="billing_address" rows="3"
                                      maxlength="400">{{ old('billing_address') }}</textarea>
                        </div>

                        <input type="hidden" name="invoice" value="1">
                    </details>
                @endif

                @if (count($addons))
                    {{-- What else is for sale. Offered here rather than after paying, because
                         "would you like a programme" asked once the money has gone is a question
                         nobody comes back to answer. --}}
                    <div class="checkout__step">
                        <h2>{{ __('site.addons.title') }}</h2>

                        @if ($addonError)
                            <p class="field__error">{{ $addonError }}</p>
                        @endif

                        <div class="addons">
                            @foreach ($addons as $addon)
                                <div class="addon @if ($addon['sold_out']) addon--gone @endif">
                                    <div class="addon__what">
                                        <strong>{{ $addon['name'] }}</strong>
                                        @if ($addon['description'])
                                            <span class="muted">{{ $addon['description'] }}</span>
                                        @endif
                                        <span class="addon__price">
                                            {{ $money($addon['price']) }}
                                            @if ('ticket' === $addon['per'])
                                                <span class="muted">{{ __('site.addons.perTicket') }}</span>
                                            @endif
                                            @if (null !== $addon['remaining'] && ! $addon['sold_out'] && $addon['remaining'] <= 10)
                                                <span class="muted">{{ __('site.addons.left', ['count' => $addon['remaining']]) }}</span>
                                            @endif
                                        </span>
                                    </div>

                                    @if ($addon['sold_out'])
                                        <span class="addon__gone">{{ __('site.addons.soldOut') }}</span>
                                    @elseif ('ticket' === $addon['per'])
                                        {{-- One each, and not a choice: the number follows the
                                             tickets, so it is stated rather than asked for. --}}
                                        <span class="addon__fixed">×{{ $addon['quantity'] }}</span>
                                    @else
                                        <label class="addon__pick">
                                            <span class="visually-hidden">{{ $addon['name'] }}</span>
                                            <select name="addons[{{ $addon['id'] }}]">
                                                @for ($n = 0; $n <= $addon['max']; $n++)
                                                    <option value="{{ $n }}"
                                                        @selected((int) old('addons.'.$addon['id']) === $n)>{{ $n }}</option>
                                                @endfor
                                            </select>
                                        </label>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($donation)
                    {{-- A gift, which is why it carries no booking fee and no tax: charging
                         somebody for the privilege of giving you money is not a fee. --}}
                    <div class="checkout__step">
                        <h2>{{ __('site.donation.title') }}</h2>

                        @if ($donation['prompt'])
                            <p class="field__hint">{{ $donation['prompt'] }}</p>
                        @endif

                        <div class="field field--narrow">
                            <label for="donation">{{ __('site.donation.label', ['currency' => $currency]) }}</label>
                            {{-- In the currency the label names, not in its minor units: somebody
                                 typing 3 into a box marked EUR means three euros. --}}
                            <input id="donation" name="donation" type="number" min="0"
                                   step="{{ $donation['step'] }}" inputmode="decimal"
                                   value="{{ old('donation', $donation['typed']) }}">
                            <p class="field__hint">{{ __('site.donation.hint') }}</p>
                        </div>
                    </div>
                @endif

                <div class="checkout__step">
                    <h2>{{ __('site.howToPay') }}</h2>

                    @foreach ($gateways as $gateway)
                        <label class="pay">
                            <input type="radio" name="gateway" value="{{ $gateway->key() }}"
                                   @checked($loop->first || old('gateway') === $gateway->key())>
                            <span>
                                <strong>{{ $gateway->label() }}</strong>
                                <span class="muted">{{ $gateway->description() }}</span>
                            </span>
                        </label>
                    @endforeach
                    @error('gateway') <p class="field__error">{{ $message }}</p> @enderror
                </div>

                <button class="button button--block checkout__submit" type="submit">{{ __('site.confirmBooking') }}</button>
            </form>

            <aside class="checkout__summary">
                <h2>{{ __('site.yourSeats') }}</h2>

                @if ($entry)
                    {{-- Above the seats, because on a timed-entry event it is the more important
                         half of what was booked. --}}
                    <p class="checkout__entry">
                        <strong>{{ __('site.entry.title') }}</strong>
                        <span>{{ \App\Domain\Events\EntrySlots::window(
                            new \DateTimeImmutable($entry['starts_at']),
                            new \DateTimeImmutable($entry['ends_at']),
                            $hold->event?->timezone,
                        ) }}</span>
                    </p>
                @endif

                <ul class="summary-lines" id="summary-lines">
                    @foreach ($lines as $line)
                        <li>
                            <span>{{ $line['label'] }}
                                @if (! empty($line['note']))
                                    <span class="summary-lines__note">{{ $line['note'] }}</span>
                                @endif
                            </span>
                            <span>{{ $money($line['amount']) }}</span>
                        </li>
                    @endforeach

                    @if ($discount)
                        <li class="summary-lines__off">
                            <span>{{ __('site.discount.line', ['code' => $discount['code']]) }}</span>
                            <span>−{{ $money($discount['amount']) }}</span>
                        </li>
                    @endif

                    {{-- A booking fee is added to the total; an inclusive tax is already inside it
                         and is shown as a note, not as another thing to add up. Re-rendered by the
                         quote below when extras change — from the server's own arithmetic, never
                         from a second copy of it in the browser. --}}
                    @foreach ($extras as $extra)
                        <li class="summary-lines__extra">
                            <span>{{ $extra['label'] }}</span>
                            <span>{{ empty($extra['informational']) ? '' : '(' }}{{ $money($extra['amount']) }}{{ empty($extra['informational']) ? '' : ')' }}</span>
                        </li>
                    @endforeach
                </ul>

                <p class="summary-total"><span>{{ __('site.total') }}</span><span id="summary-total">{{ $money($total) }}</span></p>

                {{-- Its own form, outside the one that pays: pressing enter in a discount box must
                     try the code, never buy the tickets. --}}
                @if ($discount)
                    <form class="promo promo--applied" method="POST" action="/checkout/discount/remove">
                        @csrf
                        <p class="promo__held">
                            <strong>{{ $discount['code'] }}</strong>
                            <span class="muted">{{ __('site.discount.applied') }}</span>
                        </p>
                        <button class="button button--quiet" type="submit">{{ __('site.discount.remove') }}</button>
                    </form>
                @else
                    <form class="promo" method="POST" action="/checkout/discount">
                        @csrf
                        <label class="promo__label" for="discount-code">{{ __('site.discount.label') }}</label>
                        <div class="promo__row">
                            <input id="discount-code" name="code" maxlength="40" autocomplete="off"
                                   spellcheck="false" placeholder="{{ __('site.discount.placeholder') }}">
                            <button class="button button--quiet" type="submit">{{ __('site.discount.apply') }}</button>
                        </div>
                        @if ($discountError)
                            <p class="field__error">{{ $discountError }}</p>
                        @endif
                    </form>
                @endif

                @if ($expires_at)
                    <p class="summary-hold" data-expires="{{ $expires_at->toIso8601String() }}">
                        {{ __('site.heldUntil', ['time' => \App\Support\Locale\Dates::time($expires_at->setTimezone($site->timezone))]) }}
                    </p>
                @endif
            </aside>
        </div>
    </section>

    @if (count($addons) || $donation)
        @push('scripts')
            <script>
                /*
                 * Keep the summary honest while extras are chosen.
                 *
                 * The arithmetic is not repeated here: the page asks the server what the booking
                 * would come to and prints the answer. A total worked out in the browser would be
                 * a second implementation of fees, tax and the rule that a donation sits outside
                 * both — and the one bug a checkout must not have is a summary that disagrees with
                 * the charge.
                 *
                 * Failing quietly is deliberate. Nothing here is load-bearing: the total already
                 * on the page is the server's, the buyer's choices go with the form regardless,
                 * and the price they are charged is worked out again when they press pay.
                 */
                ( function () {
                    var form = document.querySelector( '.checkout__form' );
                    var list = document.getElementById( 'summary-lines' );
                    var totalEl = document.getElementById( 'summary-total' );

                    if ( ! form || ! list || ! totalEl ) {
                        return;
                    }

                    var pending = null;

                    function ask() {
                        var chosen = {};

                        form.querySelectorAll( '[name^="addons["]' ).forEach( function ( field ) {
                            var id = field.name.slice( 'addons['.length, -1 );

                            chosen[ id ] = parseInt( field.value, 10 ) || 0;
                        } );

                        var donation = form.querySelector( '[name="donation"]' );

                        fetch( '/checkout/quote', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'Content-Type': 'application/json',
                                Accept: 'application/json',
                                'X-CSRF-TOKEN': @json(csrf_token()),
                            },
                            body: JSON.stringify( {
                                addons: chosen,
                                donation: donation ? donation.value || 0 : 0,
                            } ),
                        } )
                            .then( function ( response ) {
                                return response.ok ? response.json() : null;
                            } )
                            .then( function ( quote ) {
                                if ( ! quote ) {
                                    return;
                                }

                                list.querySelectorAll( '.summary-lines__extra' )
                                    .forEach( function ( row ) { row.remove(); } );

                                ( quote.extras || [] ).forEach( function ( extra ) {
                                    var row = document.createElement( 'li' );
                                    var label = document.createElement( 'span' );
                                    var amount = document.createElement( 'span' );

                                    row.className = 'summary-lines__extra';
                                    label.textContent = extra.label;
                                    // An inclusive tax is already inside the total; bracketed, so
                                    // the column does not read as another thing to add up.
                                    amount.textContent = extra.informational
                                        ? '(' + extra.formatted + ')'
                                        : extra.formatted;

                                    row.appendChild( label );
                                    row.appendChild( amount );
                                    list.appendChild( row );
                                } );

                                totalEl.textContent = quote.total_formatted;
                            } )
                            .catch( function () {} );
                    }

                    function soon() {
                        window.clearTimeout( pending );
                        pending = window.setTimeout( ask, 250 );
                    }

                    form.querySelectorAll( '[name^="addons["], [name="donation"]' )
                        .forEach( function ( field ) {
                            field.addEventListener( 'change', soon );
                            field.addEventListener( 'input', soon );
                        } );
                }() );
            </script>
        @endpush
    @endif
@endsection
