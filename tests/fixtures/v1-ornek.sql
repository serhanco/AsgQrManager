-- Sentetik v1 (ilk sürüm, d027276) veritabanı: migration zincirini CI'da sınamak için. Gerçek veri İÇERMEZ.
-- QR Manager — Veritabanı Şeması
-- MySQL / MariaDB  (ENGINE=InnoDB, utf8mb4)
-- Kullanım: mysql -u kullanici -p veritabani_adi < schema.sql

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;

-- ─── Linkler ──────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `links` (
  `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `slug`        VARCHAR(16)     NOT NULL,
  `title`       VARCHAR(255)    DEFAULT NULL,
  `target_url`  TEXT            NOT NULL,
  `is_active`   TINYINT(1)      NOT NULL DEFAULT 1,
  `logo`        VARCHAR(255)    DEFAULT NULL  COMMENT 'logo/ altındaki dosya adı',
  `scan_count`  INT UNSIGNED    NOT NULL DEFAULT 0,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Kullanıcılar (admin girişi) ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `users` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(64)   NOT NULL,
  `password_hash` VARCHAR(255)  NOT NULL,
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Tarama Logları ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `scans` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `link_id`     INT UNSIGNED    NOT NULL,
  `scanned_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip`          VARCHAR(45)     DEFAULT NULL,
  `user_agent`  TEXT            DEFAULT NULL,
  `referer`     TEXT            DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_link_id`        (`link_id`),
  KEY `idx_scanned_at`     (`scanned_at`),
  KEY `idx_scans_link_date`(`link_id`, `scanned_at`),
  CONSTRAINT `fk_scans_link` FOREIGN KEY (`link_id`) REFERENCES `links` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;

INSERT INTO users (username, password_hash) VALUES ('admin', '$2y$10$0000000000000000000000000000000000000000000000000000.');
INSERT INTO links (slug, title, target_url, is_active, logo, scan_count) VALUES
  ('ofis-ist', 'İstanbul Ofisi', 'https://acibadem.world/ofisler/istanbul', 1, NULL, 3),
  ('kongre26', 'Kongre 2026', 'https://acibadem.world/etkinlikler/kongre-2026?x=1', 1, 'acibadem-logo-1.png', 2),
  ('Ab3xY9z', NULL, 'https://acibadem.world/eski', 0, NULL, 0);
INSERT INTO scans (link_id, ip, user_agent, referer, scanned_at) VALUES
  (1, '203.0.113.10', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)', NULL, '2026-10-01 10:00:00'),
  (1, '203.0.113.11', 'Mozilla/5.0 (Linux; Android 14)', NULL, '2026-10-02 11:00:00'),
  (1, '203.0.113.12', 'WhatsApp/2.23', NULL, '2026-10-03 12:00:00'),
  (2, '203.0.113.10', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)', NULL, '2026-10-04 13:00:00'),
  (2, '203.0.113.13', 'Mozilla/5.0 (Windows NT 10.0)', 'https://www.linkedin.com/', '2026-10-05 14:00:00');
