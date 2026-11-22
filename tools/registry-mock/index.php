<?php

/**
 * Mock zewnętrznego rejestru firm i kurierów (ETAP 13, seller-360).
 *
 * Router dla wbudowanego serwera PHP: `php -S 0.0.0.0:8080 tools/registry-mock/index.php`.
 * Dane są deterministyczne z NIP (crc32), więc ten sam NIP zawsze daje tę samą firmę i tego samego kuriera.
 * Wymaga kilku workerów (PHP_CLI_SERVER_WORKERS), bo awaria potrafi trzymać żądanie przez 3 s,
 * a `POST /_chaos` musi się przez nią przebić.
 *
 * Endpointy:
 *   GET  /companies/{nip}              {nip, legal_name, vat_status: active|inactive, registered_at}
 *   GET  /couriers/assignments/{nip}   {courier_id: courier-1..courier-8}
 *   GET  /couriers/ratings?since=ISO   [{courier_id, on_time_rate: 0..1, rated_at}] (tylko oceny nowsze niż since)
 *   GET  /_chaos                       bieżący stan awarii
 *   POST /_chaos                       {delay_ms, error_rate, outage, bump_courier, reset}
 *
 * Chaos (stan w pliku w /tmp, wspólny dla workerów):
 *   delay_ms     stałe opóźnienie każdej odpowiedzi (usleep);
 *   error_rate   0..1, prawdopodobieństwo odpowiedzi 503;
 *   outage       true: każde żądanie czeka 3 s (dłużej niż timeout klienta 2 s, więc działa ścieżka
 *                timeoutu) i kończy się 503;
 *   bump_courier "courier-N": zmienia ocenę tego kuriera i ustawia rated_at = teraz (jednorazowo,
 *                kolejne GET /couriers/ratings?since=... zwrócą go jako zmienionego);
 *   reset        true: czyści cały stan, także zmienione oceny.
 */

const STATE_FILE = '/tmp/registry-mock-chaos.json';
const COURIERS = 8;
const OUTAGE_SECONDS = 3;
/** Oceny bazowe mają rated_at z przeszłości, żeby `since=teraz` ich nie zwracało. */
const BASE_RATED_AT = '2026-11-01T00:00:00Z';

function state(): array
{
    $raw = @file_get_contents(STATE_FILE);
    $state = $raw !== false ? json_decode($raw, true) : null;

    return is_array($state) ? $state + ['delay_ms' => 0, 'error_rate' => 0.0, 'outage' => false, 'bumped' => []]
        : ['delay_ms' => 0, 'error_rate' => 0.0, 'outage' => false, 'bumped' => []];
}

function save(array $state): void
{
    file_put_contents(STATE_FILE, json_encode($state), LOCK_EX);
}

function respond(int $status, mixed $body): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function baseRate(int $n): float
{
    return round(0.80 + (crc32("courier-{$n}") % 200) / 1000, 3);
}

/** @return array{courier_id: string, on_time_rate: float, rated_at: string} */
function rating(int $n, array $state): array
{
    $id = "courier-{$n}";
    $bumped = $state['bumped'][$id] ?? null;

    return [
        'courier_id' => $id,
        'on_time_rate' => $bumped['on_time_rate'] ?? baseRate($n),
        'rated_at' => $bumped['rated_at'] ?? BASE_RATED_AT,
    ];
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/_chaos') {
    $state = state();
    if ($method === 'POST') {
        $in = json_decode((string) file_get_contents('php://input'), true);
        if (! is_array($in)) {
            respond(400, ['error' => 'oczekiwano JSON-a']);
        }
        if (! empty($in['reset'])) {
            $state = ['delay_ms' => 0, 'error_rate' => 0.0, 'outage' => false, 'bumped' => []];
        }
        if (isset($in['delay_ms'])) {
            $state['delay_ms'] = max(0, (int) $in['delay_ms']);
        }
        if (isset($in['error_rate'])) {
            $state['error_rate'] = min(1.0, max(0.0, (float) $in['error_rate']));
        }
        if (isset($in['outage'])) {
            $state['outage'] = (bool) $in['outage'];
        }
        if (! empty($in['bump_courier'])) {
            if (! preg_match('/^courier-([1-8])$/', (string) $in['bump_courier'], $m)) {
                respond(422, ['error' => 'bump_courier: courier-1..courier-8']);
            }
            $current = rating((int) $m[1], $state)['on_time_rate'];
            $state['bumped'][$in['bump_courier']] = [
                'on_time_rate' => round(0.5 + fmod($current - 0.5 + 0.137, 0.499), 3),
                'rated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ];
        }
        save($state);
    }
    respond(200, ['delay_ms' => $state['delay_ms'], 'error_rate' => $state['error_rate'], 'outage' => $state['outage'], 'bumped' => array_keys($state['bumped'])]);
}

// Chaos dotyczy wyłącznie endpointów rejestru.
$state = state();
if ($state['outage']) {
    sleep(OUTAGE_SECONDS);
    respond(503, ['error' => 'outage']);
}
if ($state['delay_ms'] > 0) {
    usleep($state['delay_ms'] * 1000);
}
if ($state['error_rate'] > 0 && mt_rand() / mt_getrandmax() < $state['error_rate']) {
    respond(503, ['error' => 'losowy błąd']);
}

if ($method !== 'GET') {
    respond(405, ['error' => 'method not allowed']);
}

if (preg_match('#^/companies/(\d{10})$#', $path, $m)) {
    $h = crc32($m[1]);
    $prefixes = ['Alfa', 'Beta', 'Gamma', 'Delta', 'Omega', 'Nowak', 'Kowalski', 'Wisła', 'Tatra', 'Bałtyk'];
    $suffixes = ['Sp. z o.o.', 'S.A.', 'Sp. j.', 'Handel', 'Logistyka'];
    respond(200, [
        'nip' => $m[1],
        'legal_name' => $prefixes[$h % 10].' '.$suffixes[intdiv($h, 10) % 5].' '.($h % 900 + 100),
        'vat_status' => $h % 10 < 8 ? 'active' : 'inactive',
        'registered_at' => gmdate('Y-m-d', strtotime('2005-01-01') + ($h % 7000) * 86400),
    ]);
}

if (preg_match('#^/couriers/assignments/(\d{10})$#', $path, $m)) {
    respond(200, ['courier_id' => 'courier-'.(crc32($m[1]) % COURIERS + 1)]);
}

if ($path === '/couriers/ratings') {
    $since = isset($_GET['since']) ? strtotime((string) $_GET['since']) : false;
    $out = [];
    for ($n = 1; $n <= COURIERS; $n++) {
        $r = rating($n, $state);
        if ($since === false || strtotime($r['rated_at']) > $since) {
            $out[] = $r;
        }
    }
    respond(200, $out);
}

respond(404, ['error' => 'not found']);
