<?php
/**
 * bootstrap.php — yeni sayfaların ortak başlangıcı: yapılandırma + tüm kütüphaneler.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Icons.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Access.php';
require_once __DIR__ . '/UserService.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Migrator.php';
require_once __DIR__ . '/LinkService.php';
require_once __DIR__ . '/LinkRequests.php';
require_once __DIR__ . '/VCard.php';
require_once __DIR__ . '/QrDesign.php';
require_once __DIR__ . '/QrTemplates.php';
require_once __DIR__ . '/Redirector.php';
require_once __DIR__ . '/QrRenderer.php';
require_once __DIR__ . '/Share.php';
require_once __DIR__ . '/Stats.php';
require_once __DIR__ . '/Xlsx.php';
require_once __DIR__ . '/ReportExport.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/Notifications.php';
require_once __DIR__ . '/Jobs.php';
require_once __DIR__ . '/ApiAuth.php';

Logger::registerHandlers();
