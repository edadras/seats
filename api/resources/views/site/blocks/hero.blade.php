{{--
    The band across the top of a page.

    A picture, a name and a sentence — the three things a visitor uses to decide whether they are
    in the right place. With no picture it falls back to the site's own accent rather than to a grey
    box, so a page is presentable before anybody has uploaded anything.
--}}
<section class="hero hero--{{ $block['height'] }} @if ('center' === $block['align']) hero--center @endif">
    @if ($block['url'])
        <img class="hero__art" src="{{ $block['url'] }}" alt="" fetchpriority="high" decoding="async">
    @endif

    <div class="shell hero__body">
        @if ($block['title'])
            <h1 class="hero__title" dir="auto">{{ $block['title'] }}</h1>
        @endif

        @if ($block['subtitle'])
            <p class="hero__subtitle" dir="auto">{{ $block['subtitle'] }}</p>
        @endif
    </div>
</section>
