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
