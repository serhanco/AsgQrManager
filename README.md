# QR Manager — Dinamik QR Yönlendirme Sistemi

> **Temel Fikir:** Basılı materyallere konacak QR kodları asla değişmez.
> QR, sabit bir kısa URL'i kodlar; o URL'nin yönlendirdiği gerçek hedef adres
> panelden istediğiniz zaman güncellenebilir.

---

## Sürüm 3 — Kartvizit (vCard) QR türü

`Yeni QR` ekranında **Link** veya **Kartvizit** seçilir. Mevcut QR'ların hepsi Link türünde kalır.

- Kartvizit QR'ı da kısa linki (`/r/{slug}`) kodlar. Okutulunca markalı kişi sayfası açılır: ara, e-posta, WhatsApp, web, LinkedIn, harita ve **Rehbere ekle** (`/r/{slug}?vcf=1`, vCard 3.0, fotoğraf dahil). Sayfa dili telefona göre TR/EN.
- Bilgiler (ünvan, telefon, fotoğraf…) sonradan panelden değiştirilir; basılı QR aynı kalır. Daha önce rehbere kaydedilmiş kopyalar telefonda kendiliğinden güncellenmez.
- `links.target_url` kartvizitte kişinin web sitesidir (yoksa genel site): kişi kaydı okunamazsa QR yine oraya yönlenir. Pasif / arşiv / süresi dolmuş kartvizit, link QR'larla aynı yedek hedef kurallarını izler.
- Rapor: okutmaların yanında **Rehbere ekleme** sayısı. Panelden önizleme (`?preview=1`) okutma sayılmaz.
- API: `POST /links` için `"type": "vcard"` ve `"vcard": {first_name, last_name, title, department, org, phone_mobile, phone_work, email, website, address, linkedin, note}`; `PATCH` ile `vcard` alanları kısmen güncellenir.
- Arayüzde eski "Tür" alanının adı **Kategori** oldu (Genel Merkez, Ofis, Etkinlik, Kampanya); veri aynı.
- Şema: `012_vcards` (`links.qr_type` varsayılan `link` + `vcards` tablosu; yalnızca ekleme).

## Sürüm 3 — Kullanıcılar ve roller

Tek yönetici hesabının yanına sınırlı yetkili hesaplar eklenir (`Sistem → Kullanıcılar`). Mevcut hesaplar **Yönetici** olarak kalır.

| Rol | Yapabildikleri |
|---|---|
| **Yönetici** | Her şey: tüm QR'lar, kullanıcılar, ayarlar, API anahtarları, etiket yönetimi, arşivleme; editör taleplerini onaylar. |
| **Editör** | QR oluşturur ve **yalnızca kendi oluşturduğu** QR'ları düzenler. Pasife alma / yeniden açma için yönetici onayına talep gönderir (`QR Kodlar → Taleplerim`). Kapsamındaki diğer QR'ları ve raporlarını yalnızca görür. Arşivleyemez. |

Eski sürümde oluşturulmuş **Rapor izleyici** hesapları çalışmaya devam eder (görür, değiştiremez), ancak yeni kullanıcıya bu rol atanmaz.

- **Kapsam:** "Tüm QR'lar" ya da tür (Ofis, Etkinlik…) ve etiket (ör. `Almanya`) seçimi. Kullanıcı ayrıca kendi oluşturduğu QR'ları her zaman görür. Liste, rapor, gösterge paneli, CSV, değişiklik geçmişi ve API aynı kapsamla süzülür.
- **Hesaplar silinmez**, devre dışı bırakılır; devre dışı hesabın oturumu ve API anahtarları hemen çalışmaz hale gelir. Son etkin yönetici düşürülemez.
- **Geçici şifre:** yeni kullanıcıya ve şifre sıfırlamada sistem geçici şifre üretir; kullanıcı ilk girişte değiştirmeden ilerleyemez (en az 10 karakter, harf + rakam).
- **Onay akışı:** editör kendi QR'ının Aktif/Pasif rozetine (veya düzenleme sayfasındaki karta) tıklayınca talep oluşur; QR onaylanana kadar olduğu gibi çalışır. Yönetici `Onay Bekleyenler` sayfasından onaylar veya reddeder; her adım QR'ın değişiklik geçmişine yazılır.
- **API anahtarı → kullanıcı:** anahtar bir kullanıcıya bağlanırsa onun rolü ve kapsamıyla çalışır (Rapor izleyicinin anahtarı yazamaz; editörün anahtarı yalnızca kendi QR'larını değiştirir, `POST /links/{slug}/deactivate` onay talebi açar ve `202` döner). Bağlanmayan anahtar eskisi gibi tam yetkilidir.
- Şema: `008_users_roles`, `009_user_scopes`, `010_owners`, `011_link_requests` (yalnızca ekleme; `007` portal entegrasyonuna ayrıldı).
- **CI:** `.github/workflows/tests.yml` her PR'da testleri, örnek v1 veritabanından migration zincirini ve üretilen QR'ların `zbarimg` ile okunduğunu doğrular.

## Sürüm 2 — Neler değişti?

Mevcut kurulumlar **veri kaybetmeden** güncellenir. Üretilmiş hiçbir QR kodu, kısa ad veya okutma geçmişi silinmez.

| Alan | Yenilik |
|---|---|
| **Veri güvenliği** | Yalnızca *ekleyen* migration'lar (`lib/Migrator.php`). Yönetici ilk girişte şema otomatik güncellenir; öncesinde `links` tablosunun yedeği `logs/backups/` altına yazılır ve kayıt sayıları doğrulanır. Hiçbir sütun/tablo silinmez, `r.php` migration'a bağlı değildir. |
| **Silme yerine arşiv** | Linkler arşivlenir (geri alınabilir, okutulunca hedefe gitmez). Kalıcı silme yalnızca *hiç okutulmamış ve arşivdeki* kayıtlar için mümkündür. |
| **Marka özelleştirme** | Ayarlar → marka rengi, nokta şekli (kare/yuvarlak/nokta), varsayılan logo; QR başına özel renk/şekil. Okunabilirlik kuralı: ön plan arka plandan koyu olmalı, kontrast ≥ 4:1; üç köşe kare kalır. |
| **İndirme** | SVG, PDF (vektör; 30/50/100/200 mm), EPS ve AI (matbaa için CMYK vektör), PNG (600–3000 px), şeffaf PNG, tek tıkla baskı paketi (ZIP). |
| **Paylaşım** | Panoya kopyala, cihaz paylaşım menüsü, WhatsApp, e-posta, ajans/matbaa için **imzalı ve süreli indirme bağlantısı** (giriş gerektirmez, iptal edilebilir). |
| **Yönlendirme** | Başlangıç/bitiş zamanı, bitiş sonrası adres, yedek hedef (genel ve QR'a özel), isteğe bağlı UTM etiketleri. |
| **Düzen ve etiketler** | **Tür** (tek seçim: Genel Merkez/Ofis/Etkinlik/Kampanya) ve **etiket** (serbest, çoklu: ülke, etkinlik adı, materyal…). Formda çipli giriş, mevcut etiketlerden öneri, tür/etiket rehberi ve ⓘ ipuçları; İstanbul/istanbul/Istanbul gibi yazımlar otomatik birleşir. Etiketler sayfası (yeniden adlandır, birleştir, kaldır), listede çoklu seçimle toplu etiket ekle/çıkar, "etiketsiz" filtresi, etikete göre birleşik rapor. CSV dışa aktarım ve CSV ile toplu oluşturma. |
| **Raporlar** | Tarih aralığı, saat/gün/ülke/cihaz kırılımı, tekil ziyaretçi tahmini. Bot ve link önizleme istekleri sayılmaz. |
| **Denetim izi** | Kim, ne zaman, neyi değiştirdi (`Değişiklik Geçmişi`). |
| **API** | `/api/v1` — Bearer anahtarı (yalnızca hash'i saklanır), okuma/yazma yetkisi. QR **silme uç noktası yoktur**. Belgeler: `/admin/api-docs`. |
| **Güvenlik** | Veritabanı tabanlı brute-force koruması, güvenli oturum çerezi (HTTPS), CSRF, CSV formül enjeksiyonu koruması, SVG logo yüklemeye kapalı, `noindex` başlıkları, `.sql/.md/.lock` dosyalarına erişim engeli, sadece HTTPS API, KVKK için IP maskeleme/anonimleştirme. |

### Güncelleme (canlıda çalışan kurulum)

1. `main`'e birleştirmek Hostinger Git senkronuyla canlıya çıkar (`config.php` ve `logs/`, `cache/`, `logo/` içeriği repoda olmadığı için **etkilenmez**).
2. Dağıtımdan sonra **bir kez yönetici olarak giriş yapın**; şema otomatik güncellenir. (İsterseniz önce phpMyAdmin'den dışa aktarım alın.)
3. Hostinger Git senkronu depodaki **her dosyayı** sunucuya kopyalar (`install.php`, `schema.sql`, `README.md`, `tests/` dahil). Bu yüzden bunlar web'den korunur: `install.php` veritabanında yönetici varsa kendini kilitler, `.sql`/`.md`/`.lock` dosyaları `.htaccess` ile engellenir, `tests/` yalnızca komut satırında çalışır ve web erişimine kapalıdır.
4. Cloudflare arkasındaysanız hiçbir ayar gerekmez; arkasında değilseniz `config.php`'ye `define('TRUST_PROXY_HEADERS', false);` ekleyin.

### Testler

```bash
# config.php'yi TEST veritabanına yönlendirin (adında "test" geçmeli, canlıda ASLA çalıştırmayın)
php tests/run.php --dump=uretim-dokumu.sql   # dökümü yükler, migration'ın hiçbir kaydı kaybettirmediğini doğrular
php tests/run.php                            # yalnızca mantık testleri
php tests/run.php --dump=tests/fixtures/v1-ornek.sql   # sentetik ilk sürüm veritabanıyla migration zinciri
# zbarimg kuruluysa (apt install zbar-tools) üretilen QR'ların okunabilirliği de sınanır
```

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

// config.php
define('DB_HOST',    'localhost');
define('DB_NAME',    'qrmanager');
define('DB_USER',    'veritabani_kullanicisi');
define('DB_PASS',    'sifre');

// Uygulama adresini sabitlemek isterseniz doldurun.
// Boş bırakılırsa (null) sistem URL'yi ve alt klasörü OTOMATİK algılar!
define('CUSTOM_BASE_URL', null); 
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

Kurulum yaptığınız adrese göre panele erişin, örn:
```
https://siteniz.com/qr/admin/login.php
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
├── share.php               ← İmzalı, süreli QR indirme sayfası
├── api/index.php           ← REST API v1
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
│   ├── Auth.php            ← Oturum, CSRF, DB tabanlı brute-force
│   ├── helpers.php         ← PDO singleton, slug üretici, yardımcılar
│   ├── bootstrap.php       ← Tüm sınıfları yükler
│   ├── Migrator.php        ← Yalnızca-ekleyen, yedekli şema güncellemeleri
│   ├── Settings.php        ← Marka / yönlendirme / gizlilik ayarları
│   ├── LinkService.php     ← Link doğrulama, oluşturma, arşivleme, denetim izi
│   ├── Redirector.php      ← Okutma kararı (aktif/pasif/zamanlı/yedek)
│   ├── QrPdf.php           ← Vektör PDF (ve AI) çıktısı
│   ├── QrEps.php           ← Vektör EPS çıktısı
│   ├── Share.php           ← İmzalı indirme bağlantıları, format üretimi
│   ├── Stats.php           ← Rapor sorguları (panel + API ortak)
│   └── ApiAuth.php         ← API anahtarları
│
├── admin/
│   ├── login.php           ← Giriş sayfası
│   ├── logout.php          ← Çıkış
│   ├── dashboard.php       ← İstatistikler + grafik
│   ├── links.php           ← Link listesi (arama + sayfalama)
│   ├── create.php          ← Yeni link oluştur
│   ├── edit.php            ← Link düzenle (hedef URL güncelle)
│   ├── delete.php          ← Arşivle (POST); yalnızca okutulmamış arşivler kalıcı silinebilir
│   ├── restore.php         ← Arşivden çıkar
│   ├── bulk.php            ← CSV ile toplu oluştur
│   ├── tags.php            ← Etiket yönetimi (yeniden adlandır/birleştir/kaldır)
│   ├── bulk-tags.php       ← Listeden seçili QR'lara toplu etiket (POST)
│   ├── report.php          ← Raporlar
│   ├── history.php        ← Değişiklik geçmişi
│   ├── settings.php        ← Marka, yönlendirme, gizlilik, logolar, paylaşım
│   ├── api-tokens.php      ← API anahtarları · api-docs.php: belgeler
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

// URL
// Sabitlemek isterseniz 'https://siteniz.com/qr' yazın. Boş bırakırsanız OTOMATİK algılanır.
define('CUSTOM_BASE_URL', null);

// Özellikler
define('ANALYTICS_ENABLED',  true);   // false → tarama loglaması durur
define('SCAN_COUNT_ENABLED', true);   // false → scan_count artmaz

// Log seviyesi: DEBUG | INFO | WARNING | ERROR
define('LOG_LEVEL', 'INFO');

// QR varsayılanları
define('QR_DEFAULT_ECC',    'M');     // L / M / Q / H
define('QR_DEFAULT_SIZE',   1200);    // PNG px (Baskı kalitesi için)
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

- **URL formatı:** `https://siteniz.com/qr/r/{slug}`
- **HTTP kodu:** `302` (geçici — tarayıcı cache'lemez)
- **Cache-Control:** `no-store, no-cache, must-revalidate`
- **301 kullanılmaz** — hedef değiştiğinde eski cache geçersiz olur

### mod_rewrite yoksa fallback:
```
https://siteniz.com/qr/r.php?c={slug}
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

**QR tarandığında 404:** `.htaccess` dosyasının projenin ana klasöründe olduğundan ve mod_rewrite'ın açık olduğundan emin olun (`a2enmod rewrite`). Alt klasöre kurduğunuzda kural otomatik olarak o klasöre göre çalışır.

**PNG logosuz üretiliyor:** `php -m | grep -i gd` ve `php -m | grep -i imagick`
komutlarıyla eklentilerin yüklü olduğunu kontrol edin.

**cache/ veya logs/ klasörüne yazılamıyor:** `chmod 775` ve `chown www-data` uygulayın.

**"CSRF doğrulaması başarısız":** PHP session'larının çalıştığından emin olun;
paylaşımlı host'larda session dizinine yazma izni gerekebilir.
