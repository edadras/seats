{{--
    The tickets, on their own, laid out for paper.

    Its own document rather than the site's shell: nobody wants a navigation bar and a language
    menu on a ticket. It carries the venue's own tokens, so a printed ticket still looks like the
    venue's, and everything else it needs is in the one stylesheet below.
--}}
<!doctype html>
@php($locale = app()->getLocale())
@php($starts = $order->event?->starts_at?->setTimezone($order->event->timezone ?: $site->timezone))
<html lang="{{ $locale }}" dir="{{ \App\Support\Locale\Locales::direction($locale) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('site.yourTickets') }} · {{ $site->name }}</title>
    <style>{!! $brand['token_css'] !!}</style>
    <style>
        :root { color-scheme: light; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 1.5rem 1rem 3rem;
            background: var(--surface-sunken, #f6f7f9);
            color: var(--text, #171a21);
            font-family: var(--font-body, system-ui, sans-serif);
            line-height: 1.5;
        }

        .sheet { inline-size: min(100% - 1rem, 46rem); margin-inline: auto; }

        .sheet__actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.75rem 1rem;
            margin-block-end: 1.5rem;
        }

        .sheet__print {
            min-block-size: 2.75rem;
            padding-inline: 1.25rem;
            border: 1px solid var(--accent, #4a4fdc);
            border-radius: var(--button-radius, 10px);
            background: var(--accent, #4a4fdc);
            color: var(--on-accent, #fff);
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        .sheet__hint { margin: 0; font-size: 0.875rem; color: var(--text-muted, #5f6878); }

        /*
         * One ticket, one card, and — on paper — one per page.
         *
         * `break-inside: avoid` is the whole reason this page exists separately: a ticket split
         * across a page boundary is a QR code cut in half, which is a person at a door with a
         * phone and a queue behind them.
         */
        .pass {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            padding: 1.25rem;
            margin-block-end: 1rem;
            border: 1px solid var(--border, #e3e6ec);
            border-radius: var(--radius, 10px);
            background: var(--surface, #fff);
            break-inside: avoid;
        }

        .pass__qr { inline-size: 8rem; block-size: 8rem; flex: none; }
        .pass__body { min-inline-size: 0; }
        .pass__event { margin: 0 0 0.15rem; font-family: var(--font-heading, inherit); font-size: 1.2rem; font-weight: 700; }
        .pass__when { margin: 0 0 0.6rem; color: var(--text-muted, #5f6878); font-size: 0.925rem; }
        .pass__seat { margin: 0; font-size: 1.05rem; font-weight: 700; }
        .pass__meta { margin: 0.15rem 0 0; color: var(--text-muted, #5f6878); font-size: 0.85rem; }

        .pass__code {
            margin: 0.6rem 0 0;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 0.7rem;
            letter-spacing: 0.02em;
            color: var(--text-muted, #5f6878);
            word-break: break-all;
        }

        @media print {
            @page { size: A4; margin: 14mm; }
            body { padding: 0; background: #fff; }
            .no-print { display: none; }
            .pass { border-color: #999; box-shadow: none; page-break-inside: avoid; }
        }
    </style>
</head>
<body>
<div class="sheet">
    <div class="sheet__actions no-print">
        <button class="sheet__print" type="button" onclick="window.print()">{{ __('site.printTickets') }}</button>
        <p class="sheet__hint">{{ __('site.savePdfHint') }}</p>
    </div>

    @foreach ($order->allocations as $allocation)
        <article class="pass">
            @if (isset($tokens[$allocation->id]))
                <img class="pass__qr" src="{{ $qr($tokens[$allocation->id]) }}" alt="" width="320" height="320">
            @endif

            <div class="pass__body">
                <h1 class="pass__event" dir="auto">{{ $order->event?->name }}</h1>
                <p class="pass__when">
                    {{ $starts ? \App\Support\Locale\Dates::longWhen($starts) : '' }}@if ($order->event?->venue) · <span dir="auto">{{ $order->event->venue->name }}</span>@endif
                </p>

                <p class="pass__seat" dir="auto">
                    @if ($allocation->seat_id)
                        {{ trim($allocation->section_name.' '.$allocation->row_name.' '.$allocation->seat_label) }}
                    @else
                        {{ $allocation->section_name ?: __('site.standing') }}
                        × {{ \App\Support\Locale\Money::number($allocation->quantity ?: 1) }}
                    @endif
                </p>

                <p class="pass__meta">{{ __('site.orderReference', ['reference' => $order->external_order_id]) }}</p>

                <p class="pass__code">
                    {{ $tokens[$allocation->id] ?? ($allocation->ticket?->token_prefix.'…') }}
                </p>
            </div>
        </article>
    @endforeach
</div>

{{-- Opened from a button that says it will do this, so the dialogue is expected rather than a
     surprise. The button above is there for whoever dismisses it, or whose browser blocks it. --}}
<script>window.addEventListener('load', function () { window.print(); });</script>
</body>
</html>
