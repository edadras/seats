<?php
/**
 * End-to-end check of the contract between the plugin and the API.
 *
 * It reproduces Seatmap_Client::send()'s signing line for line and drives a whole sale — read,
 * hold, register, confirm, retry, refund — against a running API. If this passes, the two sides
 * genuinely agree; a unit test of either half on its own cannot tell you that.
 *
 * Needs no WordPress, so it is also the fastest way to diagnose a site whose credentials or clock
 * are wrong.
 *
 * Usage:
 *   php roundtrip-check.php <key_id> <secret> <event_public_id> [api_base_url]
 *
 * The values come from `php artisan migrate --seed` in ../../api, or from a real tenant's panel.
 * Run it against a test tenant: it creates and refunds a real order.
 */
$keyId  = $argv[1] ?? '';
$secret = $argv[2] ?? '';
$event  = $argv[3] ?? '';
$base   = rtrim($argv[4] ?? 'http://127.0.0.1:8000', '/');

if ($keyId === '' || $secret === '' || $event === '') {
    fwrite(STDERR, "Usage: php roundtrip-check.php <key_id> <secret> <event_public_id> [api_base_url]\n");
    exit(2);
}

function http(string $method, string $url, ?string $body, array $headers): array {
    $ch = curl_init($url);
    $h  = [];
    foreach ($headers as $k => $v) { $h[] = "$k: $v"; }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $h,
    ]);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
    $out = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($out, true), $out];
}

/** The plugin's signing, reproduced exactly. */
function signed(string $method, string $path, ?array $payload, string $keyId, string $secret, ?string $idem): array {
    $body      = $payload === null ? '' : json_encode($payload);
    $timestamp = (string) time();
    $nonce     = bin2hex(random_bytes(8));
    $canonical = implode("\n", [$method, $path, $timestamp, $nonce, hash('sha256', $body)]);

    $headers = [
        'Content-Type'        => 'application/json',
        'Accept'              => 'application/json',
        'X-Seatmap-Key'       => $keyId,
        'X-Seatmap-Timestamp' => $timestamp,
        'X-Seatmap-Nonce'     => $nonce,
        'X-Seatmap-Signature' => hash_hmac('sha256', $canonical, $secret),
    ];
    if ($idem) { $headers['Idempotency-Key'] = $idem; }

    return [$headers, $body];
}

$fail = 0;
function check(string $label, bool $ok, ?string $detail = ''): void {
    global $fail;
    echo ($ok ? "  ok   " : "  FAIL ") . $label . ($detail ? " — $detail" : "") . "\n";
    if (! $ok) { $fail++; }
}

echo "1. Public widget reads\n";
[$code, $ev] = http('GET', "$base/v1/embed/events/$event", null, ['Accept: application/json' => '']);
check('event descriptor', $code === 200 && isset($ev['zones']), "HTTP $code");

[$code, $map] = http('GET', "$base/v1/embed/events/$event/seat-map", null, []);

/** Walk the chart, gathering every seat id. Rows live on a floor or inside a section. */
$collect = function (array $objects) use (&$collect): array {
    $ids = [];

    foreach ($objects as $object) {
        if (($object['type'] ?? '') === 'section') {
            $ids = array_merge($ids, $collect($object['objects'] ?? []));

            continue;
        }

        if (in_array($object['type'] ?? '', ['row', 'table'], true)) {
            foreach ($object['seats'] ?? [] as $seat) {
                if (! empty($seat['seat_id'])) {
                    $ids[] = $seat['seat_id'];
                }
            }
        }
    }

    return $ids;
};

$seatIds = [];

foreach ($map['geometry']['floors'] ?? [] as $floor) {
    $seatIds = array_merge($seatIds, $collect($floor['objects'] ?? []));
}

check('geometry carries stable seat ids', $code === 200 && count($seatIds) > 10, count($seatIds).' seats');

[$code, $avail] = http('GET', "$base/v1/embed/events/$event/availability", null, []);
check('availability lists every seat', $code === 200 && count($avail['seats']) === count($seatIds),
    count($avail['seats'] ?? []).' of '.count($seatIds).', cursor '.($avail['cursor'] ?? '?'));

[$code, $avail0] = http('GET', "$base/v1/embed/events/$event/availability", null, []);
$area = $avail0['areas'][0] ?? null;
check('standing areas reported with places remaining',
    $area !== null && $area['remaining'] > 0,
    $area ? $area['label'].': '.$area['remaining'].' of '.$area['places'] : 'none');

echo "2. Hold two seats and some standing room (as the store does for the buyer)\n";
$chosen = array_slice($seatIds, 0, 2);
$body = ['seat_ids' => $chosen, 'session_id' => 'roundtrip-'.getmypid()];

if ($area) {
    $body['areas'] = [$area['capacity_object_id'] => 3];
}

[$code, $hold] = http('POST', "$base/v1/embed/events/$event/holds",
    json_encode($body),
    ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'Idempotency-Key' => bin2hex(random_bytes(8))]);
check('hold created', $code === 201 && ! empty($hold['hold_token']), "HTTP $code");
check('server-set price', ($hold['total_amount'] ?? 0) > 0, 'total '.($hold['total_amount'] ?? 0));
check('price snapshot signed', ! empty($hold['price_snapshot']['signature']));

if ($area) {
    check('standing places held by quantity',
        ($hold['areas'][0]['quantity'] ?? 0) === 3,
        json_encode($hold['areas'][0] ?? null));

    [$code, $availHeld] = http('GET', "$base/v1/embed/events/$event/availability", null, []);
    check('remaining places drop by the amount held',
        ($availHeld['areas'][0]['remaining'] ?? 0) === $area['remaining'] - 3,
        ($availHeld['areas'][0]['remaining'] ?? '?').' left');
}

echo "3. Signed server-to-server order lifecycle\n";
$orderId = 'wc-roundtrip-'.getmypid();

$path = '/v1/integrations/woocommerce/orders';
[$h, $b] = signed('POST', $path, ['external_order_id' => $orderId, 'hold_token' => $hold['hold_token'],
    'buyer' => ['name' => 'Test Buyer', 'email' => 'buyer@example.test']], $keyId, $secret, "register-$orderId");
[$code, $order] = http('POST', "$base$path", $b, $h);
check('order registered with plugin signature', $code === 201 && $order['status'] === 'pending', "HTTP $code ".json_encode($order['error'] ?? ''));

$path = "/v1/integrations/woocommerce/orders/$orderId/confirm";
[$h, $b] = signed('POST', $path, ['paid_at' => gmdate('c')], $keyId, $secret, "confirm-$orderId");
[$code, $confirmed] = http('POST', "$base$path", $b, $h);
check('confirmed', $code === 200 && $confirmed['status'] === 'confirmed', "HTTP $code");

if ($area) {
    $standing = array_values(array_filter($confirmed['allocations'], fn ($a) => $a['row'] === ''));
    check('standing places allocated as one line',
        count($standing) === 1 && $standing[0]['label'] === '3 places',
        json_encode($standing[0] ?? null));
}
$token = $confirmed['tickets'][0]['token'] ?? null;
check('ticket token issued once', $token !== null && str_starts_with($token, 'TKT'));

echo "4. Retry with the same idempotency key (the plugin's timeout path)\n";
[$h, $b] = signed('POST', $path, ['paid_at' => gmdate('c')], $keyId, $secret, "confirm-$orderId");
[$code, $replay] = http('POST', "$base$path", $b, $h);
check('replayed, not re-sold', $code === 200 && count($replay['allocations']) === count($confirmed['allocations']));
check('same allocation ids', array_column($replay['allocations'], 'id') == array_column($confirmed['allocations'], 'id'));
check('same ticket token returned', ($replay['tickets'][0]['token'] ?? null) === $token, 'a lost response must still be recoverable');

echo "5. Seats now read as sold\n";
[$code, $after] = http('GET', "$base/v1/embed/events/$event/availability", null, []);
$states = [];
foreach ($after['seats'] as $s) { $states[$s['seat_id']] = $s['state']; }
check('both seats allocated',
    ($states[$chosen[0]] ?? '?') === 'allocated' && ($states[$chosen[1]] ?? '?') === 'allocated',
    ($states[$chosen[0]] ?? 'missing').' / '.($states[$chosen[1]] ?? 'missing'));
check('cursor advanced', $after['cursor'] !== $avail['cursor'], "{$avail['cursor']} -> {$after['cursor']}");

echo "6. Tampering is rejected\n";
[$h, $b] = signed('POST', "/v1/integrations/woocommerce/orders/$orderId/cancel", [], $keyId, $secret, null);
[$code, $bad] = http('POST', "$base/v1/integrations/woocommerce/orders/$orderId/refund", $b, $h); // signature aimed elsewhere
check('signature bound to its path', $code === 401 && $bad['error']['code'] === 'invalid_signature', "HTTP $code");

[$h, $b] = signed('POST', "/v1/integrations/woocommerce/orders/$orderId/cancel", [], $keyId, 'wrong-secret', null);
[$code, $bad] = http('POST', "$base/v1/integrations/woocommerce/orders/$orderId/cancel", $b, $h);
check('wrong secret rejected', $code === 401 && $bad['error']['code'] === 'invalid_signature', "HTTP $code");

echo "7. Refund releases the seats\n";
$path = "/v1/integrations/woocommerce/orders/$orderId/refund";
[$h, $b] = signed('POST', $path, ['reason' => 'roundtrip'], $keyId, $secret, "refund-$orderId");
[$code, $refunded] = http('POST', "$base$path", $b, $h);
check('refunded', $code === 200 && $refunded['status'] === 'refunded', "HTTP $code");

[$code, $final] = http('GET', "$base/v1/embed/events/$event/availability", null, []);
$states = [];
foreach ($final['seats'] as $s) { $states[$s['seat_id']] = $s['state']; }
check('seats back on sale', ($states[$chosen[0]] ?? '?') === 'available', (string) ($states[$chosen[0]] ?? 'missing'));

if ($area) {
    check('standing places back on sale',
        ($final['areas'][0]['remaining'] ?? 0) === $area['remaining'],
        ($final['areas'][0]['remaining'] ?? '?').' left');
}

echo "\n" . ($fail === 0 ? "ALL ROUND-TRIP CHECKS PASSED\n" : "$fail CHECK(S) FAILED\n");
exit($fail === 0 ? 0 : 1);
