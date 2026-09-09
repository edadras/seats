@php $event = $eventFor($block); @endphp

@if ($event)
    {{--
        The hero.

        A ticket is bought on recognition — the poster, the name, the date, the room — and all four
        belong above the fold and in that order. Everything under it is detail for somebody who has
        already decided they are interested.
    --}}
    <header class="event-hero">
        @include('site.partials.cover', [
            'image' => $event['image'],
            'hue' => $event['hue'],
            'initials' => $event['initials'],
        ])

        <div class="shell event-hero__body">
            @if ($event['category'])
                <span class="chip chip--kind">{{ $event['category'] }}</span>
            @endif

            <h1 class="event-hero__name" dir="auto">{{ $event['name'] }}</h1>

            <ul class="event-hero__facts">
                @if ($event['venue'])
                    <li class="event-hero__fact">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="M12 21s7-5.6 7-11a7 7 0 1 0-14 0c0 5.4 7 11 7 11Z"/><circle cx="12" cy="10" r="2.6"/>
                        </svg>
                        {{ $event['venue'] }}
                    </li>
                @endif

                <li class="event-hero__fact">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <rect x="3" y="5" width="18" height="16" rx="2.5"/><path d="M3 10h18M8 3v4M16 3v4"/>
                    </svg>
                    {{ $event['long_when'] }}
                </li>

                @if ($event['from_price'] && $event['on_sale'])
                    <li class="event-hero__fact">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="M3 9.5a2 2 0 0 0 0 5V18a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-3.5a2 2 0 0 1 0-5V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v3.5Z"/>
                            <path d="M14 4v16" stroke-dasharray="2 3"/>
                        </svg>
                        <strong>{{ __('site.from', ['price' => $event['from_price']]) }}</strong>
                    </li>
                @endif
            </ul>

            {{-- A ticket bought in September is for a night in November. Downloaded rather than
                 linked to a service, so it works with whatever calendar the buyer actually uses. --}}
            <a class="event-hero__calendar" href="{{ $event['calendar_url'] }}" download>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <rect x="3" y="5" width="18" height="16" rx="2.5"/><path d="M3 10h18M8 3v4M16 3v4M12 13v5m0 0-2-2m2 2 2-2"/>
                </svg>
                {{ __('site.addToCalendar') }}
            </a>
        </div>
    </header>

    {{-- dir="auto" throughout: what an organiser wrote is in their language, not the reader's, and
         an English sentence dropped into a Persian page reads with its full stop at the front. --}}
    @if ($event['description'])
        <section class="shell section section--tight">
            <div class="prose event-about" dir="auto">{!! nl2br(e($event['description'])) !!}</div>
        </section>
    @endif

    @if ($event['on_sale'])
        <section class="shell section section--tight">
            <div class="booking">
                {{-- The same picker the WordPress plugin ships; see tools/sync-seat-picker.sh. --}}
                <div class="seatmap-widget" id="{{ $event['container_id'] }}" data-event="{{ $event['public_id'] }}">
                    <noscript>{{ __('site.pickerNeedsScript') }}</noscript>
                </div>
            </div>
        </section>

        @push('head')
            <link rel="stylesheet" href="{{ asset('site/css/widget.css') }}">
        @endpush

        @push('scripts')
            <script>
                window.seatmapBoot = window.seatmapBoot || [];
                window.seatmapBoot.push(@json($event['boot']));
            </script>
            <script src="{{ asset('site/js/widget.js') }}" defer></script>
        @endpush
    @else
        <section class="shell section section--tight">
            <p class="notice">{{ $event['closed_message'] }}</p>
        </section>
    @endif

    @if (! empty($event['waiting_list']))
        {{-- Offered where there is a queue worth joining: a night that has sold out, or one whose
             sale has closed. Seats come back all the time — a refund, a hold that expired — and
             until now they went back on sale silently, to whoever happened to be looking. --}}
        <section class="shell section section--tight">
            <form class="waitlist" method="POST" action="/events/{{ $event['public_id'] }}/waiting-list">
                @csrf

                <h2 class="waitlist__title">{{ __('site.waitlist.title') }}</h2>
                <p class="waitlist__lead">{{ __('site.waitlist.lead') }}</p>

                <div class="waitlist__fields">
                    <div class="field">
                        <label for="wl-name">{{ __('site.name') }}</label>
                        <input id="wl-name" name="name" required autocomplete="name" maxlength="120">
                    </div>

                    <div class="field">
                        <label for="wl-email">{{ __('site.email') }}</label>
                        <input id="wl-email" name="email" type="email" required
                               autocomplete="email" maxlength="190">
                    </div>

                    <div class="field field--narrow">
                        <label for="wl-quantity">{{ __('site.waitlist.howMany') }}</label>
                        <input id="wl-quantity" name="quantity" type="number" min="1" max="20" value="2">
                    </div>
                </div>

                <button class="button" type="submit">{{ __('site.waitlist.join') }}</button>
                <p class="waitlist__note">{{ __('site.waitlist.note') }}</p>
            </form>
        </section>
    @endif
@endif
