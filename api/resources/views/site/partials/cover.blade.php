{{--
    An event's poster, or one drawn for it.

    Organisers start with nothing uploaded, and a grey rectangle where the artwork goes makes a
    brand-new site look broken rather than new. So the fallback is a real cover: a hue hashed from
    the event's name — stable, so a listing never reshuffles its colours — and the name's own
    initials set large.
--}}
<div class="cover {{ $image ? '' : 'cover--drawn' }}" style="--art-h: {{ $hue }}" aria-hidden="true">
    @if ($image)
        <img src="{{ $image }}" alt="" loading="lazy" decoding="async">
    @else
        <span class="cover__initials">{{ $initials }}</span>
    @endif
</div>
