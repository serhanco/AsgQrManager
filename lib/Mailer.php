<?php
/**
 * Mailer — kütüphane gerektirmeyen küçük SMTP istemcisi (uyarı özeti ve periyodik rapor e-postaları).
 *
 * Varsayılan olarak kapalıdır: Ayarlar > E-posta bölümünde SMTP bilgileri girilip etkinleştirilince çalışır.
 * Güvenlik: tls (STARTTLS, genelde 587) ya da ssl (genelde 465); sertifika doğrulanır.
 */
class Mailer {

    public const SECURE = ['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => 'Şifresiz (önerilmez)'];

    /** Test için: gönderilen iletiler burada toplanır, SMTP'ye bağlanılmaz */
    public static ?array $outbox = null;

    public static function enabled(): bool {
        return Settings::get('mail_enabled', '0') === '1' && Settings::get('smtp_host', '') !== '' && self::from() !== '';
    }

    public static function from(): string {
        $f = trim(Settings::get('mail_from', ''));
        return filter_var($f, FILTER_VALIDATE_EMAIL) ? $f : '';
    }

    /**
     * @param string[] $to
     * @param array<int,array{name:string,data:string,type:string}> $attachments
     * @return ?string hata mesajı; başarılıysa null
     */
    public static function send(array $to, string $subject, string $html, string $text = '', array $attachments = []): ?string {
        $to = array_values(array_unique(array_filter($to, fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL))));
        if (!$to) return 'Geçerli alıcı yok.';
        $from = self::from();
        if ($from === '') return 'Gönderen adresi (Ayarlar > E-posta) geçersiz.';
        $msg = self::build($from, Settings::get('mail_from_name', 'Acıbadem QR Code Manager'), $to, $subject, $html, $text ?: self::toText($html), $attachments);
        if (self::$outbox !== null) { self::$outbox[] = ['to' => $to, 'subject' => $subject, 'html' => $html, 'attachments' => array_column($attachments, 'name'), 'raw' => $msg]; return null; }
        try {
            self::smtp($from, $to, $msg);
            return null;
        } catch (\Throwable $e) {
            Logger::warning('E-posta gönderilemedi: ' . $e->getMessage());
            return $e->getMessage();
        }
    }

    // ─── İleti ───────────────────────────────────────────────────────────────

    private static function header(string $s): string {
        return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }

    public static function build(string $from, string $fromName, array $to, string $subject, string $html, string $text, array $attachments): string {
        $b1 = 'b1_' . bin2hex(random_bytes(8));
        $b2 = 'b2_' . bin2hex(random_bytes(8));
        $domain = substr(strrchr($from, '@'), 1) ?: 'localhost';
        $h = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . self::header(str_replace(['"', "\r", "\n"], '', $fromName)) . ' <' . $from . '>',
            'To: ' . implode(', ', $to),
            'Subject: ' . self::header(str_replace(["\r", "\n"], ' ', $subject)),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Auto-Submitted: auto-generated',
            'Content-Type: multipart/mixed; boundary="' . $b1 . '"',
        ];
        $body = "--{$b1}\r\nContent-Type: multipart/alternative; boundary=\"{$b2}\"\r\n\r\n"
              . "--{$b2}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text)) . "\r\n"
              . "--{$b2}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "\r\n"
              . "--{$b2}--\r\n";
        foreach ($attachments as $a) {
            $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$a['name']);
            $body .= "--{$b1}\r\nContent-Type: {$a['type']}; name=\"{$name}\"\r\nContent-Transfer-Encoding: base64\r\n"
                   . "Content-Disposition: attachment; filename=\"{$name}\"\r\n\r\n" . chunk_split(base64_encode((string)$a['data'])) . "\r\n";
        }
        return implode("\r\n", $h) . "\r\n\r\n" . $body . "--{$b1}--\r\n";
    }

    private static function toText(string $html): string {
        $t = preg_replace(['/<br\s*\/?>/i', '/<\/(p|div|h\d|li|tr)>/i'], "\n", $html);
        return trim(html_entity_decode(strip_tags((string)$t), ENT_QUOTES, 'UTF-8'));
    }

    // ─── SMTP ────────────────────────────────────────────────────────────────

    private static function smtp(string $from, array $to, string $msg): void {
        $host = Settings::get('smtp_host', '');
        $port = (int)Settings::get('smtp_port', '587') ?: 587;
        $secure = Settings::get('smtp_secure', 'tls');
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
        $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) throw new RuntimeException("SMTP sunucusuna bağlanılamadı ({$host}:{$port}): {$errstr}");
        stream_set_timeout($fp, 20);
        try {
            self::expect($fp, [220]);
            $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
            $caps = self::cmd($fp, $ehlo, [250]);
            if ($secure === 'tls') {
                if (!preg_match('/STARTTLS/i', $caps)) throw new RuntimeException('Sunucu STARTTLS desteklemiyor; "SSL/TLS (465)" seçeneğini deneyin.');
                self::cmd($fp, 'STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new RuntimeException('TLS başlatılamadı (sertifika doğrulanamadı olabilir).');
                }
                $caps = self::cmd($fp, $ehlo, [250]);
            }
            $user = Settings::get('smtp_user', '');
            if ($user !== '') {
                $pass = Settings::get('smtp_pass', '');
                if (preg_match('/AUTH[^\n]*PLAIN/i', $caps)) {
                    self::cmd($fp, 'AUTH PLAIN ' . base64_encode("\0{$user}\0{$pass}"), [235], 'Kimlik doğrulama');
                } else {
                    self::cmd($fp, 'AUTH LOGIN', [334], 'Kimlik doğrulama');
                    self::cmd($fp, base64_encode($user), [334], 'Kimlik doğrulama');
                    self::cmd($fp, base64_encode($pass), [235], 'Kimlik doğrulama');
                }
            }
            self::cmd($fp, "MAIL FROM:<{$from}>", [250]);
            foreach ($to as $rcpt) self::cmd($fp, "RCPT TO:<{$rcpt}>", [250, 251]);
            self::cmd($fp, 'DATA', [354]);
            // Nokta ile başlayan satırlar iki nokta olur (RFC 5321)
            $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], ["\n", "\n"], $msg));
            fwrite($fp, str_replace("\n", "\r\n", $data) . "\r\n.\r\n");
            self::expect($fp, [250]);
            self::cmd($fp, 'QUIT', [221]);
        } finally {
            fclose($fp);
        }
    }

    /** $label hata mesajında görünür; parola içeren satırlar için mutlaka verilir */
    private static function cmd($fp, string $line, array $ok, ?string $label = null): string {
        fwrite($fp, $line . "\r\n");
        return self::expect($fp, $ok, $label ?? strtok($line, ' :'));
    }

    private static function expect($fp, array $ok, string $label = 'Bağlantı'): string {
        $resp = '';
        while (($l = fgets($fp, 2048)) !== false) {
            $resp .= $l;
            if (strlen($l) < 4 || $l[3] !== '-') break;
        }
        $code = (int)substr($resp, 0, 3);
        if (!in_array($code, $ok, true)) {
            throw new RuntimeException("{$label} reddedildi: " . trim($resp ?: 'yanıt yok'));
        }
        return $resp;
    }
}
