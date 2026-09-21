# Changelog

Alle nennenswerten Änderungen an StromGedachtWidget.

## 1.8.2 (2026-09-21)

- **Wert kommt automatisch: Eingabefeld ersetzen** (NRG-Stack-Formularregel, SUITE.md): In der Kachel wird das Auswahlfeld "Datenquelle" ausgeblendet, sobald die Quelle automatisch erkannt wird (genau eine StromGedachtWidget-Instanz, keine eigene Wahl) - es bleibt die schreibgeschützte Zeile "🔗 Datenquelle: ... (automatisch erkannt)". Bei eigener Wahl (✏️), mehreren oder keiner Instanz bleibt das Feld sichtbar. Der automatische Wert wird nie ins Feld geschrieben (würde beim Übernehmen als eigene Angabe gespeichert). Die Statuszeile folgt außerdem per `onChange` (`SGWTILE_OnChangeSource`) sofort der Auswahl im offenen Formular, noch vor dem Speichern. Im Widget gibt es kein automatisch befülltes Eingabefeld (Postleitzahl und Intervall sind reine Nutzerangaben), dort ist nichts zu ersetzen.

## 1.8.1 (2026-09-21)

- **Verbindungen im Formular sichtbar** (NRG-Stack-Regel "Verbund-Verbindungen im Formular sichtbar machen", SUITE.md): Die Kachel (StromGedachtTile) zeigt statt des statischen Satzes "wird automatisch erkannt" jetzt eine live berechnete Statuszeile zur Datenquelle - ✅ Instanz-ID, Name, automatisch erkannt/manuell gewählt und die aktuell angezeigten Werte je Quelle; ⚠️ bei mehreren Instanzen ohne Auswahl, bei einer nicht mehr vorhandenen gewählten Instanz oder wenn die Quelle noch keine Werte liefert; ℹ️ wenn gar keine Instanz gefunden wurde. Im Widget steht im Automationen-Bereich jetzt immer eine Statuszeile zur EMS-Erkennung (✅ erkannt mit Anzahl gesteuerter Variablen / ℹ️ kein EMS / ⚠️ Abfrage fehlgeschlagen), vorher erschien nur bei einer Kollision eine Warnung. Neue Regressionstests in `tests/smoke.php` prüfen jeden Zustand am ausgelieferten Formular-JSON.

## 1.8.0 (2026-09-14)

- **"🧡 Über dieses Modul"-Panel** (Lizenz/Spenden, NRG-Stack-Formular-Konvention Punkt 5) in beiden Modulen ergänzt — verbundweit identischer Wortlaut, ganz unten im Formular, nicht ausblendbar.
- **Forum-Hinweis überarbeitet:** eigenes, dismissibles ExpansionPanel "💬 Feedback im Symcon-Forum" statt der alten Zeile im Widget; in der Kachel gab es bisher gar keinen — jetzt ebenfalls vorhanden. Die alte `SGW_DismissReviewHint()`-Funktion bleibt aus Vertragsgründen erhalten (veröffentlichte Funktion, wird nie entfernt) und wirkt jetzt wie der neue Hinweis.
- **Ausblenden über mehrere Instanzen teilen:** Wer mehrere StromGedacht-Instanzen betreibt (z. B. verschiedene PLZ/Regionen) oder mehrere Kacheln, muss "Wozu dieses Modul?"/"Was ist Neu?"/den Forum-Hinweis nicht mehr an jeder Instanz einzeln bestätigen — ein Klick an einer reicht für alle Geschwister-Instanzen desselben Moduls, auch für später neu hinzukommende. NRG-Stack-Konvention nach MeterHub-Vorbild.

## 1.7.7 (2026-09-14)

- **Fix (form.json):** Beim 9d-Statuscode-Fix in v1.7.5 wurden `STATUS_NO_ZIP` und `STATUS_NO_SOURCE` beide auf 104 gelegt, die Konsolen-Bildunterschrift in `form.json` aber nicht nachgezogen — bei "keine Datenquelle aktiviert" stand dort weiterhin fälschlich "Bitte Postleitzahl konfigurieren" (Text des alten, separaten Codes 203). Jetzt ein gemeinsamer, zutreffender Text für Code 104, toter 203-Eintrag entfernt. Gefunden bei einer angeforderten Durchsicht der Doku-/Hilfe-Texte vor dem Store-Launch.

## 1.7.6 (2026-09-14)

- **Neues Panel "👋 Wozu dieses Modul?"** ganz oben im Instanzformular (vor dem "Neu in Version"-Panel), in beiden Modulen (StromGedachtWidget + StromGedachtTile). Kurze Erklärung in 2–3 Sätzen, was das Modul liest/tut und welchen Nutzen das stiftet — einmalig ausblendbar (nicht versionsgebunden, da sich der Zweck eines Moduls nicht mit jedem Release ändert). NRG-Stack-Formular-Konvention Punkt 0 (SUITE.md), ausgelöst durch Praxis-Feedback eines Nutzers, der zu Beginn nicht wusste, wofür das Modul gedacht ist. Referenzimplementierung MeterHub.

## 1.7.5 (2026-09-13)

- **Interne Robustheit (Verbund-Regel 9c, SUITE.md):** Werte aus `ReadPropertyString()`/`ReadAttributeString()` konnten während eines Modul-Neuladens (Kernel-Runlevel noch nicht bereit) `false` statt des erwarteten Typs liefern — mit `declare(strict_types=1)` würde daraus ein `TypeError`, der die ganze Aufrufkette abreißt (z. B. beim PLZ-Abruf, beim Lesen der Automationen-Zwischenstände in `StromGedachtWidget`, oder beim Aufbau der Kachel-Optik in `StromGedachtTile`). Jetzt an allen betroffenen Stellen explizit typgecastet (`(string)`/`(int)`/`(float)`), bevor der Wert weiterverarbeitet wird. Fund entstand aus einem verbundweiten Hinweis (Tibber/OCPPHub/Dashboard, dreimal unabhängig aufgetreten).
- **Statuscode-Fix (Verbund-Regel 9d, SUITE.md):** Eine Instanz ohne aktivierte Datenquelle setzte bisher einen Fehlerstatus (203) statt `IS_INACTIVE` (104) — ein bewusst/dauerhaft ruhender Zustand ist kein Fehler. Für einen systemweiten Integrity-Check/Watchdog sah das aber wie ein kaputtes Modul aus (ausgelöst durch einen echten Vorfall bei ModbusSlave: geparkte Instanzen mit Fehlerstatus lösten dort stundenlange, unnötige Neustarts aus). Jetzt korrekt `IS_INACTIVE`, wie bereits bei "keine PLZ eingegeben".

## 1.7.4 (2026-09-01)

- **Abruf-Cooldown:** `SGW_Update()` (Formular-Button, Kachel-Refresh, externe Aufrufe) ist jetzt auf höchstens einen echten Abruf alle 30 Sekunden begrenzt — schützt die drei kostenlosen Dritt-APIs (StromGedacht, Corrently, Energy-Charts) vor Abuse/Rate-Limiting durch versehentliches Mehrfach-Antippen, z. B. bei anonymem Zugriff auf eine öffentliche Demo-Instanz. Bei zu schneller Wiederholung kommt statt eines neuen Abrufs ein Wartehinweis zurück ("⏳ Gerade erst aktualisiert – bitte noch N Sekunde(n) warten"). Der periodische Timer (Mindestintervall 60 s) ist davon nie betroffen. Zweiter Verbund-Vorschlag aus derselben Dashboard-Anfrage wie der Sicherheitsfix unten.

## 1.7.3 (2026-09-01)

- **Sicherheitsfix (StromGedachtTile):** `RequestAction()` prüfte bei den Automationen-Verwaltungsbefehlen (Regel anlegen/bearbeiten/löschen/umschalten, Zielvariablen- und Wertelisten) bisher nicht, ob "Automationen anzeigen" deaktiviert ist — die UI blendete den Bereich zwar aus, ein direkter `requestAction()`-Aufruf (z. B. über die Browser-Konsole) hätte die Sperre umgangen und sowohl echte Regeln auf beliebige Systemvariablen schreiben als auch die vollständige Liste aller schaltbaren Variablen im System auslesen können. Jetzt serverseitig blockiert, wenn `ShowAutomations` aus ist — unabhängig vom UI-Zustand. Fund entstand aus einer Verbund-Anfrage zur öffentlichen Modulvorstellungs-Demo; per Standalone-Test verifiziert (Bypass-Versuch blockiert, Datenleck verhindert, normale Funktion bei aktivierten Automationen unverändert).

## 1.7.2 (2026-08-20)

- Neuer Button **"🔄 Übernehmen erzwingen (ohne Formularänderung)"** in beiden Modulen (StromGedachtWidget + StromGedachtTile) — ruft `IPS_ApplyChanges($id)` direkt auf, ohne dass vorher etwas im Formular geändert werden muss. Praktisch nach einem Modul-Update, falls die Instanz den neuen Code nicht von selbst übernimmt. Optionaler Verbund-Vorschlag (EMS), keine Pflicht-Konvention.

## 1.7.1 (2026-08-20)

- **Sichtbare Rückmeldung** (Verbund-Konvention "Sichtbare Rückmeldung bei jeder Aktion"): Die Schaltfläche "Jetzt aktualisieren" zeigt jetzt direkt im Formular ein Ergebnis (z. B. "✅ 3 von 3 Quelle(n) aktualisiert (12:34:56 Uhr)", "⚠️ Für diese Postleitzahl liegen keine Daten vor", "❌ Keine aktivierte Quelle erreichbar"), ohne dass das Formular neu geöffnet werden muss. `SGW_Update()` gibt dafür jetzt einen kurzen Ergebnistext zurück statt nichts (`echo SGW_Update($id);`). `AckNews()`/`DismissReviewHint()` waren bereits konform (blenden ihr Panel sofort aus).

## 1.7.0 (2026-07-27)

- **`SGW_GetForecast()` erweitert**: liefert jetzt auch Vorschau-Einträge für `source: 'gsi'` (GrünstromIndex, stündliches Raster über mehrere Tage) und `source: 'energycharts'` (ecSignal, 15-Minuten-Raster, aber nur ein knappes Zukunftsfenster) — vorher nur `'stromgedacht'`. Auf konkrete Anfrage des EMS-Moduls für eine "grünste Ladezeit"-Funktion gebaut, Roh-APIs vorher live gegen die Annahmen (24h/15-Minuten für beide Quellen) geprüft: GSI ist tatsächlich stündlich, Energy-Charts deckt nur ~12h Zukunft ab, nicht 24h. Kein künstliches Verfeinern/Auffüllen, um keine falsche Genauigkeit vorzutäuschen. `ecShare` ist bewusst nicht enthalten (passt nicht ins bestehende 3-Werte-`source`-Schema).
- Dauerhaft getestet (4 neue Fälle in `tests/smoke.php`: GSI-Vorschau, Energy-Charts-Vorschau, alle drei Quellen gemischt, nur-eine-Quelle-liefert-nur-ihre-eigenen-Einträge).

## 1.6.2 (2026-07-27)

- Usability-Nachschau auf Dietmars Hinweis (Vorbild: EMS-Fund zu impliziten Hardware-Annahmen): Formular macht jetzt deutlich, dass StromGedacht nur Baden-Württemberg + Pilotgebiete abdeckt (stand vorher nur im README, nicht im Formular selbst) und dass die Automationen-Quellenliste "Wenn Datenpunkt" nur aktivierte Quellen zeigt

## 1.6.1 (2026-07-27)

- Ampel-Texte an die offizielle StromGedacht-App/Website (TransnetBW) angeglichen — Wiedererkennbarkeit für Nutzer, die die App bereits kennen. Recherchiert auf www.stromgedacht.de statt eigene Formulierungen fortzuschreiben.

## 1.6.0 (2026-07-27)

- **Zwei-Regler-Kollisionscheck**: Automationen-Regeln zeigen jetzt an, wenn ihre Zielvariable aktuell auch vom EMS gesteuert wird (über `EMS_GetControlledVariables()`, falls im System vorhanden) — Warnhinweis im Instanzformular, ⚠️-Symbol vor der Regel in der Kachel. Reine Information, keine Blockade; ohne EMS im System keine Änderung am Verhalten.
- Dauerhafte Regressionstests für `SGW_GetState()`/`SGW_GetForecast()` und den Kollisionscheck in `tests/smoke.php` verankert (vorher nur mit Wegwerf-Skripten geprüft)

## 1.5.0 (2026-07-24)

- **`SGW_GetState()`**: stabiler NRG-Stack-Vertrag (contractVersion 1.0) — aktueller Zustand aller aktivierten Quellen als Array, für andere Module des Verbunds (z. B. EMS)
- **`SGW_GetForecast()`**: Vorschau der StromGedacht-Netzampel für einen Zeitraum, auf Basis der bisher nicht angebundenen `/v1/statesRelative`-API (Horizont max. 48 h); GrünstromIndex/Energy-Charts liefern hier laut Verbund-Vorgabe bewusst noch keine Einträge (nicht planungsrelevant)
- Lizenzwechsel MIT → PolyForm Noncommercial 1.0.0 (NRG-Stack-weite Umstellung: privat/nicht-kommerziell frei, gewerblich lizenzpflichtig)
- Teil des NRG-Stack-Modulverbunds (vormals „DG65 Energie-Suite") — siehe [SUITE.md](https://github.com/DG65/NRGEMS/blob/main/SUITE.md)
- Einheitliche Formular-Optik des NRG-Stack übernommen: „🆕 Neu in Version"-Panel (erscheint bis zur Bestätigung, danach je Version erneut), Versionsnummer im Doku-Panel-Titel

## 1.4.0 (2026-07-13)

- Neues eigenständiges Kachel-Modul **StromGedachtTile** — grafisch einstellbare native Kachel fürs WebFront (Ampelfarben, Flächen-/Textfarben, Schrift), erkennt die Quelle automatisch bei genau einer StromGedachtWidget-Instanz
- **Automationen (Wenn → Dann)** im Instanzformular und in der Kachel: Bedingung = einer der aktivierten Ampel-/Signal-Werte, Ziel = beliebige schaltbare Variable im System, mehrere Bedingungen UND-verknüpfbar, flankengesteuert
- Regel-Editor der Kachel bietet den Vergleichswert als Dropdown an, sobald der gewählte Datenpunkt bekannte Profilwerte hat (StromGedacht-Ampel, Energy-Charts-Signal); im klassischen Instanzformular (technisch nicht möglich) stattdessen eine Werte-Übersicht als Hilfetext
- Dokumentations-ExpansionPanel „📖 Dokumentation & Hilfe" im Instanzformular und in der Kachel
- Dismissbarer Bewertungs-Hinweis mit Link zur [Symcon-Community](https://community.symcon.de/t/modul-strom-gedacht-ampel-widget/143960)

## 1.3 (2026-06-19)

- Vendor in `module.json` auf TransnetBW GmbH korrigiert (Symcon-Review: vendor = Dienstanbieter, nicht Entwickler)
- Alle drei Datenquellen laufen parallel statt Einzelauswahl (Widget mit Spalten nebeneinander); Instanz bleibt aktiv, solange eine Quelle liefert

## 1.2 (2026-06-19)

- Mehrere Datenquellen: zusätzlich zu StromGedacht (Netz-Signal) jetzt auch Corrently GrünstromIndex und Energy-Charts-Signal (beide Öko-Signal), je Quelle einzeln aktivierbar, getrennte Variablenprofile

## 1.0 (2026-06-10)

- Erste funktionierende Version: PLZ-Parameter (`zip`) für die StromGedacht-API ergänzt, numerisches State-Mapping (−1/1/2/3/4 statt GREEN/YELLOW/RED), IPv4-Fallback für PHP-Streams ohne IPv6-Fallback, `form.json` instand gesetzt
