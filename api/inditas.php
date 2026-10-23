<?php
// ======================= api/inditas.php =======================
// POST /api/inditas.php?kviz=1  → új kitöltés indítása
// Válasz: 201 + token + kerdes_db darab VÉLETLEN kérdés és opciói – a helyes válaszok NÉLKÜL.
declare(strict_types=1);
require __DIR__ . '/kozos.php';

csak_post_csrffel();

$kvizId = filter_var($_GET['kviz'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$kvizId) {
    valasz(400, ['hiba' => 'Hiányzó vagy hibás kviz paraméter.']);
}

$st = db()->prepare('SELECT id, cim, idokorlat_mp, max_probalkozas, kerdes_db FROM kvizek WHERE id = ?');
$st->execute([$kvizId]);
$kviz = $st->fetch();
if (!$kviz) {
    valasz(404, ['hiba' => 'Nincs ilyen kvíz.']);
}

// --- Próbálkozások száma: a szerver számolja, nem a kliens ---
$st = db()->prepare('SELECT COUNT(*) FROM kitoltesek WHERE kviz_id = ? AND felhasznalo = ?');
$st->execute([$kviz['id'], $_SESSION['uid']]);
$eddig = (int)$st->fetchColumn();
if ($eddig >= (int)$kviz['max_probalkozas']) {
    valasz(403, ['hiba' => 'Elfogytak a próbálkozásaid (' . $kviz['max_probalkozas'] . ').']);
}

// --- Sorsolás: kerdes_db darab véletlen kérdés az összesből (pl. 10 az 50-ből) ---
// Adatminimalizálás: a "helyes" oszlopot SZÁNDÉKOSAN le sem kérdezzük.
$kq = db()->prepare('SELECT id, szoveg, pont, tobbszoros FROM kerdesek WHERE kviz_id = ? ORDER BY RANDOM() LIMIT ?');
$kq->execute([$kviz['id'], (int)$kviz['kerdes_db']]);
$kisorsolt = $kq->fetchAll();

// --- Kitöltés + a kisorsolt kérdések rögzítése egy tranzakcióban; a kezdés idejét a SZERVER írja ---
$token = bin2hex(random_bytes(16));                         // 32 hex karakter, kitalálhatatlan
db()->beginTransaction();
db()->prepare('INSERT INTO kitoltesek (token, kviz_id, felhasznalo, kezdes) VALUES (?, ?, ?, ?)')
    ->execute([$token, $kviz['id'], $_SESSION['uid'], gmdate('Y-m-d H:i:s')]);
$kitoltesId = (int)db()->lastInsertId();
$kk = db()->prepare('INSERT INTO kitoltes_kerdesek (kitoltes_id, kerdes_id) VALUES (?, ?)');
foreach ($kisorsolt as $k) {
    $kk->execute([$kitoltesId, $k['id']]);
}
db()->commit();

$oq = db()->prepare('SELECT id, szoveg FROM opciok WHERE kerdes_id = ?');   // nincs "helyes"!

$kerdesek = [];
foreach ($kisorsolt as $k) {
    $oq->execute([$k['id']]);
    $opciok = array_map(fn($o) => ['id' => (int)$o['id'], 'szoveg' => $o['szoveg']], $oq->fetchAll());
    shuffle($opciok);                                       // minden kitöltésnél más sorrend
    $kerdesek[] = [
        'id'         => (int)$k['id'],
        'szoveg'     => $k['szoveg'],
        'pont'       => (int)$k['pont'],
        'tobbszoros' => (bool)$k['tobbszoros'],
        'opciok'     => $opciok,
    ];
}

header('Location: /api/beadas.php?token=' . $token);
valasz(201, [
    'kitoltes'     => $token,
    'cim'          => $kviz['cim'],
    'idokorlat_mp' => (int)$kviz['idokorlat_mp'],
    'probalkozas'  => $eddig + 1,
    'max_probalkozas' => (int)$kviz['max_probalkozas'],
    'kerdesek'     => $kerdesek,
]);
