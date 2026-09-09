@php
    $events = $eventsFor($block);

    // Spotlight only means something when there is a season to lead: with one or two dates the
    // large card leaves a hole beside it rather than making anything look important.
    $lead = 'spotlight' === $block['layout'] && count($events) >= 3;
@endphp

<section class="section">
    <div class="shell">
        @if ($block['title'])
            <div class="section__head">
                <h2 class="section__title">{{ $block['title'] }}</h2>
            </div>
        @endif

        @if (count($events))
            <div class="events events--{{ $block['layout'] }}">
                @foreach ($events as $event)
                    {{-- Spotlight gives the first date the room: a season usually has one thing it
                         is actually selling, and a grid of identical cards says every night is the
                         same night. --}}
                    <a class="event-card @if ($event['sold_out']) event-card--gone @endif
                              @if ($lead && $loop->first) event-card--lead @endif"
                       href="{{ $event['url'] }}">
                        <div class="event-card__art">
                            @include('site.partials.cover', [
                                'image' => $event['image'],
                                'hue' => $event['hue'],
                                'initials' => $event['initials'],
                            ])

                            <time class="event-card__when" datetime="{{ $event['starts_at_iso'] }}">
                                <span class="event-card__day">{{ $event['day'] }}</span>
                                <span class="event-card__month">{{ $event['month'] }}</span>
                            </time>

                            @if ($event['category'])
                                <span class="chip chip--kind event-card__kind">{{ $event['category'] }}</span>
                            @endif

                            @if ($event['sold_out'])
                                <span class="chip chip--gone event-card__gone">{{ __('site.soldOut') }}</span>
                            @endif
                        </div>

                        <div class="event-card__body">
                            <h3 class="event-card__name" dir="auto">{{ $event['name'] }}</h3>
                            <p class="event-card__meta">{{ $event['time'] }}@if ($event['venue']) · {{ $event['venue'] }}@endif</p>
                        </div>

                        <div class="event-card__foot">
                            <p class="event-card__price">
                                {{ $event['from_price']
                                    ? __('site.from', ['price' => $event['from_price']])
                                    : '' }}
                            </p>
                            <span class="event-card__cta">{{ $event['sold_out'] ? __('site.soldOut') : __('site.book') }}</span>
                        </div>
                    </a>
                @endforeach
            </div>
        @else
            <p class="muted">{{ __('site.nothingOnSale') }}</p>
        @endif
    </div>
</section>
