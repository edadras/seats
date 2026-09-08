@extends('site.layout')

@section('content')
    <section class="shell section">
        <h1>{{ __('site.bookedHeading') }}</h1>
        <p class="muted">{{ __('site.orderLine', [
            'reference' => $order->external_order_id,
            'event' => $order->event?->name,
        ]) }}</p>

        @if ('confirmed' === $order->status)
            @if (count($tokens))
                <p class="prose">{{ __('site.showCodeAtDoor') }}</p>
            @else
                <p class="prose">{{ __('site.codesByEmail') }}</p>
            @endif

            <div class="tickets">
                @foreach ($order->allocations as $allocation)
                    <article class="ticket">
                        @if (isset($tokens[$allocation->id]))
                            <img class="ticket__qr" src="{{ $qr($tokens[$allocation->id]) }}" alt=""
                                 width="200" height="200">
                        @endif

                        <p class="ticket__seat">
                            {{ trim($allocation->section_name.' '.$allocation->row_name.' '.$allocation->seat_label) ?: __('site.standing') }}
                            {{-- Quantity belongs to a standing place, where it is the whole point.
                                 On a named seat it is always one, and printing it says nothing. --}}
                            @if (! $allocation->seat_id && $allocation->quantity)
                                <span class="muted">× {{ $allocation->quantity }}</span>
                            @endif
                        </p>

                        <p class="ticket__code">
                            {{ $tokens[$allocation->id] ?? ($allocation->ticket?->token_prefix.'…') }}
                        </p>
                    </article>
                @endforeach
            </div>
        @else
            {{-- The status is translated too. "This booking is cancelled" half in one language
                 is the sentence a worried person reads twice and still cannot act on. --}}
            <p class="notice">{{ __('site.bookingStatus', [
                'status' => __('site.status.'.$order->status),
                'reference' => $order->external_order_id,
            ]) }}</p>
        @endif
    </section>
@endsection
