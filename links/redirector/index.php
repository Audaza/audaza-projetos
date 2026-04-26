<?php
/**
 * audaza-links — Redirector universal
 * Funciona em link.audaza.com, go.audaza.com e bio.audaza.com.
 *
 * Fluxo:
 *   1. Pega slug + host
 *   2. Lookup no Supabase via RPC audazalinks_lookup
 *   3. Verifica expiração / escolhe variante A/B
 *   4. Anexa UTMs ao destino
 *   5. Redireciona 302 imediatamente
 *   6. Após resposta enviada (fastcgi_finish_request), loga o clique
 */

require_once __DIR__ . '/config.php';

// ─── 1. Extrair slug + host ─────────────────────────────────────
//
// Suporta DOIS modos:
//  (A) Subdomínio:  link.audaza.com/abc  → host="link.audaza.com",   slug="abc"
//  (B) Path-based:  audaza.com/l/abc      → host="audaza.com/l",      slug="abc"
//
// Detecta pelo nome da pasta onde o index.php está.
//   /domains/audaza.com/public_html/l/index.php   → modo B, prefixo "l"
//   /domains/link.audaza.com/public_html/index.php → modo A
//
$path     = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$req_host = strtolower($_SERVER['HTTP_HOST'] ?? '');
$dir_name = basename(dirname(__FILE__));   // pasta onde mora o index.php
$is_path_mode = ($req_host === 'audaza.com' || $req_host === 'www.audaza.com')
                && in_array($dir_name, ['l', 'g', 'b'], true);

if ($is_path_mode) {
    // /l/abc → slug = "abc"
    $stripped = preg_replace('#^/' . preg_quote($dir_name, '#') . '/?#', '', $path);
    $slug = trim($stripped, '/');
    $host = 'audaza.com/' . $dir_name;
} else {
    $slug = trim($path, '/');
    $host = $req_host;
}

if ($slug === '' || $slug === 'index.php' || strpos($slug, '/') !== false) {
    not_found();
}

$allowed_hosts = [
    'link.audaza.com', 'go.audaza.com', 'bio.audaza.com',
    'audaza.com/l',    'audaza.com/g',  'audaza.com/b',
];
if (!in_array($host, $allowed_hosts, true)) {
    not_found();
}

// ─── 2. Lookup no Supabase ──────────────────────────────────────
$rows = supabase_rpc('audazalinks_lookup', [
    'p_host' => $host,
    'p_slug' => $slug,
]);

if (!is_array($rows) || empty($rows)) {
    not_found();
}

$link = $rows[0];

// ─── 3. Verificar expiração ─────────────────────────────────────
if (!empty($link['expires_at']) && strtotime($link['expires_at']) < time()) {
    expired();
}

// ─── 4. Escolher destino (com A/B test se houver) ───────────────
$destination = $link['destination'];

if (!empty($link['ab_variants'])) {
    $variants = is_string($link['ab_variants'])
        ? json_decode($link['ab_variants'], true)
        : $link['ab_variants'];

    if (is_array($variants) && count($variants) > 0) {
        $total = array_sum(array_column($variants, 'weight'));
        if ($total > 0) {
            $rand = mt_rand(1, $total);
            $acc  = 0;
            foreach ($variants as $v) {
                $acc += (int)($v['weight'] ?? 0);
                if ($rand <= $acc) {
                    $destination = $v['url'];
                    break;
                }
            }
        }
    }
}

// ─── 5. Anexar UTMs ─────────────────────────────────────────────
$utms = array_filter([
    'utm_source'   => $link['utm_source']   ?? null,
    'utm_medium'   => $link['utm_medium']   ?? null,
    'utm_campaign' => $link['utm_campaign'] ?? null,
    'utm_term'     => $link['utm_term']     ?? null,
    'utm_content'  => $link['utm_content']  ?? null,
], fn($v) => $v !== null && $v !== '');

if (!empty($utms)) {
    $sep = (strpos($destination, '?') === false) ? '?' : '&';
    $destination .= $sep . http_build_query($utms);
}

// ─── 6. REDIRECT IMEDIATO ───────────────────────────────────────
// Garante esquema absoluto (browser trata URL sem http(s):// como path relativo)
if (!preg_match('#^https?://#i', $destination)) {
    $destination = 'https://' . ltrim($destination, '/');
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Location: ' . $destination, true, 302);

// ─── 7. Logar clique (assíncrono, depois do redirect) ───────────
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
ignore_user_abort(true);
@set_time_limit(10);

log_click((int)$link['id'], $host);

exit;


// ═══════════════════════════════════════════════════════════════
//  FUNÇÕES AUXILIARES
// ═══════════════════════════════════════════════════════════════

function supabase_rpc(string $fn, array $params)
{
    $ch = curl_init(SUPABASE_URL . '/rest/v1/rpc/' . $fn);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . SUPABASE_SERVICE_KEY,
            'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($params),
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200) {
        return null;
    }
    return json_decode($body, true);
}

function log_click(int $link_id, string $host_used): void
{
    $ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ref = $_SERVER['HTTP_REFERER']    ?? null;

    // ─── Device (mobile/tablet/desktop/bot) ───
    $device = 'desktop';
    if (preg_match('/bot|crawler|spider|crawl|http|wget|curl|preview|facebookexternalhit|whatsapp|telegram/i', $ua)) {
        $device = 'bot';
    } elseif (preg_match('/iphone|ipod|android.*mobile|blackberry|opera mini|iemobile|windows phone/i', $ua)) {
        $device = 'mobile';
    } elseif (preg_match('/ipad|tablet|android(?!.*mobile)/i', $ua)) {
        $device = 'tablet';
    }

    // ─── Browser ───
    $browser = 'Outro';
    if (stripos($ua, 'Edg/') !== false)                                              $browser = 'Edge';
    elseif (stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera') !== false)       $browser = 'Opera';
    elseif (stripos($ua, 'SamsungBrowser') !== false)                                $browser = 'Samsung Internet';
    elseif (stripos($ua, 'Firefox/') !== false)                                      $browser = 'Firefox';
    elseif (stripos($ua, 'Chrome/') !== false && stripos($ua, 'Chromium') === false) $browser = 'Chrome';
    elseif (stripos($ua, 'CriOS') !== false)                                         $browser = 'Chrome'; // iOS Chrome
    elseif (stripos($ua, 'FxiOS') !== false)                                         $browser = 'Firefox'; // iOS Firefox
    elseif (stripos($ua, 'Safari/') !== false)                                       $browser = 'Safari';
    elseif (stripos($ua, 'MSIE') !== false || stripos($ua, 'Trident/') !== false)    $browser = 'IE';

    // ─── OS ───
    $os = 'Outro';
    if (preg_match('/Windows NT 10/i', $ua))                $os = 'Windows 10/11';
    elseif (stripos($ua, 'Windows') !== false)              $os = 'Windows';
    elseif (preg_match('/iPhone OS|iOS/i', $ua) || stripos($ua, 'iPhone') !== false) $os = 'iOS';
    elseif (stripos($ua, 'iPad') !== false)                 $os = 'iPadOS';
    elseif (stripos($ua, 'Android') !== false)              $os = 'Android';
    elseif (stripos($ua, 'Macintosh') !== false || stripos($ua, 'Mac OS') !== false) $os = 'macOS';
    elseif (stripos($ua, 'Linux') !== false)                $os = 'Linux';

    // ─── IP real (cuida de proxies/CDN) ───
    $ip = null;
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            break;
        }
    }

    // ─── Geo lookup (best-effort, 2s timeout) ───
    $geo = geo_lookup($ip);

    // ─── Referrer ───
    $referer_host = null;
    if ($ref) {
        $referer_host = parse_url($ref, PHP_URL_HOST);
    }

    $payload = [
        'link_id'      => $link_id,
        'ip'           => $ip,
        'country'      => $geo['country']      ?? null,
        'country_code' => $geo['country_code'] ?? null,
        'region'       => $geo['region']       ?? null,
        'city'         => $geo['city']         ?? null,
        'latitude'     => $geo['latitude']     ?? null,
        'longitude'    => $geo['longitude']    ?? null,
        'user_agent'   => mb_substr($ua, 0, 500),
        'device_type'  => $device,
        'browser'      => $browser,
        'os'           => $os,
        'referer'      => $ref ? mb_substr($ref, 0, 500) : null,
        'referer_host' => $referer_host,
        'host_used'    => $host_used,
        'enriched'     => true,
    ];

    $ch = curl_init(SUPABASE_URL . '/rest/v1/audazalinks_clicks');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . SUPABASE_SERVICE_KEY,
            'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
            'Content-Type: application/json',
            'Prefer: return=minimal',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function geo_lookup(?string $ip): ?array
{
    if (empty($ip) || $ip === '127.0.0.1' || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0) {
        return null;
    }
    // ip-api.com — HTTP, free 45 req/min, sem token
    $url = 'http://ip-api.com/json/' . urlencode($ip) . '?fields=status,country,countryCode,regionName,city,lat,lon';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 2,
        CURLOPT_CONNECTTIMEOUT => 1,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$body) return null;
    $d = json_decode($body, true);
    if (!is_array($d) || ($d['status'] ?? '') !== 'success') return null;
    return [
        'country'      => $d['country']     ?? null,
        'country_code' => $d['countryCode'] ?? null,
        'region'       => $d['regionName']  ?? null,
        'city'         => $d['city']        ?? null,
        'latitude'     => $d['lat']         ?? null,
        'longitude'    => $d['lon']         ?? null,
    ];
}

function not_found(): void
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo render_status('Link não encontrado', 'O link que você acessou não existe ou foi desativado.', '404');
    exit;
}

function expired(): void
{
    http_response_code(410);
    header('Content-Type: text/html; charset=utf-8');
    echo render_status('Link expirado', 'Esse link já passou da data de validade.', '410');
    exit;
}

function render_status(string $title, string $msg, string $code): string
{
    return <<<HTML
<!doctype html>
<html lang="pt-BR"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title} · Audaza</title>
<style>
  body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:linear-gradient(135deg,#0f172a,#1e293b);color:#e2e8f0;padding:24px}
  .card{max-width:420px;text-align:center;padding:48px 32px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:24px;backdrop-filter:blur(12px)}
  .code{font-family:JetBrains Mono,monospace;font-size:14px;letter-spacing:.2em;color:#64748b;margin-bottom:16px}
  h1{margin:0 0 12px;font-size:28px;font-weight:600}
  p{margin:0 0 32px;color:#94a3b8;line-height:1.5}
  a{display:inline-block;padding:12px 24px;background:#f97316;color:#fff;border-radius:12px;text-decoration:none;font-weight:500;font-size:14px}
  a:hover{background:#ea580c}
</style></head>
<body><div class="card">
  <div class="code">ERRO {$code}</div>
  <h1>{$title}</h1>
  <p>{$msg}</p>
  <a href="https://audaza.com">Voltar ao site</a>
</div></body></html>
HTML;
}
