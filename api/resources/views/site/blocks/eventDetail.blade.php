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
                    @if (! empty($event['moved_from']))
                        {{-- People arrive here from a diary entry they made months ago. Saying
                             nothing about a change of date is how they turn up on the old one. --}}
                        <span class="event-hero__moved">{{ __('site.movedFrom', [
                            'was' => $event['moved_from'],
                        ]) }}</span>
                    @endif
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

    @if (! empty($event['queue']))
        @php
            /*
             * Every sentence the room can show, handed to the script as one object.
             *
             * Built here rather than inline in the `@json` call because a Blade directive parses
             * its own argument, and a multi-line array with translation calls in it is not
             * something it can be asked to read.
             */
            $roomWords = [
                'admitted' => __('site.room.letIn'),
                'lobby' => __('site.room.beforeDoors'),
                'queued' => __('site.room.place'),
                'ahead' => __('site.room.ahead'),
                'alone' => __('site.room.nextUp'),
                'gone' => __('site.room.gone'),
            ];
        @endphp

        {{-- The door. Rendered instead of the picker, not in front of it: a seat map drawing behind
             a queue is a seat map being polled by everybody the queue exists to hold back. --}}
        <section class="shell section section--tight">
            <div class="room" id="room"
                 data-poll="{{ $event['queue']['poll'] }}"
                 data-opens="{{ $event['queue']['opens_at'] }}">
                <h2 class="room__title" id="room-title">{{ __('site.room.holdOn') }}</h2>
                <p class="room__line" id="room-line">{{ __('site.room.joining') }}</p>

                <div class="room__meter" aria-hidden="true"><span id="room-bar"></span></div>

                <p class="room__hint">{{ __('site.room.hint') }}</p>

                <form class="room__out" method="POST" action="{{ $event['queue']['leave'] }}">
                    @csrf
                    <button class="button button--quiet" type="submit">{{ __('site.room.leave') }}</button>
                </form>
            </div>
        </section>

        @push('scripts')
            <script>
                /*
                 * Ask where we stand, and come in when we are let in.
                 *
                 * Nothing is worked out here: the server says the place, the number ahead and
                 * whether the doors are open, and this prints it. A queue position computed in the
                 * browser would be a second answer to the one question this page exists to answer.
                 */
                ( function () {
                    var room = document.getElementById( 'room' );
                    var title = document.getElementById( 'room-title' );
                    var line = document.getElementById( 'room-line' );
                    var bar = document.getElementById( 'room-bar' );

                    if ( ! room ) {
                        return;
                    }

                    var words = @json($roomWords);

                    function ask() {
                        fetch( room.dataset.poll, {
                            credentials: 'same-origin',
                            headers: { Accept: 'application/json' },
                        } )
                            .then( function ( response ) {
                                return response.ok ? response.json() : null;
                            } )
                            .then( function ( state ) {
                                if ( ! state ) {
                                    return;
                                }

                                if ( 'admitted' === state.state ) {
                                    // In. Reload rather than draw the picker here: the page the
                                    // server renders for somebody inside is a different page.
                                    window.location.reload();

                                    return;
                                }

                                if ( 'expired' === state.state || 'left' === state.state ) {
                                    title.textContent = words.gone;
                                    line.textContent = '';

                                    return;
                                }

                                if ( 'lobby' === state.state ) {
                                    line.textContent = words.lobby;
                                    bar.style.inlineSize = '8%';
                                    window.setTimeout( ask, 5000 );

                                    return;
                                }

                                line.textContent = state.ahead
                                    ? words.ahead.replace( ':count', state.ahead )
                                    : words.alone;
                                title.textContent = words.queued.replace( ':place', state.place );

                                // How far along, roughly. Decoration, and honest decoration: it is
                                // the share of the queue that is now behind this person.
                                var total = state.ahead + ( state.inside || 0 ) + 1;
                                bar.style.inlineSize =
                                    Math.max( 8, Math.round( 100 * ( state.inside || 0 ) / total ) ) + '%';

                                window.setTimeout( ask, 5000 );
                            } )
                            .catch( function () {
                                window.setTimeout( ask, 10000 );
                            } );
                    }

                    ask();
                }() );
            </script>
        @endpush
    @elseif ($event['on_sale'])
        <section class="shell section section--tight">
            <div class="booking">
                @if (! empty($event['per_buyer']))
                    {{-- Said before anybody chooses. A refusal at the checkout is correct and it is
                         also a wasted evening: a buyer who reads "four per person" while they are
                         looking at the seat map chooses four. --}}
                    <p class="booking__limit">
                        {{ trans_choice('site.limitPerBuyer', $event['per_buyer'], ['count' => $event['per_buyer']]) }}
                    </p>
                @endif

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
            {{-- The room, before the picker that may draw it: a chart with 3D switched on renders
                 the hall itself, and the engine has to be there when the widget boots. --}}
            <script src="{{ asset('site/js/hall3d.js') }}" defer></script>
            <script src="{{ asset('site/js/widget.js') }}" defer></script>
        @endpush
    @elseif (! empty($event['needs_code']))
        {{-- On sale, but not to this visitor. The box is offered instead of the seats, and it is
             what turns the seats on: unlocking reloads the page with the picker in place of this. --}}
        <section class="shell section section--tight">
            <div class="unlock">
                <p class="notice">{{ $event['closed_message'] }}</p>

                @if (! empty($event['opens_at']))
                    <p class="notice notice--quiet">{{ __('site.access.opensOn', ['when' => $event['opens_at']]) }}</p>
                @endif

                <form class="unlock__form" id="unlock-form" data-event="{{ $event['public_id'] }}">
                    <label class="unlock__label" for="access-code">{{ __('site.access.haveACode') }}</label>
                    <div class="unlock__row">
                        <input class="unlock__input" id="access-code" name="code" autocomplete="off"
                               maxlength="40" spellcheck="false" required>
                        <button class="button" type="submit">{{ __('site.access.unlock') }}</button>
                    </div>
                    <p class="unlock__said" id="unlock-said" role="status" aria-live="polite"></p>
                </form>
            </div>
        </section>

        @push('scripts')
            <script>
                ( function () {
                    var form = document.getElementById( 'unlock-form' );
                    var said = document.getElementById( 'unlock-said' );

                    if ( ! form ) {
                        return;
                    }

                    form.addEventListener( 'submit', function ( event ) {
                        event.preventDefault();
                        said.textContent = '';

                        fetch( '/_store/unlock', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'Content-Type': 'application/json',
                                Accept: 'application/json',
                                // The same token the picker sends on a hold. Without it the
                                // request is refused by the framework, not by the code check.
                                'X-CSRF-TOKEN': @json(csrf_token()),
                            },
                            body: JSON.stringify( {
                                event_public_id: form.dataset.event,
                                code: form.querySelector( '#access-code' ).value,
                            } ),
                        } )
                            .then( function ( response ) { return response.json(); } )
                            .then( function ( body ) {
                                if ( body.ok ) {
                                    // The seats are behind a re-render, not behind this script:
                                    // whether somebody may buy is the server's answer, always.
                                    window.location.reload();

                                    return;
                                }

                                said.textContent = body.message || '';
                            } )
                            .catch( function () {
                                said.textContent = @json(__('site.access.tryAgain'));
                            } );
                    } );
                }() );
            </script>
        @endpush
    @else
        <section class="shell section section--tight">
            <p class="notice">{{ $event['closed_message'] }}</p>

            @if (! empty($event['closed_reason']))
                <p class="notice notice--quiet" dir="auto">{{ $event['closed_reason'] }}</p>
            @endif
        </section>
    @endif

    @if (! empty($event['season_passes']))
        {{-- The whole run, offered to the person already looking at one night of it. This is the
             only moment a subscription is put in front of them, so it sits above the other dates
             rather than under them. --}}
        <section class="shell section section--tight">
            <h2 class="dates__title">{{ __('site.season.title') }}</h2>
            <ul class="season__offers">
                @foreach ($event['season_passes'] as $pass)
                    <li class="season__offer">
                        <span class="season__offer-what">
                            <strong dir="auto">{{ $pass['name'] }}</strong>
                            @if ($pass['description'])
                                <span class="muted" dir="auto">{{ $pass['description'] }}</span>
                            @endif
                            <span class="season__offer-saving">{{ $pass['saving'] }}</span>
                        </span>
                        <a class="button button--quiet" href="/season/{{ $pass['id'] }}">
                            {{ __('site.season.see') }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if (! empty($event['other_dates']))
        {{-- The rest of the run. Somebody who cannot come on Tuesday should not have to go back to
             the programme and hunt for Wednesday. --}}
        <section class="shell section section--tight">
            <h2 class="dates__title">{{ __('site.otherDates') }}</h2>
            <ul class="dates">
                @foreach ($event['other_dates'] as $date)
                    <li class="dates__item @if ($date['sold_out']) dates__item--gone @endif">
                        <a class="dates__link" href="{{ $date['url'] }}">
                            <span class="dates__when">{{ $date['when'] }}</span>
                            {{-- Where, on a run that moves. A tour date with no town on it is a
                                 date somebody has to click to find out about. --}}
                            @if (! empty($date['city']) || ! empty($date['venue']))
                                <span class="dates__where">
                                    {{ $date['venue'] }}@if (! empty($date['city']) && $date['city'] !== $date['venue']) · {{ $date['city'] }}@endif
                                </span>
                            @endif
                            <span class="dates__state">
                                {{ $date['sold_out'] ? __('site.soldOut') : __('site.book') }}
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
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
