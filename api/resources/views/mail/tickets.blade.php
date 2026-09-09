{{--
    Ticket email.

    Tables and inline styles on purpose: this is the one place in the project where a stylesheet is
    not an option, because a mail client will not load one.
--}}
<!doctype html>
{{-- An email is read in a client we do not control, so direction goes on the root element and on
     the body: some clients keep one and drop the other. --}}
@php($locale = app()->getLocale())
@php($dir = \App\Support\Locale\Locales::direction($locale))
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head><meta charset="utf-8"><title>{{ __('mail.title') }}</title></head>
<body dir="{{ $dir }}" style="margin:0;padding:24px;background:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#1b2030;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;">
    <tr>
        <td style="padding:24px;background:#ffffff;border-radius:12px;">
            <h1 style="margin:0 0 4px;font-size:20px;">{{ $site->name }}</h1>
            <p style="margin:0 0 20px;color:#5f6878;font-size:14px;">
                {{ $order->event?->name }}@if ($order->event?->starts_at) ·
                    {{-- Not format(): `l j F Y` prints English month names in every language, and
                         the calendar matters too — an Iranian reader wants ۷ مهر, not 29 September. --}}
                    {{ \App\Support\Locale\Dates::longWhen($order->event->starts_at->setTimezone($site->timezone), $locale) }}
                @endif
            </p>

            <p style="margin:0 0 20px;font-size:15px;">{{ __('mail.intro') }}</p>

            @foreach ($tickets as $ticket)
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                       style="margin-bottom:12px;border:1px solid #e3e6ec;border-radius:10px;">
                    <tr>
                        <td style="padding:14px;width:120px;" align="center">
                            <img src="{{ $ticket['qr'] }}" width="110" height="110" alt="" style="display:block;">
                        </td>
                        <td style="padding:14px;">
                            <p style="margin:0 0 4px;font-weight:600;font-size:15px;">
                                {{ $ticket['seat'] ?: __('mail.standing') }}
                                @if ('' === $ticket['seat'] && $ticket['quantity'])
                                    <span style="color:#5f6878;">× {{ $ticket['quantity'] }}</span>
                                @endif
                            </p>
                            @if (! empty($ticket['entry']))
                                <p style="margin:0 0 4px;font-weight:600;font-size:13px;">
                                    {{ $ticket['entry'] }}
                                </p>
                            @endif
                            <p style="margin:0;font-family:ui-monospace,Menlo,monospace;font-size:12px;color:#5f6878;word-break:break-all;">
                                {{ $ticket['token'] }}
                            </p>
                        </td>
                    </tr>
                </table>
            @endforeach

            <p style="margin:20px 0 0;color:#5f6878;font-size:13px;">
                {{ __('mail.keepThis', ['reference' => $order->external_order_id]) }}
            </p>
        </td>
    </tr>
</table>
</body>
</html>
