@extends('site.layout')

@section('content')
    <section class="shell section checkout">
        <h1>Checkout</h1>

        <div class="checkout__grid">
            <form class="checkout__form" method="POST" action="/checkout">
                @csrf

                <h2>Who are the tickets for?</h2>

                <div class="field">
                    <label for="name">Name</label>
                    <input id="name" name="name" value="{{ old('name') }}" required autocomplete="name">
                    @error('name') <p class="field__error">{{ $message }}</p> @enderror
                </div>

                <div class="field">
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email">
                    <p class="field__hint">Your tickets are sent here.</p>
                    @error('email') <p class="field__error">{{ $message }}</p> @enderror
                </div>

                <div class="field">
                    <label for="phone">Phone <span class="muted">(optional)</span></label>
                    <input id="phone" name="phone" value="{{ old('phone') }}" autocomplete="tel">
                </div>

                <h2>How would you like to pay?</h2>

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

                <button class="button button--primary checkout__submit" type="submit">Confirm booking</button>
            </form>

            <aside class="checkout__summary">
                <h2>Your seats</h2>

                <ul class="summary-lines">
                    @foreach ($lines as $line)
                        <li><span>{{ $line['label'] }}</span><span>{{ $money($line['amount']) }}</span></li>
                    @endforeach
                </ul>

                <p class="summary-total"><span>Total</span><span>{{ $money($total) }}</span></p>

                @if ($expires_at)
                    <p class="muted summary-hold" data-expires="{{ $expires_at->toIso8601String() }}">
                        Held until {{ $expires_at->setTimezone($site->timezone)->format('H:i') }}.
                    </p>
                @endif
            </aside>
        </div>
    </section>
@endsection
