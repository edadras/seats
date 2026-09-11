@if (count($block['items']))
    {{-- The facts, as a description list: somebody reading this is looking for one of the rows,
         not for the paragraph it would otherwise be buried in. --}}
    <section class="shell section">
        @if ($block['title'])
            <h2 class="block-heading">{{ $block['title'] }}</h2>
        @endif

        <dl class="specs">
            @foreach ($block['items'] as $item)
                <div class="specs__row">
                    <dt class="specs__label" dir="auto">{{ $item['label'] }}</dt>
                    <dd class="specs__value" dir="auto">{!! nl2br(e($item['value'])) !!}</dd>
                </div>
            @endforeach
        </dl>
    </section>
@endif
