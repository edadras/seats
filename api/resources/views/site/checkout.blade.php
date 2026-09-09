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

                <ul class="summary-lines">
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
                         and is shown as a note, not as another thing to add up. --}}
                    @foreach ($extras as $extra)
                        <li class="summary-lines__extra">
                            <span>{{ $extra['label'] }}</span>
                            <span>{{ empty($extra['informational']) ? '' : '(' }}{{ $money($extra['amount']) }}{{ empty($extra['informational']) ? '' : ')' }}</span>
                        </li>
                    @endforeach
                </ul>

                <p class="summary-total"><span>{{ __('site.total') }}</span><span>{{ $money($total) }}</span></p>

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
@endsection
