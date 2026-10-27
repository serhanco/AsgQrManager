<?php
/**
 * qr-preview.php — Form için canlı QR önizlemesi (SVG). Kaydetmeden önizler, cache'e yazmaz.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

Auth::startSession();
if (!Auth::isLoggedIn()) { http_response_code(401); exit; }

$mode = (string)($_GET['mode'] ?? 'brand');
$logo = (string)($_GET['logo'] ?? '');
if ($logo !== '' && !validateLogoFile($logo)) $logo = '';

$row = ['fg_color' => null, 'bg_color' => null, 'shape' => null, 'design' => null, 'template_id' => null];
if ($mode === 'template') {
    $row['template_id'] = (int)($_GET['tpl'] ?? 0);
} elseif ($mode === 'custom') {
    $fg = LinkService::normalizeHex($_GET['fg'] ?? '');
    $bg = LinkService::normalizeHex($_GET['bg'] ?? '');
    $shape = (string)($_GET['shape'] ?? '');
    if ($fg && $bg) { $row['fg_color'] = $fg; $row['bg_color'] = $bg; }
    if (isset(LinkService::SHAPES[$shape])) $row['shape'] = $shape;
    $row['design'] = QrDesign::encode(QrTemplates::designInput($_GET));
    // Okunamaz tasarım önizlemede marka varsayılanına döner (kayıtta hata verilir)
    if (QrDesign::check(LinkService::effectiveStyle($row))[0]) $row = ['fg_color' => null, 'bg_color' => null, 'shape' => null];
}
$style = LinkService::effectiveStyle($row);

$slug = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_GET['slug'] ?? '')) ?: 'onizleme';
try {
    $matrix = QrRenderer::buildMatrix(BASE_URL . '/r/' . $slug, $logo !== '' ? 'H' : 'M');
    header('Content-Type: image/svg+xml');
    header('Cache-Control: no-store');
    echo QrRenderer::toSvg($matrix, 4, $logo !== '' ? LOGO_DIR . '/' . basename($logo) : null, $style);
} catch (\Throwable $e) {
    http_response_code(500);
}
