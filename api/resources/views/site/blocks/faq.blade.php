@if (count($block['items']))
    <section class="shell section">
        @if ($block['title'])
            <h2 class="block-heading">{{ $block['title'] }}</h2>
        @endif
        <div class="faq">
            @foreach ($block['items'] as $item)
                <details class="faq__item">
                    <summary>{{ $item['question'] }}</summary>
                    <div class="prose">{!! nl2br(e($item['answer'])) !!}</div>
                </details>
            @endforeach
        </div>
    </section>
@endif
