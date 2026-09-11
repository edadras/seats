@if ($block['text'])
    <section class="shell section section--tight">
        @if ($block['collapsed'])
            {{-- Folded: terms that push the booking button off the screen are terms that cost
                 tickets. `details` rather than a script, so it opens for a reader with no
                 JavaScript, prints open, and is searchable by the browser's own find. --}}
            <details class="terms">
                <summary class="terms__summary">{{ $block['title'] ?: __('site.terms.title') }}</summary>
                <div class="prose terms__body" dir="auto">{!! nl2br(e($block['text'])) !!}</div>
            </details>
        @else
            <div class="terms terms--open">
                @if ($block['title'])
                    <h2 class="terms__title">{{ $block['title'] }}</h2>
                @endif
                <div class="prose terms__body" dir="auto">{!! nl2br(e($block['text'])) !!}</div>
            </div>
        @endif
    </section>
@endif
