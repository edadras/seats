@php $event = $offerFor($block); @endphp

@if ($event)
    {{--
        Buy this one, from anywhere on the site.

        Everything here is read from the same answer the event page itself renders from, so a
        button that says "on sale" on the home page and a page that says "sold out" cannot both
        exist. What it does not do is sell: the seats are chosen on the event's own page, where
        the picker, the limits and the presale door already live.
    --}}
    <section class="shell section section--tight">
        <div class="buy">
            <div class="buy__what">
                @if ($block['title'])
                    <h2 class="buy__title" dir="auto">{{ $block['title'] }}</h2>
                @else
                    <h2 class="buy__title" dir="auto">{{ $event['name'] }}</h2>
                @endif

                <p class="buy__when">
                    {{ $event['long_when'] }}
                    @if ($event['venue'])
                        · {{ $event['venue'] }}
                    @endif
                </p>

                @if ($block['note'])
                    <p class="buy__note muted" dir="auto">{{ $block['note'] }}</p>
                @endif
            </div>

            <div class="buy__act">
                @if ($event['on_sale'])
                    @if ($event['from_price'])
                        <span class="buy__price">{{ __('site.from', ['price' => $event['from_price']]) }}</span>
                    @endif

                    <a class="button button--primary" href="/events/{{ $event['public_id'] }}">
                        {{ $block['label'] ?: __('site.book') }}
                    </a>
                @else
                    {{-- The same sentence the event page would give them, rather than a button
                         that leads somewhere with nothing to buy. --}}
                    <span class="buy__closed">{{ $event['closed_message'] }}</span>

                    @if (! empty($event['opens_at']))
                        <span class="muted">{{ __('site.access.opensOn', ['when' => $event['opens_at']]) }}</span>
                    @endif

                    <a class="button button--quiet" href="/events/{{ $event['public_id'] }}">
                        {{ __('site.seeEvent') }}
                    </a>
                @endif
            </div>
        </div>
    </section>
@endif
