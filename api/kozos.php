<?php
// ======================= api/kozos.php =======================
// Közös segédfüggvények mindkét végponthoz (require-rel húzzuk be).
declare(strict_types=1);
session_start();

// Valós rendszerben ez a bejelentkezett felhasználó azonosítója lenne.
// Itt minden böngésző-session kap egy véletlen azonosítót.
$_SESSION['uid'] ??= bin2hex(random_bytes(8));

const TURELEM_MP = 10;                                      // türelmi idő a hálózati késés miatt

// --- Adatbázis-kapcsolat (egyszer jön létre, utána újrahasznosítjuk) ---
function db(): PDO {
    static $pdo = null;
    $pdo ??= new PDO('sqlite:' . __DIR__ . '/../kviz.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

// --- Egységes JSON válasz – az egyetlen kilépési pont ---
function valasz(int $kod, array $adat): never {
    http_response_code($kod);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');                      // a kvíz adatait ne cache-elje senki
    echo json_encode($adat, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

// --- Csak POST + érvényes CSRF token mehet tovább ---
function csak_post_csrffel(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        valasz(405, ['hiba' => 'Csak POST kérés engedélyezett.']);
    }
    if (!hash_equals($_SESSION['csrf'] ?? '', $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        valasz(403, ['hiba' => 'Érvénytelen vagy hiányzó CSRF token.']);
    }
}

// --- JSON-törzs beolvasása (üres törzs = üres tömb) ---
function json_torzs(): array {
    $nyers = (string)file_get_contents('php://input');
    if ($nyers === '') {
        return [];
    }
    try {
        $d = json_decode($nyers, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        valasz(400, ['hiba' => 'Érvénytelen JSON.']);
    }
    if (!is_array($d)) {
        valasz(400, ['hiba' => 'JSON objektumot várok.']);
    }
    return $d;
}
