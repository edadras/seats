<section class="shell section">
    @if ($block['title'])
        <h2 class="block-heading">{{ $block['title'] }}</h2>
    @endif

    @php $events = $eventsFor($block); @endphp

    @if (count($events))
        <div class="events events--{{ $block['layout'] }}">
            @foreach ($events as $event)
                <a class="event-card" href="{{ $event['url'] }}">
                    <time class="event-card__when" datetime="{{ $event['starts_at_iso'] }}">
                        <span class="event-card__day">{{ $event['day'] }}</span>
                        <span class="event-card__month">{{ $event['month'] }}</span>
                    </time>
                    <div class="event-card__body">
                        <h3 class="event-card__name">{{ $event['name'] }}</h3>
                        <p class="event-card__meta">{{ $event['time'] }}@if ($event['venue']) · {{ $event['venue'] }}@endif</p>
                        @if ($event['from_price'])
                            <p class="event-card__price">From {{ $event['from_price'] }}</p>
                        @endif
                    </div>
                    <span class="event-card__cta">{{ $event['sold_out'] ? 'Sold out' : 'Book' }}</span>
                </a>
            @endforeach
        </div>
    @else
        <p class="muted">Nothing on sale just now. Check back soon.</p>
    @endif
</section>
