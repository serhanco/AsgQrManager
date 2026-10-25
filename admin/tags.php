<?php
/**
 * tags.php — Etiket yönetimi: kullanım sayıları, yeniden adlandırma / birleştirme, kaldırma.
 * QR'ların kendisine (kısa ad, hedef, okutma geçmişi) dokunulmaz; yalnızca etiket metni değişir.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

Auth::startSession();
Auth::requireLogin();
Auth::requirePerm('admin');

$pdo = getPdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();
    $action = $_POST['action'] ?? '';
    $from   = trim((string)($_POST['from'] ?? ''));
    $to     = trim((string)($_POST['to'] ?? ''));
    if ($from === '') {
        flash('danger', 'Etiket belirtilmedi.');
    } elseif ($action === 'rename') {
        if ($to === '') {
            flash('danger', 'Yeni etiket adını yazın. Etiketi kaldırmak için “Kaldır” düğmesini kullanın.');
        } elseif (LinkService::tagKey($to) === LinkService::tagKey($from) && $to === $from) {
            flash('info', 'Etiket adı değişmedi.');
        } else {
            $merge = isset(LinkService::allTags($pdo)[LinkService::tagKey($to)]) && LinkService::tagKey($to) !== LinkService::tagKey($from);
            $n = LinkService::renameTag($pdo, $from, $to, Auth::actor());
            flash('success', $merge
                ? "“{$from}” etiketi “{$to}” ile birleştirildi ({$n} QR güncellendi)."
                : "“{$from}” etiketi “{$to}” olarak değiştirildi ({$n} QR güncellendi).");
        }
    } elseif ($action === 'remove') {
        $n = LinkService::renameTag($pdo, $from, '', Auth::actor());
        flash('success', "“{$from}” etiketi {$n} QR’dan kaldırıldı. QR’lar ve okutma geçmişi etkilenmedi.");
    }
    redirect(BASE_URL . '/admin/tags');
}

$tags     = LinkService::allTags($pdo);
$untagged = (int)$pdo->query("SELECT COUNT(*) FROM links WHERE archived_at IS NULL AND (tags IS NULL OR tags = '')")->fetchColumn();
$totalLive = (int)$pdo->query('SELECT COUNT(*) FROM links WHERE archived_at IS NULL')->fetchColumn();

$pageTitle  = 'Etiketler';
$activePage = 'tags';
include __DIR__ . '/views/_layout.php';
?>

<div class="page-header">
  <h1 class="page-title"><?= icon('tag') ?> Etiketler</h1>
  <div class="btn-group">
    <?php if ($untagged): ?>
      <a href="<?= BASE_URL ?>/admin/links?tag=__none" class="btn btn-ghost btn-sm" title="Hiç etiketi olmayan QR'ları listele">Etiketsiz QR (<?= $untagged ?>)</a>
    <?php endif; ?>
    <a href="<?= BASE_URL ?>/admin/links" class="btn btn-ghost btn-sm"><?= icon('arrow-left') ?> Linkler</a>
  </div>
</div>

<div class="nudge">
  <div class="nudge-icon"><?= icon('bulb') ?></div>
  <div>
    <div class="nudge-title">Tür mü, etiket mi?</div>
    <p><strong>Tür</strong> QR’ın ne olduğunu söyler (tek seçim: Genel Merkez, Ofis, Etkinlik, Kampanya).
       <strong>Etiket</strong> kesişen gruplar içindir: ülke, etkinlik adı, materyal, dönem.
       Aynı etikete sahip QR’ları <a href="<?= BASE_URL ?>/admin/report">Raporlar</a> sayfasında tek satırda görürsünüz.</p>
    <p>Aynı anlama gelen iki etiketi (örn. <em>poster</em> ve <em>afiş</em>) aşağıdan birleştirebilirsiniz; QR’lar ve okutma geçmişi korunur.</p>
  </div>
</div>

<div class="card" style="padding:0">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Etiket</th>
          <th>QR <?= tip('Arşivde olmayan QR sayısı. Parantez içinde arşivdekiler dahil toplam.') ?></th>
          <th>Okutma <?= tip('Bu etiketli QR’ların toplam okutma sayısı (QR kayıtlarındaki sayaç).') ?></th>
          <th>Yeniden adlandır / birleştir <?= tip('Yeni ad mevcut bir etiketin adıysa iki etiket birleşir. Örn. “afiş” → “poster” yazarsanız her iki etiketli QR’lar “poster” olur.') ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$tags): ?>
        <tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--text-muted)">
          Henüz etiket yok. Bir QR oluştururken veya düzenlerken “Etiketler” alanına yazarak başlayın; ya da
          <a href="<?= BASE_URL ?>/admin/links">Linkler</a> sayfasında birkaç QR’ı seçip toplu etiket ekleyin.
        </td></tr>
      <?php else: foreach ($tags as $t): ?>
        <tr>
          <td><a class="tag-chip" style="font-size:.85rem" href="<?= BASE_URL ?>/admin/links?tag=<?= urlencode($t['tag']) ?>">#<?= e($t['tag']) ?></a></td>
          <td class="fw-bold"><?= (int)$t['active'] ?> <?php if ($t['count'] !== $t['active']): ?><span class="text-muted text-xs">(<?= (int)$t['count'] ?>)</span><?php endif; ?></td>
          <td><?= formatCount((int)$t['scans']) ?></td>
          <td>
            <form method="POST" class="btn-group" style="flex-wrap:nowrap">
              <?= Auth::csrfField() ?>
              <input type="hidden" name="action" value="rename">
              <input type="hidden" name="from" value="<?= e($t['tag']) ?>">
              <input class="form-control" type="text" name="to" maxlength="30" placeholder="Yeni ad" aria-label="<?= e($t['tag']) ?> için yeni ad" style="max-width:170px">
              <button class="btn btn-ghost btn-sm" type="submit">Uygula</button>
            </form>
          </td>
          <td>
            <div class="btn-group" style="flex-wrap:nowrap">
              <a class="btn btn-ghost btn-sm" href="<?= BASE_URL ?>/admin/report?tag=<?= urlencode($t['tag']) ?>" title="Bu etiketli QR'ların birleşik raporu"><?= icon('chart') ?></a>
              <form method="POST" data-confirm="“<?= e($t['tag']) ?>” etiketi <?= (int)$t['count'] ?> QR’dan kaldırılacak. QR’lar silinmez, okutma geçmişi korunur. Devam edilsin mi?">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="from" value="<?= e($t['tag']) ?>">
                <button class="btn btn-ghost btn-sm" type="submit" title="Etiketi tüm QR'lardan kaldır"><?= icon('trash') ?></button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<p class="text-sm text-muted mt-2"><?= count($tags) ?> etiket · <?= $totalLive - $untagged ?>/<?= $totalLive ?> QR etiketli</p>

<?php include __DIR__ . '/views/_layout_end.php'; ?>
