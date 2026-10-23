<?php
// ======================= api/beadas.php =======================
// POST /api/beadas.php?token=...   törzs: {"valaszok": {"1": [1], "5": [17, 18], ...}}
// Ellenőrzések sorrendje: létezik? (404) → kié? (403) → beadták már? (409) → időn belül? (422)
// → opciók érvényesek? (422) → pontozás → atomi lezárás (409, ha közben beadták)
declare(strict_types=1);
require __DIR__ . '/kozos.php';

csak_post_csrffel();

$token = (string)($_GET['token'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
    valasz(400, ['hiba' => 'Hibás token.']);
}
$d = json_torzs();

// --- 1. Létezik? Kié? Beadták már? ---
$st = db()->prepare('SELECT kt.id, kt.kviz_id, kt.felhasznalo, kt.kezdes, kt.beadva, kv.idokorlat_mp
                     FROM kitoltesek kt JOIN kvizek kv ON kv.id = kt.kviz_id
                     WHERE kt.token = ?');
$st->execute([$token]);
$k = $st->fetch();
if (!$k) {
    valasz(404, ['hiba' => 'Nincs ilyen kitöltés.']);
}
if (!hash_equals($k['felhasznalo'], $_SESSION['uid'])) {
    valasz(403, ['hiba' => 'Ez nem a te kitöltésed.']);
}
if ($k['beadva'] !== null) {
    valasz(409, ['hiba' => 'Ezt a kitöltést már beadtad.']);
}

// --- 2. Időkorlát: a kezdést a szerver írta, a kliens "eltelt ideje" nem számít ---
$eltelt = time() - (int)strtotime($k['kezdes'] . ' UTC');
if ($eltelt > (int)$k['idokorlat_mp'] + TURELEM_MP) {
    db()->prepare('UPDATE kitoltesek SET beadva = ?, pont = 0 WHERE id = ? AND beadva IS NULL')
        ->execute([gmdate('Y-m-d H:i:s'), $k['id']]);
    valasz(422, ['hiba' => 'Lejárt az idő – a kitöltés 0 ponttal lezárva.', 'eltelt_mp' => $eltelt]);
}

$valaszok = $d['valaszok'] ?? null;
if (!is_array($valaszok)) {
    valasz(422, ['hiba' => 'A valaszok mező kötelező (objektum).']);
}

// --- 3. Érvényes és helyes opciók – CSAK az ebben a kitöltésben kisorsolt kérdésekhez ---
// Ez az adat csak itt, a szerveren él.
$st = db()->prepare('SELECT o.id, o.kerdes_id, o.helyes, q.pont
                     FROM kitoltes_kerdesek kk
                     JOIN kerdesek q ON q.id = kk.kerdes_id
                     JOIN opciok o ON o.kerdes_id = q.id
                     WHERE kk.kitoltes_id = ?');
$st->execute([$k['id']]);
$ervenyes = $helyes = $pontok = [];
foreach ($st as $r) {
    $kid = (int)$r['kerdes_id'];
    $ervenyes[$kid][] = (int)$r['id'];
    if ((int)$r['helyes'] === 1) {
        $helyes[$kid][] = (int)$r['id'];
    }
    $pontok[$kid] = (int)$r['pont'];
}

// Olyan kérdésre, amit ez a kitöltés nem kapott meg, nem lehet választ küldeni.
foreach (array_keys($valaszok) as $kid) {
    if (!isset($pontok[(int)$kid])) {
        valasz(422, ['hiba' => "A(z) $kid. kérdés nem szerepel ebben a kitöltésben."]);
    }
}

// --- 4. Pontozás halmaz-összehasonlítással ---
// Egy kérdés akkor jó, ha PONTOSAN a helyes opciókat jelölte (se több, se kevesebb).
$osszes = 0;
$eredmeny = $mentendo = [];
foreach ($pontok as $kid => $p) {
    $v = array_values(array_unique(array_map('intval', (array)($valaszok[$kid] ?? []))));
    if (array_diff($v, $ervenyes[$kid])) {                  // másik kérdés opciója → csalási kísérlet
        valasz(422, ['hiba' => "Érvénytelen opció a(z) $kid. kérdésnél."]);
    }
    sort($v);
    $h = $helyes[$kid] ?? [];
    sort($h);
    $jo = ($v === $h);
    $osszes += $jo ? $p : 0;
    $eredmeny[] = ['kerdes_id' => $kid, 'helyes' => $jo, 'pont' => $jo ? $p : 0, 'max' => $p];
    foreach ($v as $oid) {
        $mentendo[] = [$k['id'], $kid, $oid];
    }
}

// --- 5. Atomi lezárás: csak akkor sikerül, ha még senki nem adta be ---
db()->beginTransaction();
$u = db()->prepare('UPDATE kitoltesek SET beadva = ?, pont = ? WHERE id = ? AND beadva IS NULL');
$u->execute([gmdate('Y-m-d H:i:s'), $osszes, $k['id']]);
if ($u->rowCount() !== 1) {                                 // közben egy másik fülről beadták
    db()->rollBack();
    valasz(409, ['hiba' => 'Ezt a kitöltést már beadtad.']);
}
$be = db()->prepare('INSERT INTO valaszok (kitoltes_id, kerdes_id, opcio_id) VALUES (?, ?, ?)');
foreach ($mentendo as $sor) {
    $be->execute($sor);
}
db()->commit();

valasz(200, ['pont' => $osszes, 'max' => array_sum($pontok), 'eltelt_mp' => $eltelt, 'eredmeny' => $eredmeny]);
