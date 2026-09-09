{{--
    An invoice, printed from the row that was frozen when its number was issued.

    Nothing here reads a live event, site or order: an organiser who corrects their address must not
    silently rewrite a document somebody has already filed with their accounts.
--}}
<!doctype html>
<html dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
	<meta charset="utf-8">
	<style>
		body { font-size: 10.5pt; color: #111; }
		h1 { font-size: 18pt; margin: 0 0 1mm; }
		.muted { color: #666; }
		.parties { width: 100%; margin-block: 8mm 6mm; }
		.parties td { vertical-align: top; width: 50%; }
		.label {
			font-size: 8pt; letter-spacing: 0.08em; text-transform: uppercase; color: #666;
			margin-block-end: 1.5mm;
		}
		.lines { width: 100%; border-collapse: collapse; margin-block-start: 4mm; }
		.lines th {
			text-align: {{ $rtl ? 'right' : 'left' }};
			font-size: 8.5pt; text-transform: uppercase; letter-spacing: 0.06em; color: #666;
			border-block-end: 0.4mm solid #111; padding-block: 2mm;
		}
		.lines td { padding-block: 2mm; border-block-end: 0.2mm solid #ddd; }
		.num { text-align: {{ $rtl ? 'left' : 'right' }}; }
		.totals { width: 100%; margin-block-start: 4mm; }
		.totals td { padding-block: 1.2mm; }
		.totals .grand td { font-weight: bold; font-size: 12pt; border-block-start: 0.4mm solid #111; padding-block-start: 2.5mm; }
		.footer { margin-block-start: 12mm; font-size: 8.5pt; color: #666; }
	</style>
</head>
<body>

<h1>{{ __('site.invoice.title') }}</h1>
<p class="muted">
	{{ $invoice->number }} ·
	{{-- The date it was issued, spelled the reader's way — an invoice is filed by its date. --}}
	{{ \App\Support\Locale\Dates::pattern($invoice->issued_at, 'd MMMM y') }}
</p>

<table class="parties">
	<tr>
		<td>
			<div class="label">{{ __('site.invoice.from') }}</div>
			<div dir="auto"><strong>{{ $invoice->issuer['name'] }}</strong></div>
			@if (! empty($invoice->issuer['address']))
				<div dir="auto">{!! nl2br(e($invoice->issuer['address'])) !!}</div>
			@endif
			@if (! empty($invoice->issuer['tax_number']))
				<div>{{ __('site.invoice.taxNumber', ['number' => $invoice->issuer['tax_number']]) }}</div>
			@endif
		</td>
		<td>
			<div class="label">{{ __('site.invoice.to') }}</div>
			<div dir="auto"><strong>{{ $invoice->buyer['name'] }}</strong></div>
			@if (! empty($invoice->buyer['address']))
				<div dir="auto">{!! nl2br(e($invoice->buyer['address'])) !!}</div>
			@endif
			@if (! empty($invoice->buyer['tax_number']))
				<div>{{ __('site.invoice.taxNumber', ['number' => $invoice->buyer['tax_number']]) }}</div>
			@endif
			@if (! empty($invoice->buyer['email']))
				<div class="muted">{{ $invoice->buyer['email'] }}</div>
			@endif
		</td>
	</tr>
</table>

<table class="lines">
	<tr>
		<th>{{ __('site.invoice.description') }}</th>
		<th class="num">{{ __('site.invoice.quantity') }}</th>
		<th class="num">{{ __('site.invoice.unit') }}</th>
		<th class="num">{{ __('site.invoice.amount') }}</th>
	</tr>
	@foreach ($invoice->lines as $line)
		<tr>
			<td dir="auto">{{ $line['description'] }}</td>
			<td class="num">{{ \App\Support\Locale\Money::number($line['quantity']) }}</td>
			<td class="num">{{ $money($line['unit_amount']) }}</td>
			<td class="num">{{ $money($line['amount']) }}</td>
		</tr>
	@endforeach
</table>

@php($totals = $invoice->totals)

<table class="totals">
	@if (! empty($totals['discount']))
		<tr>
			<td>{{ __('site.invoice.discount') }}</td>
			<td class="num">−{{ $money($totals['discount']) }}</td>
		</tr>
	@endif
	@if (! empty($totals['fee']))
		<tr>
			<td>{{ $totals['fee_label'] ?? __('site.totals.fee') }}</td>
			<td class="num">{{ $money($totals['fee']) }}</td>
		</tr>
	@endif
	@if (! empty($totals['tax']))
		<tr>
			{{-- Said either way round, because an inclusive tax is already in the grand total
			     below and adding it again would make the document contradict itself. --}}
			<td>{{ __(empty($totals['tax_included']) ? 'site.totals.tax' : 'site.totals.taxIncluded', [
				'name' => $totals['tax_label'] ?? __('site.totals.taxName'),
				'rate' => \App\Support\Locale\Money::number(($totals['tax_rate'] ?? 0) / 100, null, 2),
			]) }}</td>
			<td class="num">{{ $money($totals['tax']) }}</td>
		</tr>
	@endif
	<tr class="grand">
		<td>{{ __('site.invoice.total') }}</td>
		<td class="num">{{ $money($totals['total']) }}</td>
	</tr>
</table>

@if (! empty($invoice->issuer['footer']))
	<p class="footer" dir="auto">{{ $invoice->issuer['footer'] }}</p>
@endif

</body>
</html>
