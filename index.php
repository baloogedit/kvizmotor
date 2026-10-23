<?php
// ======================= index.php (HTML váz) =======================
// A PHP csak a vázat és a CSRF tokent adja ki; a kérdések fetch-csel jönnek az API-ból.
declare(strict_types=1);
session_start();
$csrf = $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf" content="<?= htmlspecialchars($csrf) ?>">
  <title>Prythian-kvíz</title>
  <link rel="stylesheet" href="/style.css">
</head>
<body>
  <main>
    <h1>🌹 Prythian-kvíz</h1>
    <p class="alcim">Tüskék és rózsák udvara – mennyire ismered Feyre világát?</p>

    <div id="start">
      <p>10 véletlen kérdés az 50-ből, 5 perc, legfeljebb 3 próbálkozás. A pontozás a szerveren történik.</p>
      <button id="indit">Indítás</button>
    </div>

    <div id="fejlec" hidden>
      <span id="info"></span>
      <span id="ido">5:00</span>
    </div>

    <form id="kviz" hidden></form>

    <p id="uzenet" role="status"></p>
  </main>
  <script src="/app.js"></script>
</body>
</html>
