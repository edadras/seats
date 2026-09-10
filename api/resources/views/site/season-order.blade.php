@extends('site.layout')

@section('content')
    <section class="shell section">
        @if ($booking->isConfirmed())
            <div class="done">
                <span class="done__mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6"
                         stroke-linecap="round" stroke-linejoin="round"><path d="m4 12.5 5.5 5.5L20 7"/></svg>
                </span>
                <div>
                    <h1 class="section__title">{{ __('site.season.booked') }}</h1>
                </div>
            </div>

            <p class="muted">{{ __('site.season.orderLine', [
                'reference' => $booking->reference,
                'pass' => $booking->pass?->name,
                'nights' => \App\Support\Locale\Money::number($booking->nights),
            ]) }}</p>

            @if (count($tokens))
                <p class="prose">{{ __('site.showCodeAtDoor') }}</p>
            @else
                <p class="prose">{{ __('site.codesByEmail') }}</p>
            @endif

            {{-- One block per night, because one night is what a person turns up to. A subscriber
                 holds twelve ordinary tickets, not one thing that has to be explained at a door. --}}
            @foreach ($booking->orders->sortBy(fn ($order) => $order->event?->starts_at) as $order)
                <div class="season__booked">
                    <h2 class="season__subhead" dir="auto">{{ $order->event?->nameFor() }}</h2>
                    <p class="muted">{{ \App\Support\Locale\Dates::longWhen(
                        $order->event?->starts_at?->setTimezone($order->event?->timezone ?: $site->timezone),
                        app()->getLocale(),
                    ) }}</p>

                    <p class="done__actions">
                        <a class="button button--quiet" href="/order/{{ $order->external_order_id }}/tickets"
                           download>{{ __('site.downloadTickets') }}</a>

                        @if ($order->event)
                            <a class="button button--secondary"
                               href="/events/{{ $order->event->public_id }}/calendar.ics"
                               download>{{ __('site.addToCalendar') }}</a>
                        @endif
                    </p>

                    <div class="tickets">
                        @foreach ($order->allocations as $allocation)
                            <article class="ticket">
                                @if (isset($tokens[$allocation->id]))
                                    <img class="ticket__qr" src="{{ $qr($tokens[$allocation->id]) }}" alt=""
                                         width="180" height="180">
                                @endif

                                <p class="ticket__seat" dir="auto">
                                    @if ($allocation->seat_id)
                                        {{ trim($allocation->section_name.' '.$allocation->row_name.' '.$allocation->seat_label) }}
                                    @else
                                        {{ $allocation->section_name ?: __('site.standing') }}
                                        <span class="muted">× {{ \App\Support\Locale\Money::number($allocation->quantity ?: 1) }}</span>
                                    @endif
                                </p>

                                @if ($allocation->ticket_type_name)
                                    <p class="ticket__type" dir="auto">{{ $allocation->ticket_type_name }}</p>
                                @endif

                                <p class="ticket__code">
                                    {{ $tokens[$allocation->id] ?? ($allocation->ticket?->token_prefix.'…') }}
                                </p>
                            </article>
                        @endforeach
                    </div>
                </div>
            @endforeach
        @else
            <h1 class="section__title">{{ __('site.season.yours') }}</h1>

            <p class="notice">{{ __('site.bookingStatus', [
                'status' => __('site.status.'.$booking->status),
                'reference' => $booking->reference,
            ]) }}</p>
        @endif
    </section>
@endsection
