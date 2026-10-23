// ======================= app.js (frontend) =======================
// FONTOS: itt NINCS semmi a helyes válaszokról – a böngésző csak kérdést és opciót kap.
const CSRF = document.querySelector('meta[name="csrf"]').content;
const $ = (s) => document.querySelector(s);

let token = null;     // az aktuális kitöltés azonosítója
let orajel = null;    // a visszaszámláló setInterval azonosítója

// Egyetlen segédfüggvény minden API-híváshoz (mint a teendőlistánál).
async function api(url, adat) {
  const res = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: adat === undefined ? undefined : JSON.stringify(adat),
  });
  const json = await res.json();
  if (!res.ok) throw new Error(json.hiba ?? ('HTTP ' + res.status));
  return json;
}

function uzenet(szoveg, tipus = '') {
  const p = $('#uzenet');
  p.textContent = szoveg;
  p.className = tipus;
}

// --- Visszaszámláló: CSAK kijelzés! Az igazi időt a szerver méri. ---
function visszaszamlal(mp) {
  const vege = Date.now() + mp * 1000;
  clearInterval(orajel);
  const frissit = () => {
    const maradt = Math.max(0, Math.round((vege - Date.now()) / 1000));
    $('#ido').textContent = Math.floor(maradt / 60) + ':' + String(maradt % 60).padStart(2, '0');
    $('#ido').classList.toggle('keves', maradt <= 30);
    if (maradt === 0) {
      clearInterval(orajel);
      $('#kviz').requestSubmit();                     // lejárt: beadjuk, amit eddig jelölt
    }
  };
  frissit();
  orajel = setInterval(frissit, 1000);
}

// --- Kérdések kirajzolása (textContent → XSS-biztos) ---
function kirajzol(kerdesek) {
  const f = $('#kviz');
  f.replaceChildren();
  kerdesek.forEach((k, i) => {
    const fs = document.createElement('fieldset');
    fs.dataset.k = k.id;
    const lg = document.createElement('legend');
    lg.textContent = (i + 1) + '. ' + k.szoveg + ' (' + k.pont + ' pont)';
    fs.append(lg);
    k.opciok.forEach((o) => {
      const l = document.createElement('label');
      const inp = document.createElement('input');
      inp.type = k.tobbszoros ? 'checkbox' : 'radio';
      inp.name = 'k' + k.id;
      inp.value = o.id;
      inp.dataset.k = k.id;
      l.append(inp, ' ' + o.szoveg);
      fs.append(l);
    });
    f.append(fs);
  });
  const b = document.createElement('button');
  b.textContent = 'Beadás';
  f.append(b);
  f.hidden = false;
}

// --- Indítás ---
$('#indit').addEventListener('click', async () => {
  try {
    const d = await api('/api/inditas.php?kviz=1');
    token = d.kitoltes;
    $('#start').hidden = true;
    $('#fejlec').hidden = false;
    $('#info').textContent = d.probalkozas + '. próbálkozás / ' + d.max_probalkozas;
    uzenet('');
    kirajzol(d.kerdesek);
    visszaszamlal(d.idokorlat_mp);
  } catch (e) {
    uzenet(e.message, 'hiba');
  }
});

// --- Beadás ---
$('#kviz').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  clearInterval(orajel);
  const valaszok = {};                                // pl. { "5": [17, 18], "1": [1] }
  ev.target.querySelectorAll('input:checked').forEach((i) => {
    (valaszok[i.dataset.k] ??= []).push(Number(i.value));
  });
  ev.target.querySelectorAll('input, button').forEach((el) => (el.disabled = true));
  try {
    const d = await api('/api/beadas.php?token=' + token, { valaszok });
    // a szerver kérdésenként megmondja, jó volt-e – a helyes opciót viszont nem árulja el
    d.eredmeny.forEach((r) => {
      $('fieldset[data-k="' + r.kerdes_id + '"]').classList.add(r.helyes ? 'jo' : 'rossz');
    });
    uzenet('Eredmény: ' + d.pont + ' / ' + d.max + ' pont (' + d.eltelt_mp + ' mp alatt)', 'siker');
  } catch (e) {
    uzenet(e.message, 'hiba');
  }
  $('#start').hidden = false;                         // újra lehet indítani (amíg van próbálkozás)
  $('#indit').textContent = 'Új próbálkozás';
});
