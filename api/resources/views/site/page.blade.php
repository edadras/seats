@extends('site.layout')

@section('content')
    @forelse ($blocks as $block)
        @includeIf('site.blocks.'.$block['type'], ['block' => $block])
    @empty
        <div class="shell section">
            <p class="muted">This page has nothing on it yet.</p>
        </div>
    @endforelse
@endsection
