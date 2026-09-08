@if (count($block['items']))
    <section class="shell section section--tight">
        <div class="buttons">
            @foreach ($block['items'] as $item)
                <a class="button button--{{ $item['style'] }}" href="{{ $item['href'] }}">{{ $item['label'] }}</a>
            @endforeach
        </div>
    </section>
@endif
