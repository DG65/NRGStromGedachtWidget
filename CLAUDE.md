# Hinweise für die Arbeit an diesem Repository

## Sprachregel: zweisprachig Englisch/Deutsch (seit 1.10.0)

Die frühere Regel "alles Nutzersichtbare auf Deutsch" (Dietmar, 22.07.2026) ist mit der
Zweisprachigkeit abgelöst (EMS-Auftrag 05.11.2026, SUITE.md Konventionen Punkt 9, Store-Prüfliste
25-29). Maßgeblich ist jetzt:

- **Quelltext englisch, Deutsch in `locale.json`** (je Modul: `StromGedachtWidget/locale.json`,
  `StromGedachtTile/locale.json`, Format `{"translations":{"de":{"English":"Deutsch"}}}`).
  Neue Texte immer mit englischem Quelltext und deutschem Eintrag anlegen; `php tests/smoke.php`
  prüft, dass jeder Schlüssel in `locale.json` steht, die Schlüssel englisch sind und die
  Platzhalter (`%s`/`%d`) in beiden Sprachen übereinstimmen.
- **Formularbeschriftungen** (`caption`, `label`, `suffix`, Options-Texte in `form.json` und in
  PHP gebauten Formularen) bleiben einfacher englischer Quelltext - die Konsole übersetzt sie
  selbst (Punkt 25). Keine eigene Übersetzungsschleife über das Formular bauen.
- **`$this->Translate()`** (mit `sprintf`) nur für Texte mit eingesetzten Werten sowie Variablen-
  und Profilnamen, Meldungen/Rückgabetexte, Debug-Texte, Kachel-Inhalt, Statuszeilen und
  Listenzellen. `onClick`-Texte werden nicht übersetzt - dort sprachneutral bleiben.
- **Kachel-HTML:** Texte als `{{t:Englischer Schlüssel}}`-Token; `GetVisualizationTile()` ersetzt
  sie einmal je Laden. Übersetzungen dürfen keine Anführungszeichen, Backslashes, Backticks
  oder HTML-Tags enthalten (stehen auch in JavaScript-Zeichenketten; der Test prüft das).
- **Stolperstein 24:** nicht hunderte `Translate()`-Aufrufe je Timer-/Kachel-Lauf; der Test
  begrenzt die Anzahl (derzeit <= 12).
- **Dezimalzeichen** über `formatDecimal()` (`Translate('decimal comma') === ','`), nie
  `number_format(..., ',', '.')` fest verdrahten.
- **Idents sind API und werden nie umbenannt** (Verbund-Konvention). Konkret hier: `State`, `GSI`,
  `ECSignal`, `ECShare`, `Text`, `Updated`, `Widget`. Ebenso Klassen-, Methoden-, Property- und
  Funktionsnamen, Formularelement-Typen, API-Feldnamen der Gegenstellen sowie die Schlüssel der
  Verträge `SGW_GetState()`/`SGW_GetForecast()` (`state`, `label`, `gsi`, `ecSignal`, `ecShare`,
  `updated`); nur das Anzeigefeld `label` folgt der Sprache.
- **Produktnamen bleiben stehen:** GrünstromIndex, Energy-Charts, StromGedacht, Symcon, WebFront,
  API, HTTP. "IP-Symcon" ist veraltet - überall "Symcon" schreiben.
- **Deutsche Texte:** weiterhin keine vermeidbaren Anglizismen (Probelauf statt Dry-Run,
  Verknüpfung statt Link, Ereignis statt Event, Schaltfläche statt Button, Prüfliste statt
  Checkliste, Suche statt Scan); bei Ersetzungen Grammatik und Genus prüfen, nie blind
  `replace_all`.
- Der Modulname "StromGedacht Widget" selbst ist Store-Name und Vertrag und wird nicht übersetzt.

## Vertragsversionierung (Verbund-Konvention, 23.07.2026)

Manifest: `SUITE.md` (siehe Abschnitt "Verbund-Manifest SUITE.md — Bezugsquelle" unten — seit
31.08.2026 nur noch lokal, nicht mehr in einem GitHub-Repo).

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

- `main` — Stable-Stand, wird im Symcon-Store ausgeliefert (Standardzweig). Seit 27.10.2026
  (Dietmar: alle Store-Betas auf Stable umgestellt) gilt `main` == `beta`; Veröffentlichung
  per Fast-Forward von `beta` nach `main`.
- `beta` — Arbeitszweig für Entwicklung. Erst prüfen (Tests, Migrationsvergleich, Store-Checkliste),
  dann `beta` nach `main` bringen. Stable wird jetzt wirklich an Nutzer ausgeliefert.
- `ems-integration` — **gelöscht am 30.10.2026** (Dietmar). Früherer gemeinsamer Arbeitszweig der
  EMS-Integrationsphase; er enthielt zuletzt keine Commits, die nicht auch auf `beta`/`main` lagen.

### Vor jedem beta→main-Wechsel: Neuinstallations-Simulation

Verbund-Checkliste (SUITE.md, Commits 875fe1d + 630e15a, Grundregel "keine eigene Anlage als
Norm annehmen" — Dietmars PLZ 70173 liegt zufällig im StromGedacht-Gebiet, das hätte den
Regions-Blindfleck von v1.6.2 sonst nie sichtbar gemacht). Vor dem Merge abarbeiten, nicht erst
danach:

1. Formular komplett durchgehen, als wäre man Nutzer ohne StromGedacht-Abdeckung/außerhalb
   Baden-Württembergs, ohne Dietmars konkrete PLZ/Konfiguration im Kopf.
2. Erwähnte Fabrikate/Dienste nur als "z. B." kennzeichnen, nie als einzige Option suggerieren
   (bei uns unkritisch — reines API-Polling, keine Hardware-Bindung, siehe v1.6.2-Prüfung).
3. Bei jedem Feld/jeder Liste klarstellen, ob der Wert automatisch kommt (Discovery/Vertrag)
   oder manuell verknüpft werden muss (Vorbild: `SourceInstance`-Caption "leer = automatisch
   erkennen").
4. Default-Werte dürfen nie eine eigene Sonderkonfiguration widerspiegeln.
5. Volltextsuche nach eigenen IDs/PLZ/Kampagnennamen im Code (`grep -rn "70173"` u. Ä.) — nichts
   davon darf als Default oder Beispielwert im Repo landen.
6. Wo möglich: zweite Sitzung gegenprüfen lassen.
- `master` existiert nicht mehr (am 22.07.2026 repo-übergreifend entfernt).

## README-Badges (Verbund-Konvention, 18.08.2026)

Zeile direkt unter der H1-Überschrift (Vorbild EMS-README, `check-style.yml` als CI-Vorlage):
Symcon|PHPModul, Modul Version, Symcon Version (aus `library.json` → `compatibility.version`),
License (eigener Text „PolyForm_Noncommercial_1.0.0", kein Preset), Check-Style-CI (echter
GitHub-Actions-Workflow `.github/workflows/check-style.yml`, kein gefälschtes Badge), PayPal.

**„Modul Version"-Badge muss bei JEDEM Versions-Bump von Hand mitgezogen werden** — synct nicht
automatisch mit `library.json`/`module.json`. Gehört ab jetzt zur ohnehin bestehenden
Pflegepflicht (siehe „Formular-Konvention" oben: NEWS_ITEMS/MODULE_VERSION bei jedem Fix prüfen —
diese Badge-Zeile gehört mit auf dieselbe Prüfliste).

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

## Prüfen vor jedem Push auf `beta`/`main` (Migrationsvergleich, SUITE.md 9e, 13.09.2026)

Zusätzlich zu den Checks oben, **vor jedem Push auf `beta` und vor
jedem Nachziehen von `main`** (Stable geht an Bestandsnutzer):

```bash
php /Users/dietmar/Nextcloud/Claude/.tools/migrationsvergleich.php . origin/main HEAD
```

Vergleicht den zu pushenden Stand gegen `origin/main` (den ausgelieferten Stand) und meldet BRUCH (Modul/GUID entfernt,
Präfix geändert, Property/Ident entfernt oder Typ geändert, öffentliche Funktion entfernt/
Parameterzahl geändert, Vertrags-Major-Sprung), WARNUNG oder HINWEIS. **Rückgabecode 1 = erst
mit Dietmar klären, nicht ungeprüft pushen** — Ziel ist, dass kein Push bei Bestandsnutzern
eine Migration erzwingt (Dietmars Auftrag zum bevorstehenden ersten Symcon-Store-Start, über
EMS an alle Store-Start-Module verteilt). Details siehe Memory `nrg-stack-store-review-
erkenntnisse` Punkt 14 und `project_stromgedacht`.

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
- **Selbstverständnis (Dietmars ausdrückliche Korrektur, 31.08.2026, SUITE.md "Gemeinsames
  Zielbild"):** Änderungen nicht als "Flicken" von etwas kaputt Angenommenem beschreiben —
  Commits/Antworten als Bauen von etwas Nützlichem framen, das Nutzern und der Welt wirklich
  weiterhilft, nicht als Reparaturbetrieb.


## Verbund-Manifest SUITE.md — Bezugsquelle (geändert 31.08.2026)

SUITE.md liegt seit 31.08.2026 NICHT mehr in einem GitHub-Repo (die
Modul-Repos sind öffentlich, SUITE.md enthält das komplette Architektur-/
Debugging-Know-how des Verbunds — Dietmars Entscheidung). Primärquelle ist
ausschließlich die lokale Datei `/Users/dietmar/Nextcloud/Claude/SUITE.md`
auf Dietmars Maschine, versioniert in einem eigenen lokalen Git-Repo ohne
Remote. Frühere Kopien dieses Dokuments wurden zusätzlich aus der Historie
aller Modul-Repos entfernt (`git filter-repo` + Force-Push). Kein
Fallback-Link mehr — ohne lokalen Zugriff auf Dietmars Maschine ist SUITE.md
nicht einsehbar.
