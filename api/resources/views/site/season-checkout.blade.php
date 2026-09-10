@extends('site.layout')

@section('content')
    <section class="shell section checkout">
        <div class="section__head">
            <div>
                <span class="eyebrow" dir="auto">{{ $pass->name }}</span>
                <h1 class="section__title">{{ __('site.season.checkout') }}</h1>
            </div>
        </div>

        @if (session('seatmap_message'))
            <p class="notice">{{ session('seatmap_message') }}</p>
        @endif

        <div class="checkout__grid">
            <form class="checkout__form" method="POST" action="/season/checkout">
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

                <div class="checkout__step" @if ($total < 1) hidden @endif>
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

                <button class="button button--block checkout__submit" type="submit">
                    {{ __('site.season.confirm') }}
                </button>
            </form>

            <aside class="checkout__summary">
                <h2>{{ __('site.season.yourRun') }}</h2>

                <p class="checkout__entry">
                    <strong>{{ __('site.season.seatsPerNight', [
                        'count' => \App\Support\Locale\Money::number($seats),
                    ]) }}</strong>
                    <span>{{ __('site.season.nightsCount', [
                        'count' => \App\Support\Locale\Money::number(count($nights)),
                    ]) }}</span>
                </p>

                {{-- Every night, priced on its own. A Tuesday that costs less costs less here too:
                     the pass says what comes off the run, never what a seat is worth. --}}
                <ul class="summary-lines">
                    @foreach ($nights as $night)
                        <li>
                            <span dir="auto">{{ $night['name'] }}
                                <span class="summary-lines__note">{{ $night['when'] }}</span>
                            </span>
                            <span>{{ $money($night['total']) }}</span>
                        </li>
                    @endforeach

                    @if ($discount)
                        <li class="summary-lines__off">
                            <span>{{ __('site.season.saving') }}</span>
                            <span>−{{ $money($discount) }}</span>
                        </li>
                    @endif
                </ul>

                <p class="summary-total">
                    <span>{{ __('site.total') }}</span>
                    <span id="summary-total">{{ $money($total) }}</span>
                </p>

                {{-- Its own form, outside the one that pays: leaving a subscription must never be
                     one mis-aimed press away from buying it. --}}
                <form class="promo" method="POST" action="/season/leave">
                    @csrf
                    <button class="button button--quiet" type="submit">{{ __('site.season.leave') }}</button>
                </form>

                @if ($expires_at)
                    <p class="summary-hold" data-expires="{{ $expires_at->toIso8601String() }}">
                        {{ __('site.heldUntil', ['time' => \App\Support\Locale\Dates::time($expires_at->setTimezone($site->timezone))]) }}
                    </p>
                @endif
            </aside>
        </div>
    </section>
@endsection
