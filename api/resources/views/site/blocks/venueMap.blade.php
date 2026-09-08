<section class="shell section">
    @if ($block['title'])
        <h2 class="block-heading">{{ $block['title'] }}</h2>
    @endif
    <div class="venue">
        @if ($block['address'])
            <address class="venue__address">{!! nl2br(e($block['address'])) !!}</address>
        @endif
        @if ($block['directions'])
            <div class="prose">{!! nl2br(e($block['directions'])) !!}</div>
        @endif
    </div>
</section>
