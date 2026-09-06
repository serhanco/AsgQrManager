# QR Manager — Dinamik QR Yönlendirme Sistemi

> **Temel Fikir:** Basılı materyallere konacak QR kodları asla değişmez.
> QR, sabit bir kısa URL'i kodlar; o URL'nin yönlendirdiği gerçek hedef adres
> panelden istediğiniz zaman güncellenebilir.

---

## Gereksinimler

| Bileşen | Gereksinim |
|---|---|
| PHP | 8.1+ |
| Veritabanı | MySQL 5.7+ veya MariaDB 10.4+ |
| PHP Eklentileri | PDO, pdo_mysql, **GD veya Imagick** (PNG logo için) |
| Web Sunucusu | Apache + mod_rewrite **veya** Nginx |
| Kurulum Araçları | **Hiçbiri** — Composer, Node, Python gereksiz |

---

## Hızlı Kurulum (Apache)

### 1. Dosyaları Kopyalayın

```bash
# Sunucunuzda /var/www/html/qr/ klasörüne (veya tercih ettiğiniz yola)
cp -r QrManager/ /var/www/html/qr/

# Yazma izinleri
chmod -R 755 /var/www/html/qr/
chmod -R 775 /var/www/html/qr/cache/
chmod -R 775 /var/www/html/qr/logs/
chmod -R 775 /var/www/html/qr/logo/
chown -R www-data:www-data /var/www/html/qr/
```

### 2. config.php'yi Düzenleyin

```php
// config.php
define('DB_HOST',    'localhost');
define('DB_NAME',    'qrmanager');
define('DB_USER',    'veritabani_kullanicisi');
define('DB_PASS',    'sifre');
define('BASE_URL',   'https://internationalapp.net/qr');   // sonda / YOK
define('BASE_PATH',  '/qr');
```

### 3. Kurulum Sihirbazını Çalıştırın

Tarayıcıdan erişin (yalnızca localhost'tan):
```
http://localhost/qr/install.php
```
- Admin kullanıcı adı ve şifresini girin
- Tablolar ve klasörler otomatik oluşturulur
- **Kurulum sonrası `install.php`'yi silin!** `rm install.php`

### 4. Giriş Yapın

```
https://internationalapp.net/qr/admin/login.php
```

---

## Nginx Kurulumu (alt klasör `/qr`)

Apache yerine Nginx kullanıyorsanız şu `location` bloğunu ekleyin:

```nginx
location /qr/ {
    alias /var/www/html/qr/;
    index index.php;

    # Statik dosyalar (CSS, JS, QR cache)
    location ~* \.(css|js|svg|png|jpg|webp)$ {
        expires 1h;
        add_header Cache-Control "public";
        try_files $uri =404;
    }

    # /qr/r/{slug} yönlendirme
    location ~ ^/qr/r/([A-Za-z0-9_-]+)$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/html/qr/r.php;
        fastcgi_param QUERY_STRING    c=$1;
    }

    # PHP dosyaları
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/html$fastcgi_script_name;
    }

    # logs/ dizinine erişimi engelle
    location /qr/logs/ {
        deny all;
        return 403;
    }

    try_files $uri $uri/ /qr/index.php?$query_string;
}
```

---

## Proje Yapısı

```
QrManager/
├── index.php               ← Giriş (login/dashboard yönlendirir)
├── r.php                   ← QR yönlendirme uç noktası (302)
├── install.php             ← Kurulum sihirbazı (sonra silin!)
├── config.php              ← Tüm ayarlar burada
├── schema.sql              ← Veritabanı şeması
├── .htaccess               ← mod_rewrite + güvenlik
├── qr-standalone.html      ← PHP'den bağımsız, tek dosya JS sürümü
│
├── lib/
│   ├── phpqrcode.php       ← Bağımlılıksız QR encoder (tek dosya)
│   ├── QrRenderer.php      ← SVG + PNG üretici + logo yerleştirme
│   ├── Logger.php          ← Uygulama ve tarama log yöneticisi
│   ├── Auth.php            ← Oturum, CSRF, brute-force
│   └── helpers.php         ← PDO singleton, slug üretici, yardımcılar
│
├── admin/
│   ├── login.php           ← Giriş sayfası
│   ├── logout.php          ← Çıkış
│   ├── dashboard.php       ← İstatistikler + grafik
│   ├── links.php           ← Link listesi (arama + sayfalama)
│   ├── create.php          ← Yeni link oluştur
│   ├── edit.php            ← Link düzenle (hedef URL güncelle)
│   ├── delete.php          ← Sil (POST)
│   ├── toggle.php          ← Aktif/pasif (POST)
│   ├── qr-view.php         ← QR görüntüle + indirme uç noktası
│   ├── logs.php            ← Log görüntüleyici
│   ├── log-download.php    ← Log dosyası indirme
│   ├── analytics-toggle.php← Analytics AJAX aç/kapat
│   └── views/
│       ├── _layout.php     ← Ortak HTML başlangıcı (sidebar + topbar)
│       ├── _layout_end.php ← Ortak HTML sonu
│       ├── 404.php         ← 404 sayfası
│       └── error.php       ← Hata sayfası
│
├── assets/
│   ├── style.css           ← Tam sıfırdan yazılmış CSS (dark mode dahil)
│   └── app.js              ← Panel JS (grafik, clipboard, sidebar)
│
├── logo/                   ← Logolarınızı buraya koyun
│   └── .htaccess
│
├── cache/qr/               ← Üretilen QR dosyaları (YYYY/MM/)
│   └── .htaccess
│
└── logs/                   ← Erişim engelli! (web'den görülmez)
    ├── .htaccess           ← Deny from all
    ├── scans/YYYY/MM/      ← scans-YYYY-MM-DD.txt
    └── app/YYYY/MM/        ← app-YYYY-MM-DD.txt
```

---

## Logo Ekleme

1. `logo/` klasörüne PNG, SVG, JPG veya WEBP dosyası atın
2. Dosya adı: sadece harf, rakam, tire, alt çizgi (ör: `sirket-logo.png`)
3. Yeni link oluştururken veya düzenlerken logo galeriden seçin
4. Logo kullanıldığında ECC seviyesi otomatik **H**'ye yükseltilir

> **Not:** PNG çıktısında logo yerleştirmek için sunucuda **GD** veya **Imagick**
> eklentisi gereklidir. SVG çıktısında logo her durumda gösterilir.

---

## Konfigürasyon Referansı

```php
// config.php — tüm ayarlar

// Veritabanı
define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'qrmanager');
define('DB_USER', 'root');
define('DB_PASS', '');

// URL (sonda / olmadan)
define('BASE_URL',  'https://internationalapp.net/qr');
define('BASE_PATH', '/qr');

// Özellikler
define('ANALYTICS_ENABLED',  true);   // false → tarama loglaması durur
define('SCAN_COUNT_ENABLED', true);   // false → scan_count artmaz

// Log seviyesi: DEBUG | INFO | WARNING | ERROR
define('LOG_LEVEL', 'INFO');

// QR varsayılanları
define('QR_DEFAULT_ECC',    'M');     // L / M / Q / H
define('QR_DEFAULT_SIZE',   600);     // PNG px
define('QR_DEFAULT_MARGIN', 4);       // modül (quiet zone)

// Güvenlik
define('BRUTE_MAX_ATTEMPTS', 5);
define('BRUTE_LOCKOUT_SEC',  300);    // 5 dakika

// Slug uzunluğu (6-12 önerilen)
define('SLUG_LENGTH', 7);
```

---

## Analytics Ayarları

| `ANALYTICS_ENABLED` | `SCAN_COUNT_ENABLED` | Davranış |
|---|---|---|
| `true` | `true` | DB + dosyaya yaz, sayaç artar |
| `false` | `true` | Yalnızca sayaç artar |
| `false` | `false` | Hiçbir şey yazılmaz |
| `true` | `false` | DB + dosyaya yaz, sayaç artmaz |

Dashboard'daki toggle, `config.php`'deki `ANALYTICS_ENABLED` değerini anında değiştirir.

---

## QR Yönlendirme Detayları

- **URL formatı:** `https://internationalapp.net/qr/r/{slug}`
- **HTTP kodu:** `302` (geçici — tarayıcı cache'lemez)
- **Cache-Control:** `no-store, no-cache, must-revalidate`
- **301 kullanılmaz** — hedef değiştiğinde eski cache geçersiz olur

### mod_rewrite yoksa fallback:
```
https://internationalapp.net/qr/r.php?c={slug}
```
Bu URL doğrudan da çalışır.

---

## Güvenlik Notları

| Risk | Önlem |
|---|---|
| SQL Injection | PDO prepared statements (tüm sorgular) |
| XSS | `htmlspecialchars` (tüm çıktılar) |
| CSRF | Token (tüm POST formları) |
| Brute-Force | Session sayacı + `sleep(2)` + 5 denemede 5dk kilit |
| Open Redirect | Yalnızca `http://` ve `https://` şemasına izin |
| Path Traversal | `realpath()` + base dir prefix kontrolü (log viewer) |
| Log erişimi | `.htaccess Deny from all` + login gerektirme |
| Logo | Yalnızca png/svg/jpg/webp uzantısı, `basename()` |

---

## Varsayımlar (Tasarım Kararları)

1. **QR kütüphanesi:** `phpqrcode` algoritması tek dosyada uygulandı (Composer yok).
   Hata düzeltme ve RS block hesaplaması basitleştirildi; üretim kalitesi için
   test edip doğrulayın.

2. **Analytics toggle:** Config dosyasını doğrudan yeniden yazar (`file_put_contents`).
   Yüksek trafikli ortamda bunun yerine DB'de bir `settings` tablosu tercih edilebilir.

3. **Grafik:** Harici kütüphane kullanılmadı; Canvas API ile basit çizgi grafik.
   Etkileşimli grafikler için Chart.js gibi bir kütüphane eklenebilir.

4. **Yönlendirme fallback:** `PATH_INFO` yoksa `?c=slug` parametresi kullanılır.
   Her iki mod da çalışır.

5. **JS Standalone:** `qrcodejs` CDN'den yüklenir. İnternet erişimi olmayan
   ortamlar için dosyayı indirip yerel olarak barındırın.

6. **Session/Cookie:** Cookie path `BASE_PATH/` olarak ayarlanmıştır.
   Alt klasör değişirse `config.php`'deki `BASE_PATH`'i güncellemek yeterlidir.

---

## Sorun Giderme

**QR tarandığında 404:** `.htaccess`'deki `RewriteBase /qr/` değerini ve
mod_rewrite'ın açık olduğunu kontrol edin (`a2enmod rewrite`).

**PNG logosuz üretiliyor:** `php -m | grep -i gd` ve `php -m | grep -i imagick`
komutlarıyla eklentilerin yüklü olduğunu kontrol edin.

**cache/ veya logs/ klasörüne yazılamıyor:** `chmod 775` ve `chown www-data` uygulayın.

**"CSRF doğrulaması başarısız":** PHP session'larının çalıştığından emin olun;
paylaşımlı host'larda session dizinine yazma izni gerekebilir.
