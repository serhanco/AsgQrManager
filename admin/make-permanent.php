<?php
/**
 * make-permanent.php — Seçilen süreli QR'ların bitiş tarihini kaldırır (POST, tekli: id, toplu: ids[]).
 * QR kalıcı olarak hedef adrese gider; kısa ad, hedef ve okutma geçmişi değişmez.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

Auth::startSession();
Auth::requireLogin();
Auth::requirePerm('links.edit');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/admin/links');
Auth::requireCsrf();

// Geldiği sayfaya dön (yalnızca site içi bilinen sayfalar)
$back = (string)($_POST['back'] ?? '');
$okBack = !str_contains($back, "\n") && (in_array($back, [BASE_PATH . '/admin', BASE_PATH . '/admin/'], true) || array_filter(['/admin/links', '/admin/edit', '/admin/dashboard', '/admin/qr-view'],
    fn($p) => str_starts_with($back, BASE_PATH . $p)));
$target = $okBack ? BASE_URL . substr($back, strlen(BASE_PATH)) : BASE_URL . '/admin/links';

$ids = (array)($_POST['ids'] ?? []);
if (isset($_POST['id'])) $ids[] = $_POST['id'];
$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

if (!$ids) {
    flash('warning', 'Önce listeden en az bir QR seçin.');
} elseif (count($ids) > 500) {
    flash('danger', 'Tek seferde en fazla 500 QR işlenebilir.');
} else {
    $pdo = getPdo();
    $allowed = []; $timed = 0;
    foreach ($ids as $id) {
        $l = LinkService::find($pdo, $id);
        if (!$l || !Access::canEditLink($l)) continue;
        $allowed[] = $id;
        if (!empty($l['ends_at'])) $timed++;
    }
    $n = $allowed ? LinkService::makePermanent($pdo, $allowed, Auth::actor()) : 0;
    $skipped = count($ids) - count($allowed);
    if ($n === 0) {
        flash('warning', $allowed ? 'Seçilen QR\'larda bitiş tarihi yok; zaten kalıcılar.' : 'Seçilen QR\'ları düzenleme yetkiniz yok.');
    } else {
        $msg = "{$n} QR kalıcı yapıldı: bitiş tarihi kaldırıldı, okutulunca hedef adrese gitmeye devam eder.";
        if (count($allowed) > $timed) $msg .= ' ' . (count($allowed) - $timed) . ' QR zaten süresizdi.';
        if ($skipped) $msg .= " {$skipped} QR yetkiniz olmadığı için atlandı.";
        flash('success', $msg);
    }
}
redirect($target);
