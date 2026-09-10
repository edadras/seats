@extends('site.layout')

@section('content')
    <section class="shell section">
        @if ('confirmed' === $order->status)
            <div class="done">
                <span class="done__mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6"
                         stroke-linecap="round" stroke-linejoin="round"><path d="m4 12.5 5.5 5.5L20 7"/></svg>
                </span>
                <div>
                    <h1 class="section__title">{{ __('site.bookedHeading') }}</h1>
                </div>
            </div>

            <p class="muted">{{ __('site.orderLine', [
                'reference' => $order->external_order_id,
                'event' => $order->event?->nameFor(),
            ]) }}</p>

            @if (count($tokens))
                <p class="prose">{{ __('site.showCodeAtDoor') }}</p>
            @else
                <p class="prose">{{ __('site.codesByEmail') }}</p>
            @endif

            <p class="done__actions">
                <a class="button" href="/order/{{ $order->external_order_id }}/tickets"
                   download>{{ __('site.downloadTickets') }}</a>

                @if (! empty($invoice))
                    <a class="button button--quiet" href="/order/{{ $order->external_order_id }}/invoice"
                       download>{{ __('site.invoice.download') }}</a>
                @endif

                @if ($wallets['apple'])
                    <a class="button button--quiet" href="/order/{{ $order->external_order_id }}/wallet/apple">
                        {{ __('site.wallet.apple') }}
                    </a>
                @endif

                @if ($wallets['google'])
                    <a class="button button--quiet" href="/order/{{ $order->external_order_id }}/wallet/google">
                        {{ __('site.wallet.google') }}
                    </a>
                @endif

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
                                 width="200" height="200">
                        @endif

                        {{--
                            A named seat reads as section, row and seat. A standing place has none
                            of those: it has the area it is in and how many of them there are, and
                            `seat_label` on such a row carries an English phrase written at sale
                            time ("2 places") that would sit untranslated in the middle of this
                            sentence — and say the quantity a second time.
                        --}}
                        <p class="ticket__seat" dir="auto">
                            @if ($allocation->seat_id)
                                {{ trim($allocation->section_name.' '.$allocation->row_name.' '.$allocation->seat_label) }}
                            @else
                                {{ $allocation->section_name ?: __('site.standing') }}
                                <span class="muted">× {{ \App\Support\Locale\Money::number($allocation->quantity ?: 1) }}</span>
                            @endif
                        </p>

                        @if ($allocation->entry_starts_at)
                            <p class="ticket__type">{{ \App\Domain\Events\EntrySlots::window(
                                $allocation->entry_starts_at,
                                $allocation->entry_ends_at,
                                $order->event?->timezone,
                            ) }}</p>
                        @endif

                        @if ($allocation->ticket_type_name)
                            <p class="ticket__type" dir="auto">{{ $allocation->ticket_type_name }}</p>
                        @endif

                        <p class="ticket__code">
                            {{ $tokens[$allocation->id] ?? ($allocation->ticket?->token_prefix.'…') }}
                        </p>
                    </article>
                @endforeach
            </div>
        @else
            <h1 class="section__title">{{ __('site.bookedHeading') }}</h1>

            {{-- The status is translated too. "This booking is cancelled" half in one language
                 is the sentence a worried person reads twice and still cannot act on. --}}
            <p class="notice">{{ __('site.bookingStatus', [
                'status' => __('site.status.'.$order->status),
                'reference' => $order->external_order_id,
            ]) }}</p>
        @endif
    </section>
@endsection
