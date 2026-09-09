@extends('site.layout')

@section('content')
    <section class="shell section account">
        <div class="section__head">
            <div>
                <span class="eyebrow">{{ $site->name }}</span>
                <h1 class="section__title">{{ __('site.account.title') }}</h1>
            </div>

            @if ($buyer)
                <form method="POST" action="/account/sign-out" class="account__out">
                    @csrf
                    <span class="muted">{{ __('site.account.signedInAs', ['email' => $buyer['email']]) }}</span>
                    <button class="button button--quiet" type="submit">{{ __('site.account.signOut') }}</button>
                </form>
            @endif
        </div>

        @if ($notice)
            {{-- One of three words this application put in the URL itself, not whatever arrived. --}}
            <p class="notice">{{ __('site.account.'.(in_array($notice, ['cancelled', 'failed', 'expired'], true) ? $notice : 'failed')) }}</p>
        @endif

        @if (! $buyer)
            <div class="signin">
                <p class="prose">{{ __('site.account.blurb') }}</p>

                @if ($canSignIn)
                    <a class="button button--google" href="/account/google">
                        {{-- Google's own mark, as their sign-in guidance requires. --}}
                        <svg class="google-g" width="18" height="18" viewBox="0 0 18 18" aria-hidden="true">
                            <path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.7-1.57 2.68-3.88 2.68-6.62Z"/>
                            <path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.81.54-1.85.86-3.04.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.33A9 9 0 0 0 9 18Z"/>
                            <path fill="#FBBC05" d="M3.97 10.72a5.4 5.4 0 0 1 0-3.44V4.95H.96a9 9 0 0 0 0 8.1l3.01-2.33Z"/>
                            <path fill="#EA4335" d="M9 3.58c1.32 0 2.5.45 3.44 1.35l2.58-2.58C13.46.89 11.43 0 9 0A9 9 0 0 0 .96 4.95l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58Z"/>
                        </svg>
                        {{ __('site.account.signIn') }}
                    </a>
                @else
                    <p class="muted">{{ __('site.account.notOffered') }}</p>
                @endif

                <p class="field__hint">{{ __('site.account.emailHint') }}</p>
            </div>
        @elseif (! count($orders))
            <p class="prose">{{ __('site.account.nothing') }}</p>
            <p class="muted">{{ __('site.account.nothingBody') }}</p>
        @else
            <div class="orders">
                @foreach ($orders as $order)
                    <article class="order">
                        <header class="order__head">
                            <div>
                                <h2 class="order__event" dir="auto">{{ $order['event']?->name }}</h2>
                                <p class="muted">
                                    {{ __('site.account.placed', [
                                        'date' => \App\Support\Locale\Dates::longWhen($order['placed_at']->setTimezone($site->timezone)),
                                    ]) }}
                                    · <code>{{ $order['reference'] }}</code>
                                </p>
                            </div>
                            <div class="order__figures">
                                <span class="pillbox">{{ __('site.status.'.$order['status']) }}</span>
                                <span class="order__total">{{ $order['total'] }}</span>
                            </div>
                        </header>

                        <ul class="order__lines">
                            @foreach ($order['lines'] as $line)
                                <li>
                                    <span dir="auto">
                                        {{ $line['seat'] ?: __('site.standing') }}
                                        @if (! $line['seat'] || $line['quantity'] > 1)
                                            <span class="muted">× {{ \App\Support\Locale\Money::number($line['quantity']) }}</span>
                                        @endif
                                    </span>
                                    @if ($line['used'])
                                        <span class="pillbox pillbox--used">{{ __('site.account.used') }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>

                        @if ($order['reissuable'])
                            <form class="order__actions" method="POST"
                                  action="/account/orders/{{ $order['reference'] }}/tickets">
                                @csrf
                                <button class="button" type="submit">{{ __('site.account.getTickets') }}</button>
                                {{-- Said before the button is pressed, because it cannot be undone:
                                     the platform keeps a hash of the code it emailed and cannot
                                     hand that code back, so the only way to give somebody their
                                     ticket again is to make a new one. --}}
                                <span class="field__hint">{{ __('site.account.reissueWarning') }}</span>
                            </form>
                        @else
                            <p class="field__hint">{{ __('site.account.nothingToReissue') }}</p>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </section>
@endsection
