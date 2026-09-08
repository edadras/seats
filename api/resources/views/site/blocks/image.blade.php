@if ($block['url'])
    <figure class="section image image--{{ $block['width'] }}">
        <img src="{{ $block['url'] }}" alt="{{ $block['alt'] }}" loading="lazy">
        @if ($block['caption'])
            <figcaption>{{ $block['caption'] }}</figcaption>
        @endif
    </figure>
@endif
