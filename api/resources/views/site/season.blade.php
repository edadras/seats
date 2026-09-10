@extends('site.layout')

@section('content')
    <section class="shell section season">
        <div class="section__head">
            <div>
                <span class="eyebrow">{{ $site->name }}</span>
                <h1 class="section__title" dir="auto">{{ $pass->name }}</h1>
            </div>
        </div>

        @if ($pass->description)
            <p class="prose" dir="auto">{{ $pass->description }}</p>
        @endif

        <p class="season__saving">
            @if ('percent' === $pass->discount_kind)
                {{ __('site.season.savePercent', [
                    'percent' => \App\Support\Locale\Money::number($pass->discount_value),
                ]) }}
            @else
                {{ __('site.season.saveAmount', ['amount' => $money($pass->discount_value)]) }}
            @endif
        </p>

        <form method="POST" action="/season/{{ $pass->id }}">
            @csrf

            {{-- An inflexible pass is the whole run and says so; a flexible one asks which nights,
                 because "any six of twelve" is a promise the buyer has to be able to keep. --}}
            @if ($pass->isFlexible())
                <h2 class="season__subhead">{{ __('site.season.chooseNights', [
                    'count' => \App\Support\Locale\Money::number((int) $pass->nights),
                ]) }}</h2>
            @else
                <h2 class="season__subhead">{{ __('site.season.everyNight', [
                    'count' => \App\Support\Locale\Money::number(count($nights)),
                ]) }}</h2>
            @endif

            @error('nights') <p class="field__error">{{ $message }}</p> @enderror

            <ul class="season__nights">
                @foreach ($nights as $night)
                    <li class="season__night">
                        @if ($pass->isFlexible())
                            <label class="season__pick">
                                <input type="checkbox" name="nights[]" value="{{ $night['id'] }}"
                                       @checked(in_array($night['id'], $chosen, true) || ! count($chosen))>
                                <span>
                                    <strong dir="auto">{{ $night['name'] }}</strong>
                                    <span class="muted">{{ $night['when'] }}</span>
                                </span>
                            </label>
                        @else
                            <span>
                                <strong dir="auto">{{ $night['name'] }}</strong>
                                <span class="muted">{{ $night['when'] }}</span>
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>

            <p class="field__hint">{{ __('site.season.sameSeats') }}</p>

            <button class="button" type="submit">{{ __('site.season.chooseSeats') }}</button>
        </form>
    </section>
@endsection
