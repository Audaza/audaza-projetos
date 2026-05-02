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

// ─── 2.5. Crawler social? Servir página com OG meta tags ────────
$ua_check = $_SERVER['HTTP_USER_AGENT'] ?? '';
if (is_social_crawler($ua_check) && (
    !empty($link['og_title']) || !empty($link['og_description']) || !empty($link['og_image'])
)) {
    serve_og_preview($link, $req_host, $slug);
    exit;
}

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

$new_count = log_click((int)$link['id'], $host);

// ─── 8. Avaliar regras de notificação Z-API ─────────────────────
if ($new_count !== null) {
    evaluate_notification_rules((int)$link['id'], $new_count, $host . '/' . $slug, $destination);
}

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

function is_social_crawler(string $ua): bool
{
    return (bool) preg_match(
        '/facebookexternalhit|whatsapp|twitterbot|telegrambot|skype|linkedinbot|slackbot|discordbot|line\/|googlebot|bingbot|yandexbot|baiduspider|pinterestbot|redditbot|applebot|googleimageproxy/i',
        $ua
    );
}

function html_escape($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function serve_og_preview(array $link, string $req_host, string $slug): void
{
    $title       = $link['og_title']       ?: 'Audaza Links';
    $description = $link['og_description'] ?: '';
    $image       = $link['og_image']       ?: '';
    $shortUrl    = 'https://' . $req_host . '/' . $slug;
    $destination = $link['destination'];
    if (!preg_match('#^https?://#i', $destination)) {
        $destination = 'https://' . ltrim($destination, '/');
    }

    $titleE = html_escape($title);
    $descE  = html_escape($description);
    $imageE = html_escape($image);
    $shortE = html_escape($shortUrl);
    $destE  = html_escape($destination);

    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!doctype html>
<html lang="pt-BR"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$titleE}</title>
<meta name="description" content="{$descE}">

<meta property="og:type" content="website">
<meta property="og:url" content="{$shortE}">
<meta property="og:title" content="{$titleE}">
<meta property="og:description" content="{$descE}">
HTML;
    if (!empty($image)) {
        echo "<meta property=\"og:image\" content=\"{$imageE}\">";
        echo "<meta property=\"og:image:secure_url\" content=\"{$imageE}\">";
    }
    echo <<<HTML

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{$titleE}">
<meta name="twitter:description" content="{$descE}">
HTML;
    if (!empty($image)) {
        echo "<meta name=\"twitter:image\" content=\"{$imageE}\">";
    }

    // Fallback: navegador comum (caso por algum motivo passe aqui) redireciona via meta refresh
    echo "<meta http-equiv=\"refresh\" content=\"0; url={$destE}\">";
    echo "<link rel=\"canonical\" href=\"{$destE}\">";

    $destJson = json_encode($destination, JSON_UNESCAPED_SLASHES);
    echo <<<HTML
</head><body>
<p>Redirecionando para <a href="{$destE}">{$destE}</a>…</p>
<script>location.replace({$destJson});</script>
</body></html>
HTML;
}

function log_click(int $link_id, string $host_used): ?int
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

    // Busca click_count atualizado (trigger já incrementou)
    return fetch_click_count($link_id);
}

function fetch_click_count(int $link_id): ?int
{
    $ch = curl_init(SUPABASE_URL . '/rest/v1/audazalinks_links?id=eq.' . $link_id . '&select=click_count');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . SUPABASE_SERVICE_KEY,
            'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
        ],
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    $rows = json_decode($body, true);
    if (!is_array($rows) || empty($rows)) return null;
    return (int)($rows[0]['click_count'] ?? 0);
}

function evaluate_notification_rules(int $link_id, int $click_count, string $short_url, string $destination): void
{
    // Busca regras ativas pra esse link
    $ch = curl_init(SUPABASE_URL . '/rest/v1/audazalinks_notifications?link_id=eq.' . $link_id . '&is_active=eq.true');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . SUPABASE_SERVICE_KEY,
            'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
        ],
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    $rules = json_decode($body, true);
    if (!is_array($rules)) return;

    foreach ($rules as $rule) {
        $type = $rule['rule_type'] ?? '';
        $threshold = (int)($rule['threshold_clicks'] ?? 0);
        if ($threshold <= 0) continue;

        $should_fire = false;

        if ($type === 'milestone') {
            // Dispara quando atinge EXATAMENTE o threshold (ou múltiplos: 100, 200...)
            if ($click_count === $threshold) {
                $should_fire = true;
            } elseif ($threshold >= 10 && $click_count > 0 && ($click_count % $threshold === 0)) {
                $should_fire = true;
            }
        } elseif ($type === 'good_performance') {
            // Cliques no janela > threshold
            $window = max(1, (int)($rule['threshold_window_hours'] ?? 24));
            $count_in_window = count_clicks_in_window($link_id, $window);
            // Dispara só uma vez por janela (last_triggered_at)
            $last = $rule['last_triggered_at'] ?? null;
            $can_fire_again = !$last || (time() - strtotime($last)) > ($window * 3600);
            if ($count_in_window >= $threshold && $can_fire_again) {
                $should_fire = true;
            }
        }

        if ($should_fire) {
            $msg = build_notification_message($rule, $click_count, $short_url, $destination);
            send_zapi_message($rule['notify_phone'], $msg);
            mark_rule_triggered((int)$rule['id']);
        }
    }
}

function count_clicks_in_window(int $link_id, int $hours): int
{
    $since = gmdate('Y-m-d\TH:i:s\Z', time() - ($hours * 3600));
    $url = SUPABASE_URL . '/rest/v1/audazalinks_clicks?link_id=eq.' . $link_id
         . '&clicked_at=gte.' . urlencode($since) . '&select=id';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . SUPABASE_SERVICE_KEY,
            'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
            'Prefer: count=exact',
        ],
        CURLOPT_HEADER         => true,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    if (!$resp) return 0;
    if (preg_match('/content-range:\s*\d+-\d+\/(\d+)/i', $resp, $m)) return (int)$m[1];
    // fallback: conta JSON
    $body_pos = strpos($resp, "\r\n\r\n");
    if ($body_pos !== false) {
        $body = substr($resp, $body_pos + 4);
        $rows = json_decode($body, true);
        return is_array($rows) ? count($rows) : 0;
    }
    return 0;
}

function build_notification_message(array $rule, int $count, string $short_url, string $destination): string
{
    $type = $rule['rule_type'];
    $tpl  = $rule['custom_message'] ?? '';
    if (empty($tpl)) {
        if ($type === 'milestone') {
            $tpl = "🎯 *{count} cliques* no link {short_url}\n→ {destination}";
        } elseif ($type === 'good_performance') {
            $w = (int)($rule['threshold_window_hours'] ?? 24);
            $tpl = "🚀 *Bom desempenho!* {short_url} bateu {count} cliques nas últimas {$w}h.";
        } elseif ($type === 'bad_performance') {
            $tpl = "⚠️ Link {short_url} com desempenho abaixo do esperado: {count} cliques.";
        } elseif ($type === 'system_error') {
            $tpl = "🛑 Erro no sistema audaza-links em {short_url}";
        } else {
            $tpl = "Audaza Links: {count} cliques em {short_url}";
        }
    }
    return strtr($tpl, [
        '{count}' => (string)$count,
        '{short_url}' => 'https://' . $short_url,
        '{destination}' => $destination,
    ]);
}

function send_zapi_message(string $phone, string $message): bool
{
    $clean = preg_replace('/\D+/', '', $phone);
    if ($clean === '') return false;
    $ch = curl_init(ZAPI_BASE . '/send-text');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Client-Token: ' . ZAPI_CLIENT_TOKEN,
        ],
        CURLOPT_POSTFIELDS     => json_encode([
            'phone'   => $clean,
            'message' => $message,
        ]),
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

function mark_rule_triggered(int $rule_id): void
{
    $ch = curl_init(SUPABASE_URL . '/rest/v1/rpc/audazalinks_mark_rule_triggered');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . SUPABASE_SERVICE_KEY,
            'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode(['p_rule_id' => $rule_id]),
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
