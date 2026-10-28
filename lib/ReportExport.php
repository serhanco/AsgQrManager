<?php
/**
 * ReportExport — rapor sayfasının Excel (çok sayfalı) ve yazdırılabilir (PDF) çıktısı için ortak veri.
 *
 * $R (report.php hazırlar): scope, from, to, prevFrom, prevTo, total, unique, prevTotal, prevUnique, bots, redirected,
 * daily, prevDaily, devices, os, browsers, countries, referrers, heat, links, categories, tags, owners, changes, by, at
 */
class ReportExport {

    public const DAYS = ['Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'];

    /** Dosya adı için güvenli parça */
    public static function fileName(array $R, string $ext): string {
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', $R['fileKey'] ?? 'rapor');
        return 'qr-rapor-' . trim($slug, '-') . '-' . $R['from'] . '_' . $R['to'] . '.' . $ext;
    }

    /** Önceki döneme göre değişim metni (ör. "+%27", "-%5"); karşılaştırma yoksa boş */
    public static function changeText(int $now, ?int $prev): string {
        if ($prev === null) return '';
        $c = Stats::change($now, $prev);
        return $c === null ? '' : ($c > 0 ? '+%' . $c : ($c < 0 ? '-%' . abs($c) : '%0'));
    }

    /** En yoğun gün ve saat dilimi: [gün adı, saat, okutma] ya da null */
    public static function peak(array $heat): ?array {
        $bw = $bh = 0;
        foreach ($heat as $w => $row) foreach ($row as $h => $c) if ($c > $heat[$bw][$bh]) { $bw = $w; $bh = $h; }
        return $heat[$bw][$bh] > 0 ? [self::DAYS[$bw], $bh, $heat[$bw][$bh]] : null;
    }

    /** @return array<int,array{name:string,rows:array,widths:array}> */
    public static function sheets(array $R): array {
        $hasPrev = $R['prevTotal'] !== null;
        $pct = fn(int $v) => $R['total'] > 0 ? round($v / $R['total'] * 100, 1) : 0;

        $summary = [
            ['Acıbadem QR Code Manager — Okutma raporu', ''],
            ['Kapsam', $R['scope']],
            ['Tarih aralığı', $R['from'] . ' – ' . $R['to']],
            ['Okutma', $R['total']],
            ['Tahmini tekil ziyaretçi', $R['unique']],
        ];
        if ($hasPrev) {
            array_push($summary,
                ['Önceki dönem', $R['prevFrom'] . ' – ' . $R['prevTo']],
                ['Önceki dönem okutma', $R['prevTotal']],
                ['Değişim (okutma)', self::changeText($R['total'], $R['prevTotal']) ?: '—'],
                ['Önceki dönem tekil', $R['prevUnique']],
                ['Değişim (tekil)', self::changeText($R['unique'], $R['prevUnique']) ?: '—']);
        }
        array_push($summary,
            ['Yedek hedefe giden', $R['redirected']],
            ['Bot / önizleme (sayılmadı)', $R['bots']]);
        if ($p = self::peak($R['heat'])) $summary[] = ['En yoğun zaman', sprintf('%s %02d:00-%02d:00 (%d okutma)', $p[0], $p[1], ($p[1] + 1) % 24, $p[2])];
        array_push($summary,
            ['Hazırlayan', $R['by']],
            ['Oluşturulma', $R['at']],
            ['Not', 'Bot önizlemeleri ve pasif/yedek yönlendirmeler okutma sayılmaz. Saatler sunucu saatine göredir.']);

        $sheets = [['name' => 'Özet', 'rows' => $summary, 'widths' => [30, 60]]];

        $daily = [$hasPrev ? ['Tarih', 'Okutma', 'Önceki dönem (aynı gün sırası)'] : ['Tarih', 'Okutma']];
        $i = 0;
        foreach ($R['daily'] as $d => $c) {
            $daily[] = $hasPrev ? [$d, $c, $R['prevDaily'][$i] ?? 0] : [$d, $c];
            $i++;
        }
        $sheets[] = ['name' => 'Günlük', 'rows' => $daily, 'widths' => [14, 10, 28]];

        if ($R['links']) {
            $rows = [['Kısa ad', 'Başlık', 'Kategori', 'Etiketler', 'Okutma', 'Tahmini tekil', '% pay', 'Kısa adres']];
            foreach ($R['links'] as $l) {
                $rows[] = [$l['slug'], (string)$l['title'], $l['category'] ? (LinkService::CATEGORIES[$l['category']] ?? $l['category']) : '',
                           (string)$l['tags'], (int)$l['c'], (int)$l['u'], $pct((int)$l['c']), BASE_URL . '/r/' . $l['slug']];
            }
            $sheets[] = ['name' => "QR'lar", 'rows' => $rows, 'widths' => [14, 34, 14, 26, 10, 12, 8, 36]];
        }

        $group = function (string $name, string $label, array $data) use ($pct): array {
            $rows = [[$label, 'Okutma', '% pay']];
            foreach ($data as $k => $v) $rows[] = [(string)$k, (int)$v, $pct((int)$v)];
            return ['name' => $name, 'rows' => $rows, 'widths' => [32, 10, 8]];
        };
        if ($R['categories']) $sheets[] = $group('Kategoriler', 'Kategori', $R['categories']);
        if ($R['tags'])       $sheets[] = $group('Etiketler', 'Etiket', $R['tags']);
        if ($R['owners']) {
            $rows = [['Kullanıcı', 'QR sayısı', 'Okutma', '% pay']];
            foreach ($R['owners'] as $o) $rows[] = [$o['owner'], $o['links'], $o['total'], $pct($o['total'])];
            $sheets[] = ['name' => 'Sahipler', 'rows' => $rows, 'widths' => [28, 10, 10, 8]];
        }
        $countries = [];
        foreach ($R['countries'] as $k => $v) $countries[$k === '??' ? 'Bilinmiyor' : $k] = $v;
        $sheets[] = $group('Ülkeler', 'Ülke', $countries);

        $dev = [['Tür', 'Değer', 'Okutma', '% pay']];
        foreach (['Cihaz' => 'devices', 'İşletim sistemi' => 'os', 'Tarayıcı' => 'browsers', 'Kaynak' => 'referrers'] as $label => $k) {
            foreach ($R[$k] as $name => $v) $dev[] = [$label, (string)$name, (int)$v, $pct((int)$v)];
        }
        $sheets[] = ['name' => 'Cihaz ve kaynak', 'rows' => $dev, 'widths' => [16, 30, 10, 8]];

        $heat = [array_merge(['Gün'], array_map(fn($h) => sprintf('%02d', $h), range(0, 23)), ['Toplam'])];
        foreach ($R['heat'] as $w => $row) $heat[] = array_merge([self::DAYS[$w]], $row, [array_sum($row)]);
        $sheets[] = ['name' => 'Gün x saat', 'rows' => $heat, 'widths' => array_merge([12], array_fill(0, 24, 5), [8])];

        if ($R['changes']) {
            $rows = [['Zaman', 'Kullanıcı', 'Eski hedef', 'Yeni hedef']];
            foreach ($R['changes'] as $c) $rows[] = [$c['at'], (string)$c['user'], (string)$c['from'], (string)$c['to']];
            $sheets[] = ['name' => 'Hedef değişiklikleri', 'rows' => $rows, 'widths' => [20, 16, 44, 44]];
        }
        return $sheets;
    }

    /**
     * Günlük seri için yazdırılabilir SVG çizgi grafik (sunucuda üretilir; JavaScript gerekmez).
     * @param int[]|null $prev  @param int[] $markers gün sırası
     */
    public static function chartSvg(array $daily, ?array $prev = null, array $markers = [], int $w = 720, int $h = 200): string {
        $vals = array_values($daily);
        $labels = array_keys($daily);
        $n = count($vals);
        if (!$n) return '';
        $padL = 36; $padR = 8; $padT = 10; $padB = 24;
        $cw = $w - $padL - $padR; $ch = $h - $padT - $padB;
        $max = max(1, max($vals), $prev ? max($prev) : 0);
        $x = fn($i) => round($padL + ($n > 1 ? $i / ($n - 1) : 0.5) * $cw, 1);
        $y = fn($v) => round($padT + $ch - $v / $max * $ch, 1);
        $s = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" role="img" aria-label="Günlük okutma grafiği" font-family="Plus Jakarta Sans, Arial, sans-serif" font-size="10">';
        for ($g = 0; $g <= 4; $g++) {
            $gy = round($padT + $ch / 4 * $g, 1);
            $s .= '<line x1="' . $padL . '" y1="' . $gy . '" x2="' . ($w - $padR) . '" y2="' . $gy . '" stroke="#E2E7F1"/>'
                . '<text x="' . ($padL - 4) . '" y="' . ($gy + 3) . '" text-anchor="end" fill="#5D6886">' . (int)round($max * (1 - $g / 4)) . '</text>';
        }
        foreach ($markers as $i) {
            $s .= '<line x1="' . $x($i) . '" y1="' . $padT . '" x2="' . $x($i) . '" y2="' . ($padT + $ch) . '" stroke="#B45309" stroke-dasharray="3 3" stroke-width="1.2"/>';
        }
        $path = fn(array $v) => implode(' ', array_map(fn($i) => ($i ? 'L' : 'M') . $x($i) . ',' . $y($v[$i]), array_keys($v)));
        if ($prev && count($prev) === $n) $s .= '<path d="' . $path(array_values($prev)) . '" fill="none" stroke="#5D6886" stroke-width="1.3" stroke-dasharray="5 4"/>';
        $s .= '<path d="' . $path($vals) . ' L' . $x($n - 1) . ',' . ($padT + $ch) . ' L' . $x(0) . ',' . ($padT + $ch) . ' Z" fill="#1F4BA8" fill-opacity=".12"/>'
            . '<path d="' . $path($vals) . '" fill="none" stroke="#1F4BA8" stroke-width="2" stroke-linejoin="round"/>';
        $step = max(1, (int)ceil($n / 7));
        for ($i = 0; $i < $n; $i += $step) {
            $s .= '<text x="' . $x($i) . '" y="' . ($h - 8) . '" text-anchor="middle" fill="#5D6886">' . e(substr($labels[$i], 5)) . '</text>';
        }
        return $s . '</svg>';
    }
}
