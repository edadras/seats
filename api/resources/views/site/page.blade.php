@extends('site.layout')

@section('content')
    @forelse ($blocks as $block)
        @includeIf('site.blocks.'.$block['type'], ['block' => $block])
    @empty
        <div class="shell section">
            <p class="muted">{{ __('site.emptyPage') }}</p>
        </div>
    @endforelse
@endsection
