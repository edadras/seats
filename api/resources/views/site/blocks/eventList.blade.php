@php
    $events = $eventsFor($block);
    // Missing means yes, which is what the sanitiser defaults it to: blocks stored before this
    // was an option are pages whose programme should still be searchable.
    $filters = ($block['search'] ?? true) ? $listFilters() : null;

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

        {{-- A programme of thirty dates is a list people scroll past. Plain GET, server-rendered:
             the results are a shareable address, and the page works with JavaScript switched off. --}}
        @if ($filters && (count($events) || $filters['active']))
            <form class="finder" method="get" role="search">
                <label class="finder__field">
                    <span class="finder__label">{{ __('site.searchEvents') }}</span>
                    <input type="search" name="q" value="{{ $filters['q'] }}"
                           placeholder="{{ __('site.searchPlaceholder') }}">
                </label>

                @if (count($filters['categories']) > 1)
                    <label class="finder__field finder__field--narrow">
                        <span class="finder__label">{{ __('site.kind') }}</span>
                        <select name="category">
                            <option value="">{{ __('site.anyCategory') }}</option>
                            @foreach ($filters['categories'] as $category)
                                <option value="{{ $category }}" @selected($filters['category'] === $category)>
                                    {{ $category }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                @endif

                <button class="button button--secondary" type="submit">{{ __('site.search') }}</button>

                @if ($filters['active'])
                    <a class="finder__clear" href="{{ url()->current() }}">{{ __('site.showEverything') }}</a>
                @endif
            </form>
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
            {{-- Two different empty pages: nothing is on, or nothing matches what was asked for.
                 Telling somebody "nothing on sale" when they mistyped a name is a dead end. --}}
            <p class="muted">
                {{ $filters && $filters['active'] ? __('site.nothingMatched') : __('site.nothingOnSale') }}
            </p>
        @endif
    </div>
</section>
