<?php
/**
 * Migrator — veritabanı şemasını CANLI VERİYE DOKUNMADAN günceller.
 *
 * Kurallar (canlı sistemde basılı QR'lar kullanımda):
 *  - Yalnızca EKLEME yapılır: yeni tablo, yeni sütun, yeni indeks.
 *  - DROP / TRUNCATE / DELETE / mevcut sütunu değiştirme YOKTUR.
 *  - Her adım idempotenttir (varsa atlar), yarıda kesilirse tekrar çalıştırılabilir.
 *  - Uygulamadan önce `links` tablosunun yedeği logs/backups/ altına yazılır
 *    ve sonrasında kayıt sayısı doğrulanır.
 *  - Yönlendirme (r.php) migration çalıştırmaz; sütun yoksa eski davranışla çalışır.
 */
class Migrator {

    private const LOCK_NAME = 'qrm_migrate';

    /** @return array<string,array{0:string,1:callable}> id => [açıklama, uygulayıcı] */
    private static function definitions(): array {
        return [
            '001_links_extend' => ['Link alanları: arşiv, kategori, etiket, yedek hedef, zamanlama, UTM, marka stili', function (PDO $p) {
                self::addColumn($p, 'links', 'archived_at',   'DATETIME NULL DEFAULT NULL');
                self::addColumn($p, 'links', 'category',      'VARCHAR(20) NULL DEFAULT NULL');
                self::addColumn($p, 'links', 'tags',          'VARCHAR(255) NULL DEFAULT NULL');
                self::addColumn($p, 'links', 'notes',         'TEXT NULL');
                self::addColumn($p, 'links', 'fallback_url',  'TEXT NULL');
                self::addColumn($p, 'links', 'starts_at',     'DATETIME NULL DEFAULT NULL');
                self::addColumn($p, 'links', 'ends_at',       'DATETIME NULL DEFAULT NULL');
                self::addColumn($p, 'links', 'after_end_url', 'TEXT NULL');
                self::addColumn($p, 'links', 'utm_enabled',   'TINYINT(1) NOT NULL DEFAULT 0');
                self::addColumn($p, 'links', 'utm_campaign',  'VARCHAR(100) NULL DEFAULT NULL');
                self::addColumn($p, 'links', 'fg_color',      'VARCHAR(7) NULL DEFAULT NULL');
                self::addColumn($p, 'links', 'bg_color',      'VARCHAR(7) NULL DEFAULT NULL');
                self::addColumn($p, 'links', 'shape',         'VARCHAR(10) NULL DEFAULT NULL');
                self::addIndex($p, 'links', 'idx_links_archived', '(`archived_at`)');
                self::addIndex($p, 'links', 'idx_links_category', '(`category`)');
            }],
            '002_scans_extend' => ['Okutma alanları: ülke, bot işareti, sonuç', function (PDO $p) {
                self::addColumn($p, 'scans', 'country', 'CHAR(2) NULL DEFAULT NULL');
                self::addColumn($p, 'scans', 'is_bot',  'TINYINT(1) NOT NULL DEFAULT 0');
                self::addColumn($p, 'scans', 'outcome', 'VARCHAR(12) NULL DEFAULT NULL');
            }],
            '003_link_events' => ['Değişiklik geçmişi tablosu', function (PDO $p) {
                // link_id için FOREIGN KEY yoktur: link silinse bile geçmiş kalır
                $p->exec("CREATE TABLE IF NOT EXISTS `link_events` (
                    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `link_id`    INT UNSIGNED    NULL,
                    `slug`       VARCHAR(16)     NULL,
                    `user_id`    INT UNSIGNED    NULL,
                    `username`   VARCHAR(64)     NULL,
                    `action`     VARCHAR(32)     NOT NULL,
                    `details`    TEXT            NULL,
                    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_events_link` (`link_id`),
                    KEY `idx_events_created` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }],
            '004_login_attempts' => ['Giriş denemeleri (IP bazlı kaba kuvvet koruması)', function (PDO $p) {
                $p->exec("CREATE TABLE IF NOT EXISTS `login_attempts` (
                    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `ip`           VARCHAR(45)     NOT NULL,
                    `username`     VARCHAR(64)     NULL,
                    `attempted_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_attempt_ip` (`ip`, `attempted_at`),
                    KEY `idx_attempt_user` (`username`, `attempted_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }],
            '005_api_tokens' => ['API erişim anahtarları (yalnızca özet saklanır)', function (PDO $p) {
                $p->exec("CREATE TABLE IF NOT EXISTS `api_tokens` (
                    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `name`         VARCHAR(100) NOT NULL,
                    `token_hash`   CHAR(64)     NOT NULL,
                    `token_prefix` VARCHAR(12)  NOT NULL,
                    `scopes`       VARCHAR(50)  NOT NULL DEFAULT 'read,write',
                    `created_by`   VARCHAR(64)  NULL,
                    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `last_used_at` DATETIME     NULL DEFAULT NULL,
                    `revoked_at`   DATETIME     NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_token_hash` (`token_hash`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }],
            '006_settings' => ['Panel ayarları tablosu', function (PDO $p) {
                $p->exec("CREATE TABLE IF NOT EXISTS `settings` (
                    `skey`       VARCHAR(64) NOT NULL,
                    `svalue`     TEXT        NULL,
                    `updated_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`skey`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }],
            // 007 portal kayıtları (PR #3) için ayrıldı.
            '008_users_roles' => ['Kullanıcı rolleri ve hesap alanları (mevcut hesaplar Yönetici kalır)', function (PDO $p) {
                self::addColumn($p, 'users', 'role',                 "VARCHAR(10) NOT NULL DEFAULT 'admin'");
                self::addColumn($p, 'users', 'display_name',         'VARCHAR(100) NULL DEFAULT NULL');
                self::addColumn($p, 'users', 'email',                'VARCHAR(190) NULL DEFAULT NULL');
                self::addColumn($p, 'users', 'is_active',            'TINYINT(1) NOT NULL DEFAULT 1');
                self::addColumn($p, 'users', 'must_change_password', 'TINYINT(1) NOT NULL DEFAULT 0');
                self::addColumn($p, 'users', 'last_login_at',        'DATETIME NULL DEFAULT NULL');
                self::addColumn($p, 'users', 'created_by',           'VARCHAR(64) NULL DEFAULT NULL');
            }],
            '009_user_scopes' => ['Kullanıcı kapsamı (hangi QR\'ları görür / düzenler)', function (PDO $p) {
                $p->exec("CREATE TABLE IF NOT EXISTS `user_scopes` (
                    `id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id` INT UNSIGNED NOT NULL,
                    `kind`    VARCHAR(10)  NOT NULL,
                    `value`   VARCHAR(100) NOT NULL DEFAULT '',
                    PRIMARY KEY (`id`),
                    KEY `idx_user_scopes_user` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }],
            '010_owners' => ['QR sahibi ve API anahtarının bağlı olduğu kullanıcı', function (PDO $p) {
                self::addColumn($p, 'links', 'created_by', 'INT UNSIGNED NULL DEFAULT NULL');
                self::addIndex($p, 'links', 'idx_links_created_by', '(`created_by`)');
                self::addColumn($p, 'api_tokens', 'user_id', 'INT UNSIGNED NULL DEFAULT NULL');
            }],
            '011_link_requests' => ['Editör talepleri: pasife alma / yeniden açma için yönetici onayı', function (PDO $p) {
                $p->exec("CREATE TABLE IF NOT EXISTS `link_requests` (
                    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `link_id`       INT UNSIGNED NOT NULL,
                    `kind`          VARCHAR(16)  NOT NULL,
                    `status`        VARCHAR(10)  NOT NULL DEFAULT 'pending',
                    `note`          VARCHAR(500) NULL DEFAULT NULL,
                    `requested_by`  INT UNSIGNED NULL DEFAULT NULL,
                    `requester`     VARCHAR(64)  NOT NULL DEFAULT '',
                    `decided_by`    VARCHAR(64)  NULL DEFAULT NULL,
                    `decision_note` VARCHAR(500) NULL DEFAULT NULL,
                    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `decided_at`    DATETIME     NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_link_requests_status` (`status`),
                    KEY `idx_link_requests_link` (`link_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }],
            '012_vcards' => ['QR türü (link / kartvizit) ve kartvizit bilgileri (mevcut QR\'lar link kalır)', function (PDO $p) {
                self::addColumn($p, 'links', 'qr_type', "VARCHAR(10) NOT NULL DEFAULT 'link'");
                $p->exec("CREATE TABLE IF NOT EXISTS `vcards` (
                    `link_id`      INT UNSIGNED NOT NULL,
                    `first_name`   VARCHAR(80)  NULL DEFAULT NULL,
                    `last_name`    VARCHAR(80)  NULL DEFAULT NULL,
                    `title`        VARCHAR(120) NULL DEFAULT NULL,
                    `department`   VARCHAR(120) NULL DEFAULT NULL,
                    `org`          VARCHAR(120) NULL DEFAULT NULL,
                    `phone_mobile` VARCHAR(40)  NULL DEFAULT NULL,
                    `phone_work`   VARCHAR(40)  NULL DEFAULT NULL,
                    `email`        VARCHAR(190) NULL DEFAULT NULL,
                    `website`      VARCHAR(500) NULL DEFAULT NULL,
                    `address`      VARCHAR(300) NULL DEFAULT NULL,
                    `linkedin`     VARCHAR(500) NULL DEFAULT NULL,
                    `note`         VARCHAR(500) NULL DEFAULT NULL,
                    `photo`        MEDIUMBLOB   NULL DEFAULT NULL,
                    `photo_mime`   VARCHAR(20)  NULL DEFAULT NULL,
                    `save_count`   INT UNSIGNED NOT NULL DEFAULT 0,
                    `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`link_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }],
            '013_qr_templates' => ['QR tasarım şablonları (5 başlangıç şablonu) ve QR\'a şablon / özel tasarım bağlama', function (PDO $p) {
                $p->exec("CREATE TABLE IF NOT EXISTS `qr_templates` (
                    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `name`        VARCHAR(80)  NOT NULL,
                    `usage`       VARCHAR(20)  NOT NULL DEFAULT 'other',
                    `description` VARCHAR(300) NULL DEFAULT NULL,
                    `design`      TEXT         NOT NULL,
                    `print_mm`    SMALLINT UNSIGNED NULL DEFAULT NULL,
                    `sort_order`  INT          NOT NULL DEFAULT 0,
                    `created_by`  VARCHAR(64)  NULL DEFAULT NULL,
                    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `archived_at` DATETIME     NULL DEFAULT NULL,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                // Boş sütun = bugünkü davranış (marka varsayılanı ya da fg/bg/shape)
                self::addColumn($p, 'links', 'template_id', 'INT UNSIGNED NULL DEFAULT NULL');
                self::addColumn($p, 'links', 'design', 'TEXT NULL DEFAULT NULL');
                if ((int)$p->query('SELECT COUNT(*) FROM qr_templates')->fetchColumn() === 0) {
                    $ins = $p->prepare('INSERT INTO qr_templates (name, `usage`, description, design, print_mm, sort_order, created_by) VALUES (?,?,?,?,?,?,?)');
                    foreach (QrTemplates::STARTERS as $i => $t) {
                        $ins->execute([$t['name'], $t['usage'], $t['description'], json_encode($t['design'], JSON_UNESCAPED_SLASHES), $t['print_mm'], ($i + 1) * 10, 'sistem']);
                    }
                }
            }],
        ];
    }

    // ─── Durum ───────────────────────────────────────────────────────────────

    /** Kodun beklediği şema sürümü (son migration kimliği) */
    public static function version(): string {
        $ids = array_keys(self::definitions());
        return end($ids) ?: '0';
    }

    /** @return string[] Uygulanmamış migration kimlikleri */
    public static function pending(PDO $pdo): array {
        self::ensureTable($pdo);
        $done = $pdo->query('SELECT id FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_diff(array_keys(self::definitions()), $done));
    }

    /** @return array<int,array{id:string,description:string,applied_at:?string}> */
    public static function status(PDO $pdo): array {
        self::ensureTable($pdo);
        $done = $pdo->query('SELECT id, applied_at FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
        $out = [];
        foreach (self::definitions() as $id => [$desc]) {
            $out[] = ['id' => $id, 'description' => $desc, 'applied_at' => $done[$id] ?? null];
        }
        return $out;
    }

    /**
     * Bekleyen migration varsa uygular. Yönetim paneli girişinde otomatik çağrılır.
     * Hata olursa sayfayı düşürmez; hata mesajını döndürür.
     *
     * @return array{applied:string[],error:?string,backup:?string}
     */
    public static function run(PDO $pdo): array {
        $result = ['applied' => [], 'error' => null, 'backup' => null];
        try {
            $pending = self::pending($pdo);
            if (!$pending) return $result;

            $got = $pdo->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 10)")->fetchColumn();
            if ((int)$got !== 1) {
                $result['error'] = 'Veritabanı güncellemesi başka bir istek tarafından yapılıyor.';
                return $result;
            }

            try {
                // Kilidi aldıktan sonra tekrar kontrol et
                $pending = self::pending($pdo);
                if (!$pending) return $result;

                $before = (int)$pdo->query('SELECT COUNT(*) FROM links')->fetchColumn();
                $result['backup'] = self::backupLinks($pdo);

                $defs = self::definitions();
                foreach ($pending as $id) {
                    ($defs[$id][1])($pdo);
                    $pdo->prepare('INSERT IGNORE INTO schema_migrations (id) VALUES (?)')->execute([$id]);
                    $result['applied'][] = $id;
                    Logger::info("Migration uygulandı: {$id}");
                }

                $after = (int)$pdo->query('SELECT COUNT(*) FROM links')->fetchColumn();
                if ($after !== $before) {
                    // Eklemeli migration kayıt sayısını değiştiremez; olursa hemen haber ver.
                    Logger::error("Migration sonrası link sayısı değişti: {$before} -> {$after}");
                    $result['error'] = "Link sayısı değişti ({$before} → {$after}). Yedek: " . ($result['backup'] ?? '-');
                }
            } finally {
                $pdo->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')");
            }
        } catch (\Throwable $e) {
            Logger::error('Migration hatası: ' . $e->getMessage(), $e->getFile() . ':' . $e->getLine());
            $result['error'] = 'Veritabanı güncellemesi başarısız: ' . $e->getMessage();
        }
        return $result;
    }

    // ─── Yedek ───────────────────────────────────────────────────────────────

    /** `links` tablosunu INSERT dosyası olarak logs/backups/ altına yazar (web'den erişilemez). */
    private static function backupLinks(PDO $pdo): ?string {
        $base = defined('ROOT_DIR') ? ROOT_DIR . '/logs/backups' : null;
        if ($base === null) return null;
        if (!is_dir($base) && !@mkdir($base, 0755, true)) return null;

        $file = $base . '/pre-migrate-' . date('Ymd-His') . '-links.sql';
        $rows = $pdo->query('SELECT * FROM links ORDER BY id')->fetchAll();
        $sql  = "-- QR Manager: migration öncesi links yedeği (" . date('c') . ")\n";
        foreach ($rows as $row) {
            $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($row)));
            $vals = implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row));
            $sql .= "INSERT INTO `links` ($cols) VALUES ($vals);\n";
        }
        if (@file_put_contents($file, $sql) === false) return null;

        // Son 10 yedeği tut
        $old = glob($base . '/pre-migrate-*-links.sql') ?: [];
        sort($old);
        foreach (array_slice($old, 0, max(0, count($old) - 10)) as $f) @unlink($f);

        return $file;
    }

    // ─── Şema yardımcıları (idempotent) ──────────────────────────────────────

    private static function ensureTable(PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `schema_migrations` (
            `id`         VARCHAR(64) NOT NULL,
            `applied_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $st->execute([$table, $column]);
        return (int)$st->fetchColumn() > 0;
    }

    private static function addColumn(PDO $pdo, string $table, string $column, string $ddl): void {
        if (self::columnExists($pdo, $table, $column)) return;
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $ddl");
    }

    private static function addIndex(PDO $pdo, string $table, string $name, string $cols): void {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $st->execute([$table, $name]);
        if ((int)$st->fetchColumn() > 0) return;
        $pdo->exec("ALTER TABLE `$table` ADD INDEX `$name` $cols");
    }
}
