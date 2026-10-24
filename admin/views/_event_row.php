<?php
/** Tek bir link_events satırını tablo satırı olarak yazar. Beklenen: $ev */
?>
<tr>
  <td class="text-sm text-muted" style="white-space:nowrap"><?= e(substr($ev['created_at'], 0, 16)) ?></td>
  <td class="text-sm"><?= e($ev['username'] ?: '—') ?></td>
  <td class="text-sm fw-bold"><?= e(LinkEvents::actionLabel($ev['action'])) ?></td>
  <td class="text-xs"><?= LinkEvents::detailsHtml($ev) ?></td>
</tr>
