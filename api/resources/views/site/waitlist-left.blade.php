@extends('site.layout')

@section('content')
    <section class="shell section">
        <h1 class="section__title">{{ __('site.waitlist.leftTitle') }}</h1>
        <p class="lede">{{ __('site.waitlist.leftBody') }}</p>
        <p><a class="button button--quiet" href="/">{{ __('site.waitlist.backHome') }}</a></p>
    </section>
@endsection
