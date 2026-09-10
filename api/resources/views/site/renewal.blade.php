@extends('site.layout')

@section('content')
    <section class="shell section section--tight">
        <h1 class="section__title">{{ __('site.renewal.title') }}</h1>

        @if ($error)
            <p class="notice">{{ $error }}</p>
        @endif

        @if ($declined)
            <p class="notice">{{ __('site.renewal.declined') }}</p>
        @endif

        {{-- The name of the run and the date the offer ends, in that order: a subscriber deciding
             needs to know what it is and how long they have, and nothing else on this page is more
             important than the date. --}}
        <p class="lede">{{ __('site.renewal.about', ['run' => $round?->name ?? '']) }}</p>

        @if ($round && $round->deadline)
            <p class="field__hint">
                {{ __('site.renewal.until', [
                    'date' => \App\Support\Locale\Dates::longWhen($round->deadline),
                ]) }}
            </p>
        @endif

        <h3 class="order__event">{{ __('site.renewal.yourSeats') }}</h3>

        <ul class="order__lines">
            @foreach ($seats as $seat)
                <li>{{ $seat }}</li>
            @endforeach
        </ul>

        @if (count($nights))
            <h3 class="order__event">{{ __('site.renewal.nights') }}</h3>

            <ul class="order__lines">
                @foreach ($nights as $night)
                    <li>{{ $night['name'] }} · {{ \App\Support\Locale\Dates::longWhen($night['starts']) }}</li>
                @endforeach
            </ul>
        @endif

        {{-- Two answers and no third. A page that only knew how to say yes would leave the chairs
             blocked until the deadline for somebody who decided in five seconds that they cannot
             come this year — and those are exactly the seats the box office wants back early. --}}
        @if ($live && $offer->isOpen())
            <p class="field__hint">{{ __('site.renewal.priceLater') }}</p>

            <form method="POST" action="/renewals/{{ $offer->id }}/{{ $token }}/accept">
                @csrf
                <button class="button" type="submit">{{ __('site.renewal.take') }}</button>
            </form>

            <form method="POST" action="/renewals/{{ $offer->id }}/{{ $token }}/decline">
                @csrf
                <button class="button button--quiet" type="submit">{{ __('site.renewal.giveUp') }}</button>
            </form>
        @elseif ('accepted' === $offer->state)
            <p class="notice">{{ __('site.renewal.taken') }}</p>
        @elseif (! $live)
            <p class="notice">{{ __('site.renewal.over') }}</p>
        @endif
    </section>
@endsection
