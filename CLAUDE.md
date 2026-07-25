# Hinweise für die Arbeit an diesem Repository

## Sprachregel: alles Nutzersichtbare auf Deutsch

Verbindliche Verbund-Regel (angeordnet von Dietmar am 22.07.2026, gilt für alle Module des
**NRG-Stack** — der Produktname des DG65-Modulverbunds): **keine englischen Sätze/Ausdrücke und
keine vermeidbaren Anglizismen** in allem, was der Nutzer zu sehen bekommt.

Betroffen sind:

- Formularbeschriftungen (`caption` in `form.json`), Hinweis- und Warntexte, Bestätigungsdialoge
- Fehler- und Statusmeldungen, Rückgabe-Texte (z. B. `reason`-Felder eines Ergebnis-Arrays)
- `SendDebug`-/`LogMessage`-Ausgaben, Variablen- und Profilnamen
- Texte in der Kachel (`StromGedachtTile/module.html`), README, CHANGELOG

Vermeidbare Anglizismen ersetzen: Dry-Run → Probelauf, Link → Verknüpfung, Event → Ereignis,
Button → Schaltfläche, Checkliste → Prüfliste, Scan/scannen → Suche/suchen,
Token → Zugangsschlüssel.

**Faustregel:** eindeutschen, wo es das Verständnis verbessert; stehen lassen, wo der englische
Begriff der Fachbegriff oder ein Produktname **ist**. Hier stehen bleiben daher u. a.:
GrünstromIndex, Energy-Charts, StromGedacht (Produktnamen), API, HTTP, Debug (so heißt das
Fenster in der IPS-Konsole), WebFront.

**Beim Ersetzen die Grammatik prüfen** — ein Wort-für-Wort-Ersatz bricht Sätze, sobald sich das
Genus ändert (aus „der Button" wird „**die** Schaltfläche", nicht „der Schaltfläche"). Also nie
blind `replace_all`, sondern den Diff durchsehen.

**Ausgenommen** (bleibt englisch, weil Umbenennen Verträge bricht):

- Bezeichner im Code: Klassen-, Methoden-, Variablen-, Property- und vor allem **Ident-Namen**.
  Konkret hier: `State`, `GSI`, `ECSignal`, `ECShare`, `Text`, `Updated`, `Widget`.
  **Idents sind API und werden nie umbenannt** (Verbund-Konvention).
- Feststehende IP-Symcon-/Technikbegriffe (`SelectVariable`, `WebFront`, Modbus TCP, HTMLBox …)
  und Formularelement-Typen (`"type": "Button"` ist ein Bezeichner, kein Anzeigetext).
- API-Feldnamen der Gegenstelle (`state`, `forecast`, `unix_seconds`, `signal`, `share` …).
- Der Modulname „StromGedacht Widget" selbst (Store-Name, Vertrag).

Für den geplanten `SGW_GetState()`-Vertrag heißt das: Feldnamen englisch
(`state`, `label`, `gsi`, `ecSignal`, `ecShare`, `updated`), das `label`-**Feld** aber mit
deutschem Anzeigetext füllen.

## Vertragsversionierung (Verbund-Konvention, 23.07.2026)

Manifest: https://github.com/DG65/EMS/blob/main/SUITE.md

- **Modul-Version** bleibt unser SemVer (Datei `library.json`/`module.json`).
- **Vertragsversion:** Sobald `SGW_GetState()`/`SGW_GetForecast()` gebaut werden, liefern sie von
  Anfang an ein Feld `contractVersion` => `'1.0'` (Major.Minor als String). **Major nur bei einem
  echten Bruch** erhöhen — Kompatibilität wird nur innerhalb derselben Major garantiert
  (blue'Log-Prinzip). Fehlt das Feld beim Konsumenten, gilt `'1.0'`.
- **Konsument** (EMS/InverterHub) prüft die Mindest-Major; bei Inkompatibilität läuft er
  standalone weiter, deaktiviert die Kopplung und meldet das **sichtbar**.

## Emojis

Verbund-Regel (Entscheidung Dietmar 23.07.2026, ersetzt jede frühere „keine Emojis"-Vorgabe):
**Emojis sind erwünscht, wo sie Nutzen stiften.** Zwei Einsatzzwecke:

1. **Panel-Icon** — ein Zeichen am Anfang einer ExpansionPanel-Überschrift (Ersatz fürs fehlende
   `icon`-Feld). Hier bereits genutzt: 📖 Dokumentation & Hilfe, 🎨 Ampelfarben, 🖌️ Flächen & Text,
   🔤 Schrift.
2. **Status-/Aufmerksamkeitssymbol** (✅❌⚠️💡ℹ️ sowie unsere Ampel-Darstellungen 🔄 usw.) dort,
   wo etwas beim Lesen Aufmerksamkeit erfordert oder herausgestellt werden soll.

Kein Symcon-Store-Review hat Emojis je beanstandet. **Beobachtungsklausel:** sollte ein
Stable-Review sie doch bemängeln, entscheidet der Verbund neu (Rückfall: gemeinsam emoji-frei).

## Formular-Konvention (NRG-Stack-Optik, 24.07.2026, Referenz InverterHub)

Aufbau von oben nach unten in `GetConfigurationForm()`:

1. **„🆕 Neu in Version X.Y"** — `ExpansionPanel` `name: NewsPanel`, aufgeklappt, ganz oben
   (`array_unshift`). Inhalt aus `private const NEWS_ITEMS` des jeweiligen Moduls. Dismissible
   **pro Version** über Attribut `SeenNews` (`SGW_AckNews`/`SGWTILE_AckNews`) — erscheint bei
   jeder neuen `MODULE_VERSION` wieder, keine Versionsnummer im Panel-Titel selbst.
2. **„📖 Dokumentation & Hilfe"** — `ExpansionPanel` `name: DocPanel`, eingeklappt. Hier steht die
   Versionsnummer im Titel (`… (v1.5.0)`), wird in `GetConfigurationForm()` gepatcht.
3. Fachpanels. Neue/wichtige Felder bekommen `🆕` als Präfix im Label.
4. Symcon-Forum-Hinweis (`ReviewHint`) — am Ende der Elemente-Liste (`$form['elements'][] = …`),
   dismissible einmalig (nicht pro Version).

**Pflegepflicht bei JEDEM Fix/Update, nicht nur großen Releases** (Dietmar, 24.07.2026): Bei
jeder Änderung prüfen, ob `MODULE_VERSION`/`NEWS_ITEMS` etwas brauchen und ob ein neues Feld ein
`🆕` verdient — das Ergebnis darf „nein" sein, aber die Prüfung muss jedes Mal stattfinden, sonst
zeigt das News-Panel nichts oder Veraltetes an (`MODULE_VERSION` synct nicht automatisch mit
`module.json`/`library.json`).

**Layout-Qualität generell** (Dietmar, 24.07.2026): logische Gruppierung zusammengehöriger
Felder, Step-by-Step-Reihenfolge ohne Scroll-Zickzack (kein Hin- und Herspringen zwischen weit
auseinanderliegenden Formularteilen für einen zusammenhängenden Vorgang), Feldkanten sauber auf
einer Linie statt kreuz und quer. Details siehe SUITE.md.

## Zweige

- `ems-integration` — **aktueller Arbeitszweig, ab 25.07.2026 (Verschärfung Dietmar) AUSNAHMSLOS
  für alle Pushes**, solange die EMS-Integrationsphase läuft — keine Ausnahme mehr für
  vermeintlich sichere Fixes auf `beta`. Verbundweiter Zweig (identischer Name in allen
  Modul-Repos), ursprünglich von `beta` abgezweigt. Gilt bis Dietmar explizit etwas anderes sagt.
- `beta` — Entwicklung und schnelle Auslieferung an Dietmar und seinen Testerkreis. **Während der
  Integrationsphase NICHT direkt bepushen** (siehe oben) — wird später von `ems-integration`
  nachgezogen, sobald sich der Stand bewährt hat.
- `main` — geprüfter, stabiler Stand (Standardzweig). Nur bewusst dorthin veröffentlichen.
- `master` existiert nicht mehr (am 22.07.2026 repo-übergreifend entfernt).

## Prüfen vor dem Commit

```bash
php -l StromGedachtWidget/module.php && php -l StromGedachtTile/module.php
python3 -m json.tool StromGedachtWidget/form.json > /dev/null
python3 -m json.tool StromGedachtTile/form.json > /dev/null
php tests/smoke.php
```

Der Smoke-Test ruft die echten APIs auf und kann bei Netz-/API-Ausfällen transient
fehlschlagen — bei Fehlern erst ein zweites Mal laufen lassen, bevor man Ursachenforschung
betreibt. `api.stromgedacht.de` ist aus Dietmars Netz nur über IPv4 erreichbar (das Modul hat
dafür einen Fallback).

## Verbund-Regeln (Kurzfassung)

- **Eigenständigkeit:** Das Modul muss ohne jedes andere Modul voll lauffähig sein. Jeder Aufruf
  eines fremden Modulpräfixes gehört hinter `function_exists()` — ein Aufruf einer undefinierten
  Funktion ist in PHP ein **Fatal Error**, ein vorangestelltes `@` unterdrückt ihn **nicht**.
  (Derzeit ruft dieses Modul keine fremden Präfixe auf.)
- **Ein Regler pro Stellgröße:** Steuernd schreibt nur das EMS. Die Wenn→Dann-Automationen dieses
  Moduls sind für den Betrieb ohne EMS gedacht; sie dürfen nicht parallel zum EMS dieselbe
  Stellgröße schreiben.
- **Verträge:** Veröffentlichte `SGW_*`-Funktionen werden nie umbenannt, nur additiv erweitert.
- **Fremde Repos:** Keine Änderungen ohne Absprache; Koordination läuft über Dietmar.
