@extends('site.layout')

@section('content')
    <section class="shell section section--tight">
        <h1 class="section__title">{{ __('site.consent.title') }}</h1>

        {{-- The address is shown so somebody can see which of theirs this is about, and nothing
             else about them is: whether they have ever bought anything here is not this page's to
             say, because a valid link only ever reaches the person who owns the address. --}}
        <p class="lede">{{ __('site.consent.about', ['email' => $email]) }}</p>

        @if ($saved)
            <p class="notice">{{ __('site.consent.saved') }}</p>
        @endif

        <form method="POST" action="/preferences/{{ urlencode($email) }}/{{ $token }}">
            @csrf

            <label class="consent">
                <input type="checkbox" name="news" value="1" @checked($wants)>
                <span>{{ __('site.consent.line') }}</span>
            </label>

            <p class="field__hint">{{ __('site.consent.serviceStays') }}</p>

            <button class="button" type="submit">{{ __('site.consent.save') }}</button>
        </form>
    </section>
@endsection
