@if (count($block['items']))
    {{--
        A carousel that is a scroller first.

        The track is a row of slides with scroll-snap on it, so with no JavaScript at all — and on
        every phone — it already works: you swipe. The buttons and the dots below are an
        enhancement over that, not the thing itself, which is why they are added by the script
        rather than rendered here for somebody who cannot use them.
    --}}
    <section class="section slides" data-slides @if ($block['autoplay']) data-autoplay="6000" @endif
             aria-roledescription="carousel"
             @if ($block['title']) aria-label="{{ $block['title'] }}" @endif>
        <div class="shell">
            @if ($block['title'])
                <h2 class="block-heading">{{ $block['title'] }}</h2>
            @endif
        </div>

        <div class="slides__track slides__track--{{ $block['height'] }}" data-slides-track tabindex="0">
            @foreach ($block['items'] as $index => $item)
                <figure class="slides__slide" data-slide
                        aria-roledescription="slide"
                        aria-label="{{ $index + 1 }} / {{ count($block['items']) }}">
                    @if ($item['href'])
                        <a class="slides__link" href="{{ $item['href'] }}">
                            <img src="{{ $item['url'] }}" alt="{{ $item['alt'] }}"
                                 loading="{{ 0 === $index ? 'eager' : 'lazy' }}">
                        </a>
                    @else
                        <img src="{{ $item['url'] }}" alt="{{ $item['alt'] }}"
                             loading="{{ 0 === $index ? 'eager' : 'lazy' }}">
                    @endif

                    @if ($item['caption'])
                        <figcaption class="slides__caption" dir="auto">{{ $item['caption'] }}</figcaption>
                    @endif
                </figure>
            @endforeach
        </div>
    </section>

    @once
        @push('scripts')
            <script>
                /*
                 * Arrows, dots, and — only if asked for — a slide that moves on its own.
                 *
                 * Everything here is added to a thing that already works, so a browser that runs
                 * none of it leaves a swipeable row of pictures rather than a broken widget. The
                 * autoplay stops the moment somebody touches, hovers or tabs into the track, and
                 * never starts at all for a reader who has asked their system for less motion.
                 */
                ( function () {
                    var quiet = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

                    document.querySelectorAll( '[data-slides]' ).forEach( function ( show ) {
                        var track = show.querySelector( '[data-slides-track]' );
                        var slides = Array.prototype.slice.call( show.querySelectorAll( '[data-slide]' ) );

                        if ( ! track || slides.length < 2 ) {
                            return;
                        }

                        var at = 0;
                        var timer = null;

                        var nav = document.createElement( 'div' );
                        nav.className = 'slides__nav shell';

                        /** Where the middle of a slide sits, relative to the middle of the track. */
                        function offBy( slide ) {
                            var box = slide.getBoundingClientRect();
                            var frame = track.getBoundingClientRect();

                            return ( box.left + box.width / 2 ) - ( frame.left + frame.width / 2 );
                        }

                        function go( index ) {
                            at = ( index + slides.length ) % slides.length;

                            // Scrolled by how far it is from the middle rather than to an absolute
                            // position: `scrollLeft` counts down from zero in a right-to-left page,
                            // and a distance measured from what is on the screen is the same
                            // arithmetic in both directions.
                            track.scrollBy( {
                                left: offBy( slides[ at ] ),
                                behavior: quiet ? 'instant' : 'smooth',
                            } );
                            paint();
                        }

                        function button( className, label, handler ) {
                            var element = document.createElement( 'button' );

                            element.type = 'button';
                            element.className = className;
                            element.setAttribute( 'aria-label', label );
                            element.addEventListener( 'click', handler );

                            return element;
                        }

                        var previous = button( 'slides__arrow slides__arrow--back', @json(__('site.slides.previous')), function () { go( at - 1 ); } );
                        var next = button( 'slides__arrow slides__arrow--on', @json(__('site.slides.next')), function () { go( at + 1 ); } );

                        var dots = document.createElement( 'div' );
                        dots.className = 'slides__dots';

                        slides.forEach( function ( slide, index ) {
                            dots.appendChild( button( 'slides__dot', @json(__('site.slides.goTo')).replace( ':number', index + 1 ), function () { go( index ); } ) );
                        } );

                        function paint() {
                            Array.prototype.forEach.call( dots.children, function ( dot, index ) {
                                dot.classList.toggle( 'is-here', index === at );
                                dot.setAttribute( 'aria-current', index === at ? 'true' : 'false' );
                            } );
                        }

                        nav.appendChild( previous );
                        nav.appendChild( dots );
                        nav.appendChild( next );
                        show.appendChild( nav );
                        paint();

                        // Which slide somebody scrolled to themselves, so the dots keep up with a
                        // swipe rather than only with the buttons.
                        track.addEventListener( 'scroll', function () {
                            var nearest = 0;
                            var best = Infinity;

                            slides.forEach( function ( slide, index ) {
                                var distance = Math.abs( offBy( slide ) );

                                if ( distance < best ) {
                                    best = distance;
                                    nearest = index;
                                }
                            } );

                            if ( nearest !== at ) {
                                at = nearest;
                                paint();
                            }
                        }, { passive: true } );

                        if ( show.dataset.autoplay && ! quiet ) {
                            var every = Number( show.dataset.autoplay ) || 6000;

                            var start = function () {
                                stop();
                                timer = window.setInterval( function () { go( at + 1 ); }, every );
                            };
                            var stop = function () {
                                if ( timer ) {
                                    window.clearInterval( timer );
                                    timer = null;
                                }
                            };

                            [ 'mouseenter', 'focusin', 'touchstart' ].forEach( function ( name ) {
                                show.addEventListener( name, stop, { passive: true } );
                            } );
                            [ 'mouseleave', 'focusout' ].forEach( function ( name ) {
                                show.addEventListener( name, start );
                            } );

                            start();
                        }
                    } );
                }() );
            </script>
        @endpush
    @endonce
@endif
