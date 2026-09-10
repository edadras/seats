{{--
    The ticket document.

    Written for mPDF, which is not a browser: no flexbox, no custom properties, no logical
    properties. Tables and inline styles, which is what it understands — and what a document that
    has to look the same in every PDF reader wants anyway.
--}}
@php($starts = $order->event?->starts_at?->setTimezone($order->event->timezone ?: $site->timezone))
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: vazirmatn, sans-serif; font-size: 10pt; color: #171a21; }
        .pass { border: 0.6pt solid #b9bfcb; padding: 6mm; margin-bottom: 5mm; }
        .venue { font-size: 8pt; color: #5f6878; letter-spacing: 0.06em; text-transform: uppercase; }
        .event { font-size: 15pt; font-weight: bold; margin: 1mm 0 0; }
        .when { font-size: 10pt; color: #3f4756; margin: 1mm 0 0; }
        .seat { font-size: 13pt; font-weight: bold; margin: 4mm 0 0; }
        .entry { font-size: 11pt; font-weight: bold; margin: 1.5mm 0 0; }
		.kind { font-size: 10pt; color: #555; margin-block-end: 2mm; }
        .meta { font-size: 8.5pt; color: #5f6878; margin: 1mm 0 0; }
        .code { font-family: monospace; font-size: 7.5pt; color: #5f6878; margin: 3mm 0 0; }
        .qr { width: 34mm; }
    </style>
</head>
<body>
@foreach ($order->allocations as $allocation)
    {{-- A table, because that is the one thing mPDF lays out reliably side by side. --}}
    <table class="pass" width="100%" cellpadding="0" cellspacing="0" autosize="1">
        <tr>
            <td width="70%" valign="top">
                <div class="venue">{{ $order->event?->venue?->name ?? $site->name }}</div>
                <div class="event">{{ $order->event?->nameFor() }}</div>
                <div class="when">{{ $starts ? \App\Support\Locale\Dates::longWhen($starts) : '' }}</div>

                <div class="seat">
                    @if ($allocation->seat_id)
                        {{ trim($allocation->section_name.' '.$allocation->row_name.' '.$allocation->seat_label) }}
                    @else
                        {{ $allocation->section_name ?: __('site.standing') }}
                        × {{ \App\Support\Locale\Money::number($allocation->quantity ?: 1) }}
                    @endif
                </div>

                @if ($allocation->entry_starts_at)
                    {{-- The arrival window, on a timed-entry event. Printed as prominently as the
                         seat, because it is the thing this holder is actually being told. --}}
                    <div class="entry">{{ \App\Domain\Events\EntrySlots::window(
                        $allocation->entry_starts_at,
                        $allocation->entry_ends_at,
                        $order->event?->timezone,
                    ) }}</div>
                @endif

                @if ($allocation->ticket_type_name)
                    {{-- Printed on the ticket because it is what the door will ask about: a
                         concession the holder cannot show proof for is a concession refused. --}}
                    <div class="kind">{{ $allocation->ticket_type_name }}</div>
                @endif

                <div class="meta">{{ __('site.orderReference', ['reference' => $order->external_order_id]) }}</div>
                <div class="code">{{ $tokens[$allocation->id] ?? ($allocation->ticket?->token_prefix.'…') }}</div>
            </td>
            <td width="30%" valign="top" align="{{ $rtl ? 'left' : 'right' }}">
                @if (isset($tokens[$allocation->id]))
                    <img class="qr" src="{{ $qr($tokens[$allocation->id]) }}">
                @endif
            </td>
        </tr>
    </table>
@endforeach
</body>
</html>
