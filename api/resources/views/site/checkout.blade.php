@extends('site.layout')

@section('content')
    <section class="shell section checkout">
        <h1>{{ __('site.checkout') }}</h1>

        <div class="checkout__grid">
            <form class="checkout__form" method="POST" action="/checkout">
                @csrf

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

                <button class="button button--primary checkout__submit" type="submit">{{ __('site.confirmBooking') }}</button>
            </form>

            <aside class="checkout__summary">
                <h2>{{ __('site.yourSeats') }}</h2>

                <ul class="summary-lines">
                    @foreach ($lines as $line)
                        <li><span>{{ $line['label'] }}</span><span>{{ $money($line['amount']) }}</span></li>
                    @endforeach
                </ul>

                <p class="summary-total"><span>{{ __('site.total') }}</span><span>{{ $money($total) }}</span></p>

                @if ($expires_at)
                    <p class="muted summary-hold" data-expires="{{ $expires_at->toIso8601String() }}">
                        {{ __('site.heldUntil', ['time' => \App\Support\Locale\Dates::time($expires_at->setTimezone($site->timezone))]) }}
                    </p>
                @endif
            </aside>
        </div>
    </section>
@endsection
