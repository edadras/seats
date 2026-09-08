@php $event = $eventFor($block); @endphp

@if ($event)
    <section class="shell section">
        <h1 class="event__name">{{ $event['name'] }}</h1>
        <p class="event__when">{{ $event['long_when'] }}@if ($event['venue']) · {{ $event['venue'] }}@endif</p>

        @if ($event['description'])
            <div class="prose event__description">{!! nl2br(e($event['description'])) !!}</div>
        @endif
    </section>

    @if ($event['on_sale'])
        <section class="shell section">
            {{-- The same picker the WordPress plugin ships; see tools/sync-seat-picker.sh. --}}
            <div class="seatmap-widget" id="{{ $event['container_id'] }}" data-event="{{ $event['public_id'] }}">
                <noscript>Choosing seats needs JavaScript. Please enable it, or contact the box office.</noscript>
            </div>
        </section>

        @push('head')
            <link rel="stylesheet" href="{{ asset('site/css/widget.css') }}">
        @endpush

        @push('scripts')
            <script>
                window.seatmapBoot = window.seatmapBoot || [];
                window.seatmapBoot.push(@json($event['boot']));
            </script>
            <script src="{{ asset('site/js/widget.js') }}" defer></script>
        @endpush
    @else
        <section class="shell section">
            <p class="notice">{{ $event['closed_message'] }}</p>
        </section>
    @endif
@endif
