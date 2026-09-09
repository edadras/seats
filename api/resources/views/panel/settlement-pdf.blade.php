{{--
	A settlement statement: the document an organiser sends a venue at the end of a run.

	Everything on it comes from the settlement array the report screen shows, so the paper and the
	screen can never disagree about what is owed.
--}}
<!doctype html>
<html dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
	<meta charset="utf-8">
	<style>
		body { font-size: 10pt; color: #111; }
		h1 { font-size: 17pt; margin: 0 0 1mm; }
		h2 { font-size: 11pt; margin: 8mm 0 2mm; }
		.muted { color: #666; }
		.lines { width: 100%; border-collapse: collapse; margin-block-start: 3mm; }
		.lines th {
			text-align: {{ $rtl ? 'right' : 'left' }};
			font-size: 8pt; text-transform: uppercase; letter-spacing: 0.06em; color: #666;
			border-block-end: 0.4mm solid #111; padding-block: 2mm;
		}
		.lines td { padding-block: 1.8mm; border-block-end: 0.2mm solid #ddd; }
		.num { text-align: {{ $rtl ? 'left' : 'right' }}; white-space: nowrap; }
		.totals { width: 100%; margin-block-start: 3mm; }
		.totals td { padding-block: 1.2mm; }
		.totals .grand td {
			font-weight: bold; font-size: 12pt;
			border-block-start: 0.4mm solid #111; padding-block-start: 2.5mm;
		}
		.footer { margin-block-start: 10mm; font-size: 8pt; color: #666; }
	</style>
</head>
<body>

<h1>{{ __('panel.settlement.title') }}</h1>
<p class="muted" dir="auto">
	{{ $tenant->name }}
	@if ($settlement['period']['from'] || $settlement['period']['to'])
		·
		{{ __('panel.settlement.between', [
			'from' => $settlement['period']['from']
				? \App\Support\Locale\Dates::pattern(new \DateTimeImmutable($settlement['period']['from']), 'd MMMM y')
				: '—',
			'to' => $settlement['period']['to']
				? \App\Support\Locale\Dates::pattern(new \DateTimeImmutable($settlement['period']['to']), 'd MMMM y')
				: '—',
		]) }}
	@endif
	·
	{{ __('paid' === $settlement['period']['basis'] ? 'panel.settlement.basisPaid' : 'panel.settlement.basisEvent') }}
</p>

@if ([] === $settlement['rows'])
	<p>{{ __('panel.settlement.empty') }}</p>
@else
	<table class="lines">
		<thead>
			<tr>
				<th>{{ __('panel.settlement.event') }}</th>
				<th class="num">{{ __('panel.settlement.seats') }}</th>
				<th class="num">{{ __('panel.settlement.charged') }}</th>
				<th class="num">{{ __('panel.settlement.refunded') }}</th>
				<th class="num">{{ __('panel.settlement.commission') }}</th>
				<th class="num">{{ __('panel.settlement.payable') }}</th>
			</tr>
		</thead>
		<tbody>
			@foreach ($settlement['rows'] as $row)
				<tr>
					<td dir="auto">
						{{ $row['event']['name'] }}
						@if ($row['event']['starts_at'])
							<div class="muted">
								{{ \App\Support\Locale\Dates::pattern(new \DateTimeImmutable($row['event']['starts_at']), 'd MMMM y') }}
							</div>
						@endif
					</td>
					<td class="num">{{ \App\Support\Locale\Money::number($row['seats']) }}</td>
					<td class="num">{{ $money($row['charged'], $row['currency']) }}</td>
					{{-- A dash rather than a zero: nothing came back, and a column of "0.00" reads
					     as an amount somebody has to check. --}}
					<td class="num">{{ $row['refunded'] ? $money(-$row['refunded'], $row['currency']) : '—' }}</td>
					<td class="num">{{ $row['commission'] ? $money(-$row['commission'], $row['currency']) : '—' }}</td>
					<td class="num">{{ $money($row['payable'], $row['currency']) }}</td>
				</tr>
			@endforeach
		</tbody>
	</table>

	@foreach ($settlement['totals'] as $total)
		<h2>{{ $total['currency'] }}</h2>
		<table class="totals">
			<tr>
				<td>{{ __('panel.settlement.tickets') }}</td>
				<td class="num">{{ $money($total['tickets'], $total['currency']) }}</td>
			</tr>
			@if ($total['discount'] > 0)
				<tr>
					<td>{{ __('panel.settlement.discount') }}</td>
					<td class="num">{{ $money(-$total['discount'], $total['currency']) }}</td>
				</tr>
			@endif
			@if ($total['fee'] > 0)
				<tr>
					<td>{{ __('panel.settlement.fee') }}</td>
					<td class="num">{{ $money($total['fee'], $total['currency']) }}</td>
				</tr>
			@endif
			<tr>
				<td>{{ __('panel.settlement.charged') }}</td>
				<td class="num">{{ $money($total['charged'], $total['currency']) }}</td>
			</tr>
			@if ($total['refunded'] > 0)
				<tr>
					<td>{{ __('panel.settlement.refunded') }}</td>
					<td class="num">{{ $money(-$total['refunded'], $total['currency']) }}</td>
				</tr>
			@endif
			@if ($total['tax_kept'] > 0)
				<tr>
					<td class="muted">{{ __('panel.settlement.taxKept') }}</td>
					<td class="num muted">{{ $money($total['tax_kept'], $total['currency']) }}</td>
				</tr>
			@endif
			@if ($total['commission'] > 0)
				<tr>
					<td>
						{{ __('panel.settlement.commissionAt', [
							'rate' => \App\Support\Locale\Money::number($settlement['commission_rate'] / 100, null, 2),
						]) }}
					</td>
					<td class="num">{{ $money(-$total['commission'], $total['currency']) }}</td>
				</tr>
			@endif
			<tr class="grand">
				<td>{{ __('panel.settlement.payable') }}</td>
				<td class="num">{{ $money($total['payable'], $total['currency']) }}</td>
			</tr>
		</table>
	@endforeach
@endif

<p class="footer">{{ __('panel.settlement.note') }}</p>

</body>
</html>
