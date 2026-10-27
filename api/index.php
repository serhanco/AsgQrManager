<?php
/**
 * api/index.php — REST API v1 (JSON)
 *
 * Kimlik doğrulama: "Authorization: Bearer qrm_..." veya "X-API-Key: qrm_..."
 * QR'lar API ile SİLİNEMEZ: yalnızca arşivlenir (archive) ve geri alınabilir (restore).
 * Ayrıntılar: /admin/api-docs
 */
require_once __DIR__ . '/../lib/bootstrap.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function apiOut(int $status, array $payload): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}
function apiError(int $status, string $code, string $message, array $extra = []): never {
    apiOut($status, ['error' => ['code' => $code, 'message' => $message] + $extra]);
}

// ─── HTTPS zorunlu (anahtar düz metin gitmesin) ───────────────────────────────
$host = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = (bool)preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/', $host);
if (!Auth::isHttps() && !$isLocal && !(defined('API_ALLOW_HTTP') && API_ALLOW_HTTP)) {
    apiError(403, 'https_required', 'API yalnızca HTTPS üzerinden kullanılabilir.');
}

// ─── Kimlik doğrulama ─────────────────────────────────────────────────────────
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$token = '';
if (preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) $token = $m[1];
elseif (!empty($_SERVER['HTTP_X_API_KEY'])) $token = trim($_SERVER['HTTP_X_API_KEY']);

$pdo = getPdo();
try {
    // API ilk istekte şemayı (yalnızca ekleme) güncel tutar
    if (Migrator::pending($pdo)) Migrator::run($pdo);
    $tok = $token !== '' ? ApiAuth::verify($pdo, $token) : null;
} catch (\Throwable $e) {
    Logger::error('API doğrulama hatası: ' . $e->getMessage());
    apiError(500, 'server_error', 'Geçici bir hata oluştu.');
}
if (!$tok || !ApiAuth::applyContext($pdo, $tok)) {
    header('WWW-Authenticate: Bearer realm="qr-manager"');
    apiError(401, 'unauthorized', 'Geçersiz veya eksik API anahtarı.');
}
$actor = ['id' => Access::userId(), 'name' => 'api:' . $tok['name']];

// ─── Yönlendirme ──────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$route  = trim((string)($_GET['_route'] ?? ''), '/');
$parts  = $route === '' ? [] : explode('/', $route);

// Gövde (JSON veya form)
$body = [];
if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if (strlen($raw) > 65536) apiError(413, 'too_large', 'İstek gövdesi en fazla 64 KB olabilir.');
    if ($raw !== '' && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'json')) {
        $body = json_decode($raw, true);
        if (!is_array($body)) apiError(400, 'invalid_json', 'Gövde geçerli bir JSON nesnesi olmalı.');
    } else {
        $body = $_POST;
    }
}

function needWrite(array $tok): void {
    if (!ApiAuth::can($tok, 'write')) apiError(403, 'forbidden', 'Bu anahtarın yazma yetkisi yok.');
}
/** Editör yalnızca kendi oluşturduğu QR'ları değiştirebilir */
function needEdit(array $link): void {
    if (!Access::canEditLink($link)) apiError(403, 'forbidden', 'Bu QR\'ı değiştirme yetkiniz yok; editörler yalnızca kendi oluşturdukları QR\'ları düzenler.');
}

function loadLink(PDO $pdo, string $slug): array {
    $l = LinkService::findBySlug($pdo, preg_replace('/[^A-Za-z0-9_-]/', '', $slug));
    if (!$l || !Access::canSeeLink($l)) apiError(404, 'not_found', 'QR bulunamadı.');
    return $l;
}

try {
    // GET /ping
    if ($method === 'GET' && $parts === ['ping']) {
        apiOut(200, ['ok' => true, 'time' => date('c'), 'token' => $tok['name'], 'scopes' => explode(',', $tok['scopes'])]);
    }

    // GET /tags — kullanılan etiketler ve QR/okutma sayıları
    if ($method === 'GET' && $parts === ['tags']) {
        $out = [];
        foreach (LinkService::allTags($pdo) as $t) {
            $out[] = ['tag' => $t['tag'], 'qr_count' => $t['active'], 'qr_count_including_archived' => $t['count'], 'scan_count' => $t['scans']];
        }
        apiOut(200, ['data' => $out]);
    }

    if (($parts[0] ?? '') !== 'links') apiError(404, 'not_found', 'Bilinmeyen adres.');

    // ── /links ──
    if (count($parts) === 1) {
        if ($method === 'GET') {
            [$where, $params] = LinkService::buildFilter([
                'q' => $_GET['q'] ?? '', 'status' => $_GET['status'] ?? '', 'category' => $_GET['category'] ?? '', 'tag' => $_GET['tag'] ?? '',
            ]);
            $page = max(1, (int)($_GET['page'] ?? 1));
            $per  = max(1, min(100, (int)($_GET['per_page'] ?? 25)));
            $c = $pdo->prepare("SELECT COUNT(*) FROM links {$where}");
            $c->execute($params);
            $total = (int)$c->fetchColumn();
            $st = $pdo->prepare("SELECT * FROM links {$where} ORDER BY id DESC LIMIT ? OFFSET ?");
            $i = 1;
            foreach ($params as $p) $st->bindValue($i++, $p);
            $st->bindValue($i++, $per, PDO::PARAM_INT);
            $st->bindValue($i++, ($page - 1) * $per, PDO::PARAM_INT);
            $st->execute();
            apiOut(200, ['data' => array_map([LinkService::class, 'present'], $st->fetchAll()),
                         'meta' => ['page' => $page, 'per_page' => $per, 'total' => $total]]);
        }
        if ($method === 'POST') {
            needWrite($tok);
            [$clean, $errors] = LinkService::normalize($body);
            if ($errors) apiError(422, 'validation_failed', 'Girdi geçersiz.', ['details' => $errors]);
            try {
                $link = LinkService::create($pdo, $clean, isset($body['slug']) ? (string)$body['slug'] : null, $actor);
            } catch (RuntimeException $e) {
                apiError(409, 'conflict', $e->getMessage());
            }
            http_response_code(201);
            header('Location: ' . BASE_URL . '/api/v1/links/' . $link['slug']);
            apiOut(201, ['data' => LinkService::present($link)]);
        }
        apiError(405, 'method_not_allowed', 'Bu yöntem desteklenmiyor.');
    }

    // ── /links/{slug} ──
    $link = loadLink($pdo, $parts[1]);

    if (count($parts) === 2) {
        if ($method === 'GET') apiOut(200, ['data' => LinkService::present($link)]);
        if ($method === 'PATCH' || $method === 'PUT') {
            needWrite($tok);
            needEdit($link);
            unset($body['slug']); // kısa ad değiştirilemez: basılı QR bozulur
            [$clean, $errors] = LinkService::normalize($body, $link);
            if ($errors) apiError(422, 'validation_failed', 'Girdi geçersiz.', ['details' => $errors]);
            if (array_key_exists('is_active', $clean) && (int)$clean['is_active'] !== (int)$link['is_active'] && !Access::canToggleLink($link)) {
                apiError(403, 'approval_required', 'Aktif/pasif değişikliği yönetici onayı gerektirir; POST /links/{slug}/' . ($clean['is_active'] ? 'activate' : 'deactivate') . ' ile talep oluşturun.');
            }
            $changes = LinkService::update($pdo, $link, $clean, $actor);
            if (array_key_exists('is_active', $changes)) {
                LinkEvents::log($pdo, (int)$link['id'], $link['slug'], $actor, $clean['is_active'] ? 'activate' : 'deactivate');
            }
            apiOut(200, ['data' => LinkService::present(LinkService::find($pdo, (int)$link['id'])), 'changed' => array_keys($changes)]);
        }
        apiError(405, 'method_not_allowed', 'QR kayıtları API ile silinemez; arşivlemek için POST /links/{slug}/archive kullanın.');
    }

    // ── /links/{slug}/{action} ──
    $sub = $parts[2] ?? '';
    if (count($parts) === 3) {
        if ($method === 'GET' && $sub === 'qr') {
            $fmt = in_array($_GET['format'] ?? 'svg', Share::FORMATS, true) ? ($_GET['format'] ?? 'svg') : 'svg';
            $r = Share::render($link, $fmt, (int)($_GET['size'] ?? 0), !empty($_GET['transparent']));
            header('Content-Type: ' . $r['mime']);
            header('Content-Disposition: ' . (!empty($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . $r['name'] . '"');
            http_response_code(200);
            echo $r['data'];
            exit;
        }
        if ($method === 'GET' && $sub === 'stats') {
            $tz = Settings::timezone();
            $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
            $ok = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4));
            $to   = $ok($_GET['to'] ?? '')   ? $_GET['to']   : $today;
            $from = $ok($_GET['from'] ?? '') ? $_GET['from'] : (new DateTimeImmutable($to))->modify('-29 days')->format('Y-m-d');
            if ($from > $to) apiError(422, 'validation_failed', '"from" tarihi "to"dan sonra olamaz.');
            $S = Stats::summary($pdo, (int)$link['id'], $from, $to);
            apiOut(200, ['data' => [
                'slug' => $link['slug'], 'from' => $from, 'to' => $to,
                'scans' => $S['total'], 'unique_visitors_estimate' => $S['unique'],
                'bot_previews_not_counted' => $S['bots'], 'redirected_to_fallback' => $S['redirected'],
                'daily' => $S['daily'], 'devices' => $S['devices'], 'os' => $S['os'],
                'browsers' => $S['browsers'], 'countries' => $S['countries'], 'referrers' => $S['referrers'],
                'total_scans_all_time' => (int)$link['scan_count'],
            ]]);
        }
        if ($method === 'POST' && in_array($sub, ['activate', 'deactivate', 'archive', 'restore'], true)) {
            needWrite($tok);
            needEdit($link);
            if (in_array($sub, ['archive', 'restore'], true) && !Access::can('links.archive')) {
                apiError(403, 'forbidden', 'Arşivleme yalnızca yönetici yetkisiyle yapılabilir.');
            }
            if (in_array($sub, ['activate', 'deactivate'], true) && !Access::canToggleLink($link)) {
                // Editör: yönetici onayına talep (202 Accepted)
                if (((int)$link['is_active'] === 1) === ($sub === 'activate')) {
                    apiOut(200, ['data' => LinkService::present($link)]);
                }
                [$reqId, $err] = LinkRequests::create($pdo, $link, $actor, $sub, (string)($body['note'] ?? ''));
                if ($err) apiError(409, 'conflict', $err);
                apiOut(202, ['data' => LinkService::present($link),
                             'request' => ['id' => $reqId, 'kind' => $sub, 'status' => 'pending',
                                           'message' => 'Talep yönetici onayına gönderildi; QR onaylanana kadar olduğu gibi çalışır.']]);
            }
            switch ($sub) {
                case 'activate': case 'deactivate':
                    $new = $sub === 'activate' ? 1 : 0;
                    if ((int)$link['is_active'] !== $new) {
                        $pdo->prepare('UPDATE links SET is_active = ?, updated_at = NOW() WHERE id = ?')->execute([$new, $link['id']]);
                        LinkEvents::log($pdo, (int)$link['id'], $link['slug'], $actor, $sub);
                    }
                    break;
                case 'archive': LinkService::archive($pdo, $link, $actor); break;
                case 'restore': LinkService::restore($pdo, $link, $actor); break;
            }
            apiOut(200, ['data' => LinkService::present(LinkService::find($pdo, (int)$link['id']))]);
        }
    }
    apiError(404, 'not_found', 'Bilinmeyen adres.');

} catch (\Throwable $e) {
    Logger::error('API hatası: ' . $e->getMessage(), $e->getFile() . ':' . $e->getLine());
    apiError(500, 'server_error', 'Geçici bir hata oluştu.');
}
