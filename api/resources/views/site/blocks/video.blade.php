@if ($block['provider'])
    <section class="section">
        <div class="shell">
            @if ($block['title'])
                <h2 class="block-heading">{{ $block['title'] }}</h2>
            @endif

            @if ('file' === $block['provider'])
                {{-- Nobody else is involved: the browser plays the file with its own controls. --}}
                <video class="video__player" controls preload="none"
                       @if ($block['poster']) poster="{{ $block['poster'] }}" @endif>
                    <source src="{{ $block['key'] }}">
                </video>
            @else
                @php
                    // Built from the provider and the id this block was sanitised into, never from
                    // anything an organiser typed: that is the whole of the defence here.
                    $embed = 'youtube' === $block['provider']
                        ? 'https://www.youtube-nocookie.com/embed/'.$block['key'].'?autoplay=1&rel=0'
                        : 'https://player.vimeo.com/video/'.$block['key'].'?autoplay=1';
                @endphp

                {{--
                    A poster with a play button, and the film itself only once somebody asks.

                    Loading the player straight away would hand every visitor to a third party
                    before they have shown any interest in watching anything — on a page that is
                    otherwise about buying a ticket from this venue. It is also most of a megabyte.
                --}}
                <div class="video" data-video data-embed="{{ $embed }}"
                     @if ($block['poster']) style="background-image:url('{{ $block['poster'] }}')" @endif>
                    <button class="video__play" type="button"
                            aria-label="{{ $block['title'] ?: __('site.video.play') }}">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M8 5.5v13l11-6.5-11-6.5Z" fill="currentColor"/>
                        </svg>
                    </button>

                    <noscript>
                        {{-- No script, no iframe: a plain link to the thing itself. --}}
                        <a class="video__away" href="{{ $block['url'] }}" rel="noopener noreferrer"
                           target="_blank">{{ __('site.video.watch') }}</a>
                    </noscript>
                </div>
            @endif

            @if ($block['caption'])
                <p class="video__caption muted" dir="auto">{{ $block['caption'] }}</p>
            @endif
        </div>
    </section>

    @once
        @push('scripts')
            <script>
                /*
                 * Swap the poster for the player, once, when somebody asks for it.
                 *
                 * `allow="autoplay"` because they have just pressed play: the autoplay in the
                 * address is what saves them pressing it a second time inside the frame.
                 */
                ( function () {
                    document.querySelectorAll( '[data-video]' ).forEach( function ( box ) {
                        var button = box.querySelector( '.video__play' );

                        if ( ! button ) {
                            return;
                        }

                        button.addEventListener( 'click', function () {
                            var frame = document.createElement( 'iframe' );

                            frame.src = box.dataset.embed;
                            frame.title = button.getAttribute( 'aria-label' ) || '';
                            frame.setAttribute( 'allow', 'autoplay; fullscreen; picture-in-picture' );
                            frame.setAttribute( 'allowfullscreen', '' );
                            frame.setAttribute( 'loading', 'lazy' );
                            frame.className = 'video__frame';

                            box.innerHTML = '';
                            box.appendChild( frame );
                            box.classList.add( 'is-playing' );
                        } );
                    } );
                }() );
            </script>
        @endpush
    @endonce
@endif
