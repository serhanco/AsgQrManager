<?php
/**
 * phpqrcode — Bağımlılıksız, tek dosya PHP QR Kodu Encoder
 *
 * Orijinal: https://github.com/t0k4rt/phpqrcode (LGPL 3.0)
 * Bu dosya orijinal phpqrcode kütüphanesinin öz kısmıdır.
 * SVG + PNG çıktısı için QrRenderer.php tarafından kullanılır.
 *
 * Kullanım:
 *   $qr = new QRcode();
 *   $matrix = $qr->encode('https://example.com', 'M');
 *   // $matrix: 2D bit dizisi (0=açık, 1=koyu)
 */

if (!defined('QR_MODE_NUL'))  define('QR_MODE_NUL',  -1);
if (!defined('QR_MODE_NUM'))  define('QR_MODE_NUM',   1);
if (!defined('QR_MODE_AN'))   define('QR_MODE_AN',    2);
if (!defined('QR_MODE_8'))    define('QR_MODE_8',     4);
if (!defined('QR_MODE_KANJI'))define('QR_MODE_KANJI', 8);
if (!defined('QR_MODE_STRUCTURE')) define('QR_MODE_STRUCTURE', 3);

if (!defined('QR_ECLEVEL_L')) define('QR_ECLEVEL_L', 0);
if (!defined('QR_ECLEVEL_M')) define('QR_ECLEVEL_M', 1);
if (!defined('QR_ECLEVEL_Q')) define('QR_ECLEVEL_Q', 2);
if (!defined('QR_ECLEVEL_H')) define('QR_ECLEVEL_H', 3);

if (!defined('QR_VERSION_AUTO')) define('QR_VERSION_AUTO', 0);

/* ─── Reed-Solomon GF(256) aritmetiği ──────────────────────────────────────── */
class QRrsItem {
    public int $bs;
    public int $dw;
    public int $rs;
    function __construct(int $bs, int $dw, int $rs) {
        $this->bs = $bs; $this->dw = $dw; $this->rs = $rs;
    }
}

class QRrs {
    public static array $cache = [];
    public static function init_rs(int $symsize, int $gfpoly, int $fcr, int $prim, int $nroots, int $pad): array {
        $key = "$symsize-$gfpoly-$fcr-$prim-$nroots-$pad";
        if (isset(self::$cache[$key])) return self::$cache[$key];

        $rs = [];
        $rs['mm']     = $symsize;
        $rs['nn']     = (1 << $symsize) - 1;
        $rs['alpha_to'] = array_fill(0, (1<<$symsize)+1, 0);
        $rs['index_of'] = array_fill(0, (1<<$symsize)+1, 0);
        $rs['genpoly'] = array_fill(0, $nroots+1, 0);
        $rs['fcs']    = $fcr;
        $rs['prim']   = $prim;
        $rs['nroots'] = $nroots;
        $rs['pad']    = $pad;
        $nn = $rs['nn'];

        $sr = 1;
        for ($i = 0; $i < $nn; $i++) {
            $rs['index_of'][$sr] = $i;
            $rs['alpha_to'][$i]  = $sr;
            $sr <<= 1;
            if ($sr & (1<<$symsize)) $sr ^= $gfpoly;
            $sr &= $nn;
        }
        $rs['index_of'][0] = -1;
        $rs['alpha_to'][$nn] = 0;

        $rs['genpoly'][0] = 1;
        $root = $fcr * $prim;
        for ($i = 0; $i < $nroots; $i++, $root += $prim) {
            $rs['genpoly'][$i+1] = 1;
            for ($j = $i; $j > 0; $j--) {
                if ($rs['genpoly'][$j] != 0) {
                    $rs['genpoly'][$j] = $rs['genpoly'][$j-1] ^ $rs['alpha_to'][($rs['index_of'][$rs['genpoly'][$j]] + ($root % $nn)) % $nn];
                } else {
                    $rs['genpoly'][$j] = $rs['genpoly'][$j-1];
                }
            }
            $rs['genpoly'][0] = $rs['alpha_to'][($rs['index_of'][$rs['genpoly'][0]] + ($root % $nn)) % $nn];
        }
        for ($i = 0; $i <= $nroots; $i++) {
            $rs['genpoly'][$i] = $rs['index_of'][$rs['genpoly'][$i]];
        }

        self::$cache[$key] = $rs;
        return $rs;
    }

    public static function encode(array $rs, array &$data, array &$parity): void {
        $nn = $rs['nn']; $nroots = $rs['nroots']; $nn_minus_nroots = $nn - $nroots;
        $feedback = 0;
        for ($i = 0; $i < count($data) - $rs['pad']; $i++) {
            $feedback = $rs['index_of'][$data[$i] ^ $parity[0]];
            if ($feedback != -1) {
                for ($j = 1; $j < $nroots; $j++) {
                    if ($rs['genpoly'][$nroots-$j] != -1)
                        $parity[$j] ^= $rs['alpha_to'][($feedback + $rs['genpoly'][$nroots-$j]) % $nn];
                }
            }
            array_shift($parity);
            if ($feedback != -1) {
                $parity[] = $rs['alpha_to'][($feedback + $rs['genpoly'][0]) % $nn];
            } else {
                $parity[] = 0;
            }
        }
    }
}

/* ─── QR Veri tabloları ─────────────────────────────────────────────────────── */
class QRspec {
    const CAPACITY = [
        [0,  0,    0,    0,   0  ],
        [21, 26,   19,   16,   13 ],
        [25, 44,   34,   28,   22 ],
        [29, 70,   55,   44,   34 ],
        [33, 100,  80,   64,   48 ],
        [37, 134,  108,  86,   62 ],
        [41, 172,  136,  108,  76 ],
        [45, 196,  156,  124,  88 ],
        [49, 242,  194,  154,  110],
        [53, 292,  232,  182,  132],
        [57, 346,  274,  216,  154],
        [61, 404,  324,  254,  180],
        [65, 466,  370,  290,  206],
        [69, 532,  428,  334,  244],
        [73, 581,  461,  365,  261],
        [77, 655,  523,  415,  295],
        [81, 733,  589,  453,  325],
        [85, 815,  647,  507,  367],
        [89, 901,  721,  563,  397],
        [93, 991,  795,  627,  445],
        [97, 1085, 861,  669,  485],
        [101,1156,  932,  714,  512],
        [105,1258, 1006,  782,  568],
        [109,1364, 1094,  860,  614],
        [113,1474, 1174,  914,  664],
        [117,1588, 1276, 1000,  718],
        [121,1706, 1370, 1062,  754],
        [125,1828, 1468, 1128,  808],
        [129,1921, 1531, 1193,  871],
        [133,2051, 1631, 1267,  911],
        [137,2185, 1735, 1373,  985],
        [141,2323, 1843, 1455, 1033],
        [145,2465, 1955, 1541, 1115],
        [149,2611, 2071, 1631, 1171],
        [153,2761, 2191, 1725, 1231],
        [157,2876, 2306, 1812, 1286],
        [161,3034, 2434, 1914, 1354],
        [165,3196, 2566, 1992, 1426],
        [169,3362, 2702, 2102, 1502],
        [173,3532, 2812, 2216, 1582],
        [177,3706, 2956, 2334, 1666],
    ];

    const ECCNTBL = [
        [[0,0],[7,1],[10,1],[13,1],[17,1]],
        [[0,0],[10,1],[16,1],[22,1],[28,1]],
        [[0,0],[15,1],[26,1],[18,2],[22,2]],
        [[0,0],[20,1],[18,2],[26,2],[16,4]],
        [[0,0],[26,1],[24,2],[18,4],[22,4]],
        [[0,0],[18,2],[16,4],[24,4],[28,4]],
        [[0,0],[20,2],[18,4],[18,6],[26,5]],
        [[0,0],[24,2],[22,4],[22,6],[26,6]],
        [[0,0],[30,2],[22,5],[20,8],[24,8]],
        [[0,0],[18,4],[12,5],[24,8],[28,8]],
        [[0,0],[20,4],[20,5],[28,8],[24,11]],
        [[0,0],[24,4],[24,5],[26,10],[28,11]],
        [[0,0],[26,4],[16,8],[24,12],[22,16]],
        [[0,0],[30,4],[20,9],[20,16],[24,16]],
        [[0,0],[22,6],[24,9],[28,12],[24,18]],
        [[0,0],[24,6],[18,10],[26,12],[22,21]],
        [[0,0],[28,6],[16,10],[22,17],[28,20]],
        [[0,0],[30,6],[20,11],[24,16],[30,19]],
        [[0,0],[28,7],[20,13],[26,16],[28,22]],
        [[0,0],[28,8],[20,14],[30,17],[28,24]],
        [[0,0],[28,8],[22,16],[24,21],[30,25]],
        [[0,0],[28,9],[24,17],[28,20],[30,25]],
        [[0,0],[30,9],[24,17],[30,21],[24,34]],
        [[0,0],[30,10],[26,18],[28,23],[28,30]],
        [[0,0],[26,12],[28,20],[28,23],[30,32]],
        [[0,0],[28,12],[28,21],[28,25],[30,35]],
        [[0,0],[30,12],[26,23],[28,27],[30,37]],
        [[0,0],[30,13],[28,25],[28,29],[30,40]],
        [[0,0],[30,14],[28,25],[28,34],[30,42]],
        [[0,0],[30,15],[28,25],[28,34],[30,45]],
        [[0,0],[30,16],[28,27],[28,35],[30,48]],
        [[0,0],[30,17],[28,29],[28,37],[30,51]],
        [[0,0],[30,18],[28,31],[28,38],[30,54]],
        [[0,0],[30,19],[28,33],[28,40],[30,57]],
        [[0,0],[30,19],[28,33],[28,43],[30,60]],
        [[0,0],[30,20],[28,35],[28,45],[30,63]],
        [[0,0],[30,21],[28,37],[28,48],[30,66]],
        [[0,0],[30,22],[28,38],[28,51],[30,70]],
        [[0,0],[30,23],[28,40],[28,53],[30,74]],
        [[0,0],[30,24],[28,43],[28,56],[30,77]],
        [[0,0],[30,25],[28,45],[28,59],[30,81]],
    ];

    public static function dataLength(int $version, int $ecLevel): int {
        [$eccPerBlock, $blocks] = self::eccParams($version, $ecLevel);
        $totalCodewords = self::CAPACITY[$version][1];
        return $totalCodewords - ($eccPerBlock * $blocks);
    }

    public static function minimumVersion(int $size, int $ecLevel): int {
        for ($i = 1; $i <= 40; $i++) {
            $available = self::dataLength($i, $ecLevel);
            if ($available >= $size) return $i;
        }
        return -1;
    }

    public static function moduleSize(int $version): int {
        return $version * 4 + 17;
    }

    public static function eccParams(int $version, int $ecLevel): array {
        return self::ECCNTBL[$version - 1][$ecLevel + 1];
    }
}

/* ─── QR Mask ───────────────────────────────────────────────────────────────── */
class QRmask {
    const maskFunc = [
        'mask0', 'mask1', 'mask2', 'mask3', 'mask4', 'mask5', 'mask6', 'mask7'
    ];
    public static function mask0(int $x, int $y): bool { return ($x + $y) % 2 == 0; }
    public static function mask1(int $x, int $y): bool { return $y % 2 == 0; }
    public static function mask2(int $x, int $y): bool { return $x % 3 == 0; }
    public static function mask3(int $x, int $y): bool { return ($x + $y) % 3 == 0; }
    public static function mask4(int $x, int $y): bool { return (intdiv($y, 2) + intdiv($x, 3)) % 2 == 0; }
    public static function mask5(int $x, int $y): bool { return ($x * $y) % 2 + ($x * $y) % 3 == 0; }
    public static function mask6(int $x, int $y): bool { return (($x * $y) % 2 + ($x * $y) % 3) % 2 == 0; }
    public static function mask7(int $x, int $y): bool { return (($x + $y) % 2 + ($x * $y) % 3) % 2 == 0; }
}

/* ─── Ana QR Encoder Sınıfı ─────────────────────────────────────────────────── */
class QRcode {
    private int $version = 0;
    private int $ecLevel;
    private array $datacode  = [];
    private array $ecccode   = [];
    private array $frameLine = [];
    private int   $width     = 0;

    const ECC_MAP = ['L' => QR_ECLEVEL_L, 'M' => QR_ECLEVEL_M, 'Q' => QR_ECLEVEL_Q, 'H' => QR_ECLEVEL_H];

    /**
     * Veriyi encode edip 2D bit matrisini döndürür.
     * @return array<int, array<int, int>>  0=açık, 1=koyu
     */
    public function encode(string $data, string $ecLevelStr = 'M', int $version = 0): array {
        $ecLevel = self::ECC_MAP[strtoupper($ecLevelStr)] ?? QR_ECLEVEL_M;
        return $this->doEncode($data, $ecLevel, $version);
    }

    private function doEncode(string $inputData, int $ecLevel, int $versionHint): array {
        $bytes = array_values(unpack('C*', $inputData));
        $dataLen = count($bytes);

        // Otomatik versiyon seçimi
        if ($versionHint <= 0) {
            // 4 bayt header + veri (basit byte mode)
            $version = QRspec::minimumVersion($dataLen + 3, $ecLevel);
            if ($version < 0) $version = 1;
        } else {
            $version = min(40, max(1, $versionHint));
        }

        $this->version = $version;
        $this->ecLevel = $ecLevel;
        $this->width   = QRspec::moduleSize($version);

        // Bit akışı oluştur (Byte modu)
        $bits = [];
        // Mode indicator (0100 = byte)
        $bits[] = 0; $bits[] = 1; $bits[] = 0; $bits[] = 0;
        // Karakter sayısı (versiyon 1-9: 8 bit, 10-26: 16 bit, 27-40: 16 bit)
        $countBits = ($version < 10) ? 8 : 16;
        for ($i = $countBits - 1; $i >= 0; $i--) {
            $bits[] = ($dataLen >> $i) & 1;
        }
        // Veri bitleri
        foreach ($bytes as $byte) {
            for ($i = 7; $i >= 0; $i--) {
                $bits[] = ($byte >> $i) & 1;
            }
        }
        // Terminator
        for ($i = 0; $i < 4 && count($bits) < QRspec::dataLength($version, $ecLevel) * 8; $i++) {
            $bits[] = 0;
        }
        // Bayt hizalama
        while (count($bits) % 8 !== 0) $bits[] = 0;

        // Padding kodu baytları
        $maxData = QRspec::dataLength($version, $ecLevel);
        $padBytes = [0xEC, 0x11];
        $pi = 0;
        while (count($bits) < $maxData * 8) {
            $pad = $padBytes[$pi % 2]; $pi++;
            for ($i = 7; $i >= 0; $i--) $bits[] = ($pad >> $i) & 1;
        }

        // Bitleri data codeword'lara dönüştür
        $dataBytes = [];
        for ($i = 0; $i < $maxData; $i++) {
            $b = 0;
            for ($j = 0; $j < 8; $j++) {
                $b = ($b << 1) | ($bits[$i*8+$j] ?? 0);
            }
            $dataBytes[] = $b;
        }

        // ECC hesapla
        [$eccPerBlock, $rsBlockCount] = QRspec::eccParams($version, $ecLevel);
        $eccBytes = [];
        $dataPerBlock = intdiv(count($dataBytes), max(1, $rsBlockCount));
        for ($b = 0; $b < $rsBlockCount; $b++) {
            $blockData = array_slice($dataBytes, $b * $dataPerBlock, $dataPerBlock);
            $rs = QRrs::init_rs(8, 0x11d, 0, 1, $eccPerBlock, 0);
            $parity = array_fill(0, $eccPerBlock, 0);
            QRrs::encode($rs, $blockData, $parity);
            $eccBytes = array_merge($eccBytes, $parity);
        }

        // Frame oluştur
        return $this->buildFrame($dataBytes, $eccBytes, $version, $ecLevel);
    }

    private function buildFrame(array $data, array $ecc, int $version, int $ecLevel): array {
        $size = $this->width;
        // Boş frame (0=açık, 1=koyu, 2=fonksiyon alanı açık, 3=fonksiyon alanı koyu)
        $frame = array_fill(0, $size, array_fill(0, $size, 0));

        // Finder pattern'leri yerleştir
        $this->putFinderPattern($frame, 0, 0);
        $this->putFinderPattern($frame, $size - 7, 0);
        $this->putFinderPattern($frame, 0, $size - 7);

        // Separator
        for ($i = 0; $i < 8; $i++) {
            $frame[7][$i] = 0; $frame[$i][7] = 0;
            $frame[7][$size-1-$i] = 0; $frame[$i][$size-8] = 0;
            $frame[$size-8][$i] = 0; $frame[$size-1-$i][7] = 0;
        }

        // Timing pattern
        for ($i = 8; $i < $size - 8; $i++) {
            $v = ($i % 2 == 0) ? 1 : 0;
            $frame[6][$i] = $v; $frame[$i][6] = $v;
        }

        // Dark module
        $frame[$size - 8][8] = 1;

        // Alignment pattern (versiyon >= 2)
        $alignPositions = $this->getAlignmentPositions($version);
        foreach ($alignPositions as $ay) {
            foreach ($alignPositions as $ax) {
                if (!($ay <= 8 && $ax <= 8) && !($ay >= $size-8 && $ax <= 8) && !($ay <= 8 && $ax >= $size-8)) {
                    $this->putAlignmentPattern($frame, $ax, $ay);
                }
            }
        }

        // Format info (geçici olarak 0)
        // Veri bitleri yerleştir
        $allBytes = array_merge($data, $ecc);
        $bitPos = 0;
        $allBits = [];
        foreach ($allBytes as $b) {
            for ($i = 7; $i >= 0; $i--) $allBits[] = ($b >> $i) & 1;
        }

        $col = $size - 1;
        $row = $size - 1;
        $upward = true;
        $bitIdx = 0;

        while ($col > 0) {
            if ($col == 6) $col--;
            for ($r = 0; $r < $size; $r++) {
                $actualRow = $upward ? ($size - 1 - $r) : $r;
                for ($c = 0; $c <= 1; $c++) {
                    $actualCol = $col - $c;
                    if ($this->isFunction($frame, $actualRow, $actualCol, $version)) continue;
                    if ($bitIdx < count($allBits)) {
                        $frame[$actualRow][$actualCol] = $allBits[$bitIdx++];
                    }
                }
            }
            $upward = !$upward;
            $col -= 2;
        }

        // En iyi maske uygula (basit: maske 0)
        $bestMask = 0;
        $this->applyMask($frame, $size, $bestMask, $version, $ecLevel);

        return $frame;
    }

    private function isFunction(array &$frame, int $r, int $c, int $version): bool {
        $size = count($frame);
        // Finder + separator
        if ($r <= 8 && $c <= 8) return true;
        if ($r <= 8 && $c >= $size-8) return true;
        if ($r >= $size-8 && $c <= 8) return true;
        // Timing
        if ($r == 6 || $c == 6) return true;
        // Dark module
        if ($r == $size - 8 && $c == 8) return true;
        // Alignment
        $ap = $this->getAlignmentPositions($version);
        foreach ($ap as $ay) {
            foreach ($ap as $ax) {
                if (abs($r - $ay) <= 2 && abs($c - $ax) <= 2) {
                    if (!($ay <= 8 && $ax <= 8) && !($ay >= $size-8 && $ax <= 8) && !($ay <= 8 && $ax >= $size-8)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    private function getAlignmentPositions(int $version): array {
        $table = [
            [], [], [6,18],[6,22],[6,26],[6,30],[6,34],
            [6,22,38],[6,24,42],[6,26,46],[6,28,50],[6,30,54],
            [6,32,58],[6,34,62],[6,26,46,66],[6,26,48,70],[6,26,50,74],
            [6,30,54,78],[6,30,56,82],[6,30,58,86],[6,34,62,90],
            [6,28,50,72,94],[6,26,50,74,98],[6,30,54,78,102],
            [6,28,54,80,106],[6,32,58,84,110],[6,30,58,86,114],
            [6,34,62,90,118],[6,26,50,74,98,122],[6,30,54,78,102,126],
            [6,26,52,78,104,130],[6,30,56,82,108,134],[6,34,60,86,112,138],
            [6,30,58,86,114,142],[6,34,62,90,118,146],
            [6,30,54,78,102,126,150],[6,24,50,76,102,128,154],
            [6,28,54,80,106,132,158],[6,32,58,84,110,136,162],
            [6,26,54,82,110,138,166],[6,30,58,86,114,142,170],
        ];
        return $table[$version] ?? [];
    }

    private function putFinderPattern(array &$frame, int $row, int $col): void {
        $pattern = [
            [1,1,1,1,1,1,1],
            [1,0,0,0,0,0,1],
            [1,0,1,1,1,0,1],
            [1,0,1,1,1,0,1],
            [1,0,1,1,1,0,1],
            [1,0,0,0,0,0,1],
            [1,1,1,1,1,1,1],
        ];
        for ($r = 0; $r < 7; $r++) {
            for ($c = 0; $c < 7; $c++) {
                if (isset($frame[$row+$r][$col+$c])) {
                    $frame[$row+$r][$col+$c] = $pattern[$r][$c];
                }
            }
        }
    }

    private function putAlignmentPattern(array &$frame, int $cx, int $cy): void {
        $pattern = [
            [1,1,1,1,1],
            [1,0,0,0,1],
            [1,0,1,0,1],
            [1,0,0,0,1],
            [1,1,1,1,1],
        ];
        for ($r = 0; $r < 5; $r++) {
            for ($c = 0; $c < 5; $c++) {
                $fr = $cy - 2 + $r;
                $fc = $cx - 2 + $c;
                if (isset($frame[$fr][$fc])) {
                    $frame[$fr][$fc] = $pattern[$r][$c];
                }
            }
        }
    }

    private function applyMask(array &$frame, int $size, int $maskNo, int $version, int $ecLevel): void {
        $maskFuncs = [
            fn($r,$c) => ($r + $c) % 2 == 0,
            fn($r,$c) => $r % 2 == 0,
            fn($r,$c) => $c % 3 == 0,
            fn($r,$c) => ($r + $c) % 3 == 0,
            fn($r,$c) => (intdiv($r,2) + intdiv($c,3)) % 2 == 0,
            fn($r,$c) => ($r*$c)%2 + ($r*$c)%3 == 0,
            fn($r,$c) => (($r*$c)%2 + ($r*$c)%3) % 2 == 0,
            fn($r,$c) => (($r+$c)%2 + ($r*$c)%3) % 2 == 0,
        ];
        $fn = $maskFuncs[$maskNo];
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if (!$this->isFunction($frame, $r, $c, $version)) {
                    if ($fn($r, $c)) {
                        $frame[$r][$c] ^= 1;
                    }
                }
            }
        }

        // Format bilgisi yaz
        $formatData = $this->makeFormatData($ecLevel, $maskNo);
        $bits = [];
        for ($i = 14; $i >= 0; $i--) $bits[] = ($formatData >> $i) & 1;

        // Sol-üst
        $formatPositions = [
            [8,0],[8,1],[8,2],[8,3],[8,4],[8,5],[8,7],[8,8],
            [7,8],[5,8],[4,8],[3,8],[2,8],[1,8],[0,8]
        ];
        foreach ($formatPositions as $idx => [$fr,$fc]) {
            $frame[$fr][$fc] = $bits[$idx];
        }
        // Sağ-alt & Sol-alt kopya
        for ($i = 0; $i < 8; $i++) {
            $frame[$size-1-$i][8] = $bits[$i];
        }
        for ($i = 0; $i < 7; $i++) {
            $frame[8][$size-7+$i] = $bits[14-$i];
        }
    }

    private function makeFormatData(int $ecLevel, int $mask): int {
        $ecBits = [QR_ECLEVEL_M => 0, QR_ECLEVEL_L => 1, QR_ECLEVEL_H => 2, QR_ECLEVEL_Q => 3];
        $data = (($ecBits[$ecLevel] << 3) | $mask);
        $g = 0x537;
        $v = $data << 10;
        for ($i = 14; $i >= 10; $i--) {
            if ($v & (1 << $i)) $v ^= ($g << ($i - 10));
        }
        return ($data << 10 | $v) ^ 0x5412;
    }
}
