@php $tags = \App\Domain\Sites\Measurement::forSite($site); @endphp

@if (count($tags))
    {{--
        Measurement, and the question that has to come before it.

        Nothing here loads until a visitor has said yes. That is not only the law in most of the
        places this platform sells tickets — it is the difference between a venue's ticket page and
        a page that hands every visitor to three companies before they have decided to come.

        The bar is rendered hidden and shown by the script. A reader with no JavaScript therefore
        sees no bar, which is correct: with no JavaScript nothing was going to be measured anyway,
        and a consent question about something that cannot happen is furniture.
    --}}
    <div class="cookiebar" data-consent hidden role="region"
         aria-label="{{ __('site.cookies.title') }}">
        <div class="shell cookiebar__inner">
            <p class="cookiebar__text">{{ __('site.cookies.body', ['site' => $site->name]) }}</p>
            <div class="cookiebar__acts">
                <button class="button button--quiet" type="button" data-consent-no>
                    {{ __('site.cookies.no') }}
                </button>
                <button class="button button--primary" type="button" data-consent-yes>
                    {{ __('site.cookies.yes') }}
                </button>
            </div>
        </div>
    </div>

    {{--
        Emitted here rather than pushed onto the scripts stack, and the order is the reason: a page
        that records an event does so from a stacked script, and `seatmapTrack` has to exist by
        then. A pushed script would land after them and every event would be dropped by the very
        guard that is supposed to queue it.
    --}}
    <script>
        /*
         * Ask, remember, and only then measure.
         *
         * `seatmapTrack` is available to every page whether or not anybody has consented: it
         * queues, and the queue is either sent when somebody says yes or thrown away when they
         * say no. That way a page that wants to record a purchase does not have to know
         * anything about consent, and there is exactly one place that does.
         */
        ( function () {
            var KEY = 'seatmap-measure';
            var tags = @json($tags);
            var bar = document.querySelector( '[data-consent]' );
            var queue = [];
            var on = false;

            function remembered() {
                try {
                    return window.localStorage.getItem( KEY );
                } catch ( error ) {
                    // A private window, or storage turned off. Treated as "not asked", which
                    // means the bar appears each visit — annoying, and the only honest answer
                    // when there is nowhere to write the choice down.
                    return null;
                }
            }

            function remember( answer ) {
                try {
                    window.localStorage.setItem( KEY, answer );
                } catch ( error ) {}
            }

            function add( src, ready ) {
                var element = document.createElement( 'script' );

                element.async = true;
                element.src = src;
                element.addEventListener( 'load', ready || function () {} );
                document.head.appendChild( element );
            }

            function start() {
                if ( on ) {
                    return;
                }

                on = true;

                tags.forEach( function ( tag ) {
                    if ( 'ga4' === tag.provider ) {
                        window.dataLayer = window.dataLayer || [];
                        window.gtag = function () { window.dataLayer.push( arguments ); };
                        window.gtag( 'js', new Date() );
                        window.gtag( 'config', tag.id );
                        add( tag.src );
                    }

                    if ( 'meta' === tag.provider ) {
                        /* eslint-disable */
                        !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
                        n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
                        n.push=n;n.loaded=!0;n.version='2.0';n.queue=[]}(window);
                        /* eslint-enable */
                        add( tag.src );
                        window.fbq( 'init', tag.id );
                        window.fbq( 'track', 'PageView' );
                    }

                    if ( 'plausible' === tag.provider ) {
                        var element = document.createElement( 'script' );

                        element.defer = true;
                        element.src = tag.src;
                        element.setAttribute( 'data-domain', tag.id );
                        document.head.appendChild( element );

                        window.plausible = window.plausible || function () {
                            ( window.plausible.q = window.plausible.q || [] ).push( arguments );
                        };
                    }
                } );

                queue.splice( 0 ).forEach( function ( event ) { send( event[ 0 ], event[ 1 ] ); } );
            }

            /** One event, in each provider's own vocabulary. */
            function send( name, params ) {
                params = params || {};

                if ( window.gtag ) {
                    window.gtag( 'event', name, params );
                }

                if ( window.fbq ) {
                    // Meta names three of these differently, and a purchase recorded under the
                    // wrong name is a purchase their reporting will not count.
                    var meta = { view_item: 'ViewContent', begin_checkout: 'InitiateCheckout', purchase: 'Purchase' };

                    window.fbq( 'track', meta[ name ] || name, {
                        value: params.value,
                        currency: params.currency,
                        content_name: params.item_name,
                    } );
                }

                if ( window.plausible ) {
                    window.plausible( name, { props: {
                        event: params.item_name || '',
                        value: params.value || 0,
                    } } );
                }
            }

            window.seatmapTrack = function ( name, params ) {
                if ( on ) {
                    send( name, params );

                    return;
                }

                queue.push( [ name, params ] );
            };

            function answer( value ) {
                remember( value );

                if ( bar ) {
                    bar.hidden = true;
                }

                if ( 'yes' === value ) {
                    start();
                } else {
                    // Thrown away rather than kept: an event queued before a refusal is an
                    // event the visitor has now declined to send.
                    queue.length = 0;
                }
            }

            if ( bar ) {
                bar.querySelector( '[data-consent-yes]' )
                    .addEventListener( 'click', function () { answer( 'yes' ); } );
                bar.querySelector( '[data-consent-no]' )
                    .addEventListener( 'click', function () { answer( 'no' ); } );
            }

            // A browser that has already said no is not asked again in a dialogue. Global
            // Privacy Control is a legal signal in several places; Do Not Track is not, but a
            // visitor who set it meant the same thing and being asked anyway is insulting.
            if ( true === window.navigator.globalPrivacyControl || '1' === window.navigator.doNotTrack ) {
                answer( 'no' );
            } else {
                var said = remembered();

                if ( 'yes' === said ) {
                    start();
                } else if ( 'no' !== said && bar ) {
                    bar.hidden = false;
                }
            }

            // A way back to the question, for somebody who changed their mind.
            document.querySelectorAll( '[data-consent-reopen]' ).forEach( function ( link ) {
                link.addEventListener( 'click', function ( event ) {
                    event.preventDefault();

                    try {
                        window.localStorage.removeItem( KEY );
                    } catch ( error ) {}

                    if ( bar ) {
                        bar.hidden = false;
                    }
                } );
            } );
        }() );
    </script>
@endif
