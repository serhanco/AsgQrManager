<?php
/**
 * Settings — veritabanında saklanan panel ayarları (settings tablosu)
 *
 * Tablo henüz yoksa (migration çalışmadıysa) tüm okumalar varsayılana düşer,
 * yönlendirme (r.php) bu durumda da çalışmaya devam eder.
 */
class Settings {

    /** Varsayılan değerler. Sabit (config.php) karşılığı olanlar çalışma anında çözülür. */
    private const DEFAULTS = [
        'timezone'           => 'Europe/Istanbul', // zamanlı hedef saatleri bu saat dilimine göre yorumlanır
        'fallback_url'       => '',                // pasif / süresi dolmuş QR'ların genel yedek hedefi
        'info_page_url'      => 'https://acibadem.world/', // yedek hedef yoksa gösterilen bilgi sayfasındaki buton
        'info_page_redirect_seconds' => '0',      // 0 = otomatik geçiş yok
        'info_page_message_tr' => '',             // bilgi sayfasına isteğe bağlı ek not
        'info_page_message_en' => '',
        'brand_fg'           => '#000000',
        'brand_bg'           => '#FFFFFF',
        'brand_shape'        => 'square',
        'brand_logo'         => '',
        'utm_source'         => 'qr',
        'utm_medium'         => 'print',
        'ip_mode'            => 'full',            // full | mask | none
        'ip_anonymize_days'  => '0',               // 0 = kapalı
        'share_ttl_days'     => '14',
    ];

    private static ?array $cache = null;

    private static function load(): array {
        if (self::$cache !== null) return self::$cache;
        self::$cache = [];
        try {
            $rows = getPdo()->query('SELECT skey, svalue FROM settings')->fetchAll();
            foreach ($rows as $r) self::$cache[$r['skey']] = (string)$r['svalue'];
        } catch (\Throwable $e) {
            // tablo yok: varsayılanlarla devam
        }
        return self::$cache;
    }

    public static function get(string $key, ?string $default = null): string {
        $all = self::load();
        if (array_key_exists($key, $all)) return $all[$key];
        if ($default !== null) return $default;
        return self::DEFAULTS[$key] ?? '';
    }

    public static function set(string $key, string $value): void {
        $stmt = getPdo()->prepare(
            'INSERT INTO settings (skey, svalue) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)'
        );
        $stmt->execute([$key, $value]);
        self::$cache = null;
    }

    public static function forget(string $key): void {
        getPdo()->prepare('DELETE FROM settings WHERE skey = ?')->execute([$key]);
        self::$cache = null;
    }

    public static function has(string $key): bool {
        return array_key_exists($key, self::load());
    }

    public static function analyticsEnabled(): bool {
        if (self::has('analytics_enabled')) return self::get('analytics_enabled') === '1';
        return defined('ANALYTICS_ENABLED') && ANALYTICS_ENABLED;
    }

    public static function timezone(): DateTimeZone {
        try {
            return new DateTimeZone(self::get('timezone'));
        } catch (\Throwable $e) {
            return new DateTimeZone('Europe/Istanbul');
        }
    }

    /** İmzalı paylaşım bağlantıları için gizli anahtar (ilk kullanımda üretilir). */
    public static function shareSecret(): string {
        $s = self::get('share_secret', '');
        if ($s === '') {
            $s = bin2hex(random_bytes(32));
            self::set('share_secret', $s);
        }
        return $s;
    }
}
