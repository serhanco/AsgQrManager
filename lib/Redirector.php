<?php
/**
 * Redirector — bir linkin okutulduğunda nereye gideceğine karar verir.
 * Saf mantıktır (HTTP/DB yazımı yok), bu yüzden test edilebilir.
 *
 * Eski kayıtlarda yeni sütunlar NULL/yok olabilir; bu durumda davranış
 * eskisiyle aynıdır: aktifse hedefe git, pasifse 404.
 */
class Redirector {

    /** @return array{state:string} archived | inactive | scheduled | expired | live */
    public static function state(array $link, ?DateTimeImmutable $now = null): array {
        if (!empty($link['archived_at'])) return ['state' => 'archived'];
        if (empty($link['is_active']))    return ['state' => 'inactive'];

        $now  = $now ?? new DateTimeImmutable('now');
        $local = $now->setTimezone(Settings::timezone())->format('Y-m-d H:i:s');

        if (!empty($link['starts_at']) && $local < $link['starts_at']) return ['state' => 'scheduled'];
        if (!empty($link['ends_at'])   && $local >= $link['ends_at'])  return ['state' => 'expired'];
        return ['state' => 'live'];
    }

    /**
     * @return array{action:string,url:?string,outcome:string,state:string}
     *   action: redirect | notfound ; outcome: ok | after_end | fallback | dead
     */
    public static function resolve(array $link, ?DateTimeImmutable $now = null): array {
        $state = self::state($link, $now)['state'];

        if ($state === 'live') {
            return ['action' => 'redirect', 'url' => LinkService::applyUtm($link['target_url'], $link),
                    'outcome' => 'ok', 'state' => $state];
        }

        if ($state === 'expired' && !empty($link['after_end_url']) && isValidUrl($link['after_end_url'])) {
            return ['action' => 'redirect', 'url' => LinkService::applyUtm($link['after_end_url'], $link),
                    'outcome' => 'after_end', 'state' => $state];
        }

        // Pasif / arşiv / başlamamış / bitmiş: önce QR'a özel, sonra genel yedek hedef
        foreach ([$link['fallback_url'] ?? '', Settings::get('fallback_url')] as $fb) {
            if ($fb !== '' && $fb !== null && isValidUrl($fb)) {
                return ['action' => 'redirect', 'url' => $fb, 'outcome' => 'fallback', 'state' => $state];
            }
        }
        return ['action' => 'notfound', 'url' => null, 'outcome' => 'dead', 'state' => $state];
    }
}
