{{-- Paragraphs from plain text: an organiser types prose, not markup. --}}
<section class="shell section section--tight">
    <div class="prose" dir="auto">
        @foreach (preg_split('/\R{2,}/', $block['text']) as $paragraph)
            @if (trim($paragraph) !== '')
                <p>{!! nl2br(e(trim($paragraph))) !!}</p>
            @endif
        @endforeach
    </div>
</section>
