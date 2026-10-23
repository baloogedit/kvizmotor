<?php
// Egyszer kell lefuttatni: php init_db.php
// Létrehozza a kviz.sqlite fájlt a schema.sql alapján (táblák + ACOTAR kérdések).
// Újrakezdéshez: töröld a kviz.sqlite fájlt, és futtasd újra.
declare(strict_types=1);

$fajl = __DIR__ . '/kviz.sqlite';
if (file_exists($fajl)) {
    exit("A kviz.sqlite már létezik. Ha újra akarod kezdeni, előbb töröld.\n");
}

$pdo = new PDO('sqlite:' . $fajl, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));

echo "Kész\n";
