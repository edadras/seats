@extends('site.layout')

@section('content')
    <section class="shell section">
        <h1>You’re booked</h1>
        <p class="muted">Order {{ $order->external_order_id }} · {{ $order->event?->name }}</p>

        @if ('confirmed' === $order->status)
            @if (count($tokens))
                <p class="prose">Show a code at the door — one for each seat. We’ve emailed them to
                    you as well.</p>
            @else
                <p class="prose">Your tickets are on their way by email. The codes are shown only
                    once here, so check your inbox.</p>
            @endif

            <div class="tickets">
                @foreach ($order->allocations as $allocation)
                    <article class="ticket">
                        @if (isset($tokens[$allocation->id]))
                            <img class="ticket__qr" src="{{ $qr($tokens[$allocation->id]) }}" alt=""
                                 width="200" height="200">
                        @endif

                        <p class="ticket__seat">
                            {{ trim($allocation->section_name.' '.$allocation->row_name.' '.$allocation->seat_label) ?: 'Standing' }}
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
            <p class="notice">This booking is {{ $order->status }}. If that looks wrong, contact the
                box office and quote {{ $order->external_order_id }}.</p>
        @endif
    </section>
@endsection
