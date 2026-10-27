<?php
/**
 * VCard — dijital kartvizit QR türü.
 *
 * Kartvizit QR'ı da kısa linki (/r/{slug}) kodlar; kişi bilgisi `vcards` tablosunda durur ve
 * panelden değiştirilebilir. Okutulunca kişi sayfası açılır, "Rehbere ekle" .vcf (vCard 3.0) indirir.
 * `links.target_url` kartvizitte web sitesi (yoksa genel site) olarak tutulur: kişi kaydı okunamazsa
 * QR yine bu adrese yönlenir, ölü QR oluşmaz.
 */
class VCard {

    /** Alan => [etiket, en fazla uzunluk] */
    public const FIELDS = [
        'first_name'   => ['Ad', 80],
        'last_name'    => ['Soyad', 80],
        'title'        => ['Ünvan', 120],
        'department'   => ['Birim', 120],
        'org'          => ['Kurum', 120],
        'phone_mobile' => ['Cep telefonu', 40],
        'phone_work'   => ['İş telefonu', 40],
        'email'        => ['E-posta', 190],
        'website'      => ['Web sitesi', 500],
        'address'      => ['Adres', 300],
        'linkedin'     => ['LinkedIn', 500],
        'note'         => ['Not (kartta görünür)', 500],
    ];

    public const DEFAULT_ORG     = 'Acıbadem';
    public const DEFAULT_WEBSITE = 'https://acibadem.world/';
    public const PHOTO_MAX_PX    = 480;
    public const PHOTO_MAX_BYTES = 5 * 1024 * 1024;

    private static ?bool $available = null;

    /** Şema hazır mı (012 çalışmış mı); değilse panel kartvizit seçeneğini göstermez */
    public static function available(PDO $pdo): bool {
        if (self::$available !== null) return self::$available;
        try {
            $pdo->query('SELECT qr_type FROM links LIMIT 0');
            $pdo->query('SELECT link_id FROM vcards LIMIT 0');
            return self::$available = true;
        } catch (\Throwable $e) {
            return self::$available = false;
        }
    }

    /** Form/POST'tan kişi alanları */
    public static function fromPost(array $post): array {
        $vc = is_array($post['vc'] ?? null) ? $post['vc'] : [];
        return array_intersect_key($vc, self::FIELDS);
    }

    public static function isVcard(array $link): bool {
        return ($link['qr_type'] ?? 'link') === 'vcard';
    }

    /** Kişi kaydı (fotoğraf verisi hariç; has_photo bayrağıyla) */
    public static function find(PDO $pdo, int $linkId): ?array {
        try {
            $cols = implode(', ', array_keys(self::FIELDS));
            $st = $pdo->prepare("SELECT link_id, {$cols}, save_count, photo_mime, (photo IS NOT NULL) AS has_photo, updated_at
                                 FROM vcards WHERE link_id = ?");
            $st->execute([$linkId]);
            return $st->fetch() ?: null;
        } catch (\Throwable $e) {
            return null; // tablo yoksa (migration bekliyor) kartvizit yok sayılır
        }
    }

    /** @return array{0:string,1:string}|null [veri, mime] */
    public static function photo(PDO $pdo, int $linkId): ?array {
        try {
            $st = $pdo->prepare('SELECT photo, photo_mime FROM vcards WHERE link_id = ? AND photo IS NOT NULL');
            $st->execute([$linkId]);
            $r = $st->fetch();
            return $r ? [$r['photo'], $r['photo_mime'] ?: 'image/jpeg'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Form/API girdisini temizler. @return array{0:array,1:string[]} */
    public static function normalize(array $in): array {
        $c = []; $errors = [];
        foreach (self::FIELDS as $k => [$label, $max]) {
            $v = trim(str_replace(["\r", "\n"], [' ', ' '], (string)($in[$k] ?? '')));
            if ($k === 'address' || $k === 'note') $v = trim((string)($in[$k] ?? ''));
            $c[$k] = mb_substr($v, 0, $max);
        }
        if ($c['org'] === '') $c['org'] = self::DEFAULT_ORG;
        if ($c['first_name'] === '' && $c['last_name'] === '') $errors[] = 'Kartvizit için ad veya soyad gerekli.';
        if ($c['email'] !== '' && !filter_var($c['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'E-posta adresi geçersiz.';
        foreach (['phone_mobile', 'phone_work'] as $k) {
            if ($c[$k] !== '' && !preg_match('/^\+?[0-9 ()\-.]{6,30}$/', $c[$k])) $errors[] = self::FIELDS[$k][0] . ' geçersiz (ör. +90 532 000 00 00).';
        }
        foreach (['website', 'linkedin'] as $k) {
            if ($c[$k] !== '' && !preg_match('#^https?://#i', $c[$k])) $c[$k] = 'https://' . $c[$k];
            if ($c[$k] !== '' && !isValidUrl($c[$k])) $errors[] = self::FIELDS[$k][0] . ' adresi geçersiz.';
        }
        return [$c, $errors];
    }

    /** QR'ın yönlendirme yedeği: kişinin web sitesi, yoksa genel site */
    public static function fallbackTarget(array $c): string {
        return ($c['website'] ?? '') !== '' ? $c['website'] : self::DEFAULT_WEBSITE;
    }

    public static function fullName(array $c): string {
        return trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
    }

    /** Kişi kaydını yazar (yoksa ekler). $photo: [veri, mime] | false (sil) | null (dokunma) */
    public static function save(PDO $pdo, int $linkId, array $c, $photo = null): void {
        $cols = array_keys(self::FIELDS);
        $vals = array_map(fn($k) => ($c[$k] ?? '') !== '' ? $c[$k] : null, $cols);
        $set  = implode(', ', array_map(fn($k) => "`$k` = VALUES(`$k`)", $cols));
        $sql  = 'INSERT INTO vcards (link_id, ' . implode(', ', array_map(fn($k) => "`$k`", $cols)) . ') VALUES ('
              . implode(',', array_fill(0, count($cols) + 1, '?')) . ") ON DUPLICATE KEY UPDATE {$set}, updated_at = NOW()";
        $pdo->prepare($sql)->execute(array_merge([$linkId], $vals));
        if ($photo === false) {
            $pdo->prepare('UPDATE vcards SET photo = NULL, photo_mime = NULL WHERE link_id = ?')->execute([$linkId]);
        } elseif (is_array($photo)) {
            $st = $pdo->prepare('UPDATE vcards SET photo = ?, photo_mime = ? WHERE link_id = ?');
            $st->bindValue(1, $photo[0], PDO::PARAM_LOB);
            $st->bindValue(2, $photo[1]);
            $st->bindValue(3, $linkId, PDO::PARAM_INT);
            $st->execute();
        }
    }

    /** Değişen alanlar (geçmiş kaydı için): alan => [eski, yeni] */
    public static function diff(?array $old, array $new): array {
        $out = [];
        foreach (self::FIELDS as $k => [$label]) {
            $a = (string)($old[$k] ?? ''); $b = (string)($new[$k] ?? '');
            if ($a !== $b) $out[$label] = [$a !== '' ? $a : null, $b !== '' ? $b : null];
        }
        return $out;
    }

    public static function countSave(PDO $pdo, int $linkId): void {
        try {
            $pdo->prepare('UPDATE vcards SET save_count = save_count + 1 WHERE link_id = ?')->execute([$linkId]);
        } catch (\Throwable $e) {
            // sayaç hatası indirmeyi engellemez
        }
    }

    /**
     * Yüklenen fotoğrafı kare kırpıp küçültür, JPEG'e çevirir.
     * @return array{0:?array,1:?string} [[veri, mime], hata]
     */
    public static function photoFromUpload(?array $file): array {
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [null, null];
        if ($file['error'] !== UPLOAD_ERR_OK) return [null, 'Fotoğraf yüklenemedi.'];
        if ($file['size'] > self::PHOTO_MAX_BYTES) return [null, 'Fotoğraf en fazla 5 MB olabilir.'];
        if (!function_exists('imagecreatefromstring')) return [null, 'Sunucuda resim işleme (GD) yok; fotoğraf eklenemez.'];
        $info = @getimagesize($file['tmp_name']);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return [null, 'Fotoğraf JPG, PNG veya WebP olmalı.'];
        }
        $src = @imagecreatefromstring((string)file_get_contents($file['tmp_name']));
        if (!$src) return [null, 'Fotoğraf okunamadı.'];
        $w = imagesx($src); $h = imagesy($src); $side = min($w, $h);
        $size = min(self::PHOTO_MAX_PX, $side);
        $dst = imagecreatetruecolor($size, $size);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, (int)(($w - $side) / 2), (int)(($h - $side) / 2), $size, $size, $side, $side);
        ob_start();
        imagejpeg($dst, null, 85);
        $data = (string)ob_get_clean();
        imagedestroy($src); imagedestroy($dst);
        return [[$data, 'image/jpeg'], null];
    }

    // ─── .vcf (vCard 3.0) ────────────────────────────────────────────────────

    private static function esc(string $v): string {
        return str_replace(['\\', "\r\n", "\n", "\r", ',', ';'], ['\\\\', '\\n', '\\n', '\\n', '\\,', '\\;'], $v);
    }

    /** RFC 6350/2425: satırlar 75 baytta katlanır; çok baytlı karakter bölünmez */
    private static function fold(string $line): string {
        if (strlen($line) <= 75) return $line;
        $out = ''; $cur = ''; $limit = 75;
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            if (strlen($cur) + strlen($ch) > $limit) { $out .= $cur . "\r\n "; $cur = ''; $limit = 74; }
            $cur .= $ch;
        }
        return $out . $cur;
    }

    /** @param ?array $photo [veri, mime] */
    public static function toVcf(array $c, ?string $pageUrl = null, ?array $photo = null): string {
        $L = ['BEGIN:VCARD', 'VERSION:3.0'];
        $L[] = 'N:' . self::esc($c['last_name'] ?? '') . ';' . self::esc($c['first_name'] ?? '') . ';;;';
        $L[] = 'FN:' . self::esc(self::fullName($c) ?: ($c['org'] ?? ''));
        if (($c['org'] ?? '') !== '') {
            $L[] = 'ORG:' . self::esc($c['org']) . (($c['department'] ?? '') !== '' ? ';' . self::esc($c['department']) : '');
        }
        if (($c['title'] ?? '') !== '')        $L[] = 'TITLE:' . self::esc($c['title']);
        if (($c['phone_mobile'] ?? '') !== '') $L[] = 'TEL;TYPE=CELL,VOICE:' . self::esc($c['phone_mobile']);
        if (($c['phone_work'] ?? '') !== '')   $L[] = 'TEL;TYPE=WORK,VOICE:' . self::esc($c['phone_work']);
        if (($c['email'] ?? '') !== '')        $L[] = 'EMAIL;TYPE=INTERNET,WORK:' . self::esc($c['email']);
        if (($c['website'] ?? '') !== '')      $L[] = 'URL;TYPE=WORK:' . self::esc($c['website']);
        if (($c['linkedin'] ?? '') !== '')     $L[] = 'X-SOCIALPROFILE;TYPE=linkedin:' . self::esc($c['linkedin']);
        if (($c['address'] ?? '') !== '')      $L[] = 'ADR;TYPE=WORK:;;' . self::esc($c['address']) . ';;;;';
        $note = trim(($c['note'] ?? '') . ($pageUrl ? "\n" . $pageUrl : ''));
        if ($note !== '')                      $L[] = 'NOTE:' . self::esc($note);
        if ($photo) $L[] = 'PHOTO;ENCODING=b;TYPE=' . ($photo[1] === 'image/png' ? 'PNG' : 'JPEG') . ':' . base64_encode($photo[0]);
        $L[] = 'REV:' . gmdate('Ymd\THis\Z');
        $L[] = 'END:VCARD';
        return implode("\r\n", array_map([self::class, 'fold'], $L)) . "\r\n";
    }

    /** İndirme dosya adı: ad-soyad.vcf (ASCII) */
    public static function fileName(array $c): string {
        $n = strtolower(strtr(self::fullName($c), ['ç' => 'c', 'Ç' => 'c', 'ğ' => 'g', 'Ğ' => 'g', 'ı' => 'i', 'İ' => 'i',
                                                   'ö' => 'o', 'Ö' => 'o', 'ş' => 's', 'Ş' => 's', 'ü' => 'u', 'Ü' => 'u']));
        $n = trim(preg_replace('/[^a-z0-9]+/', '-', $n), '-');
        return ($n !== '' ? $n : 'kartvizit') . '.vcf';
    }
}
