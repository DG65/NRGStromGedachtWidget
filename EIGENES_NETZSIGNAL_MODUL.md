# Eigenes Netzsignal-Modul an den NRG-Stack anbinden

🇬🇧 [English version](EIGENES_NETZSIGNAL_MODUL.en.md)

Diese Anleitung richtet sich an Entwickler, die ein **eigenes Symcon-Modul für ein Netzsignal** haben (zum Beispiel eine
andere Netzampel oder ein Signal eines Netzbetreibers) und es für das EMS und andere Module des NRG-Stacks sichtbar
machen wollen. Das eigene Modul bleibt ein eigenständiges Modul. Es muss weder StromGedacht Widget noch ein anderes
NRG-Stack-Modul installiert haben.

Gemeint ist der **Vertrag `gridsignal`, Version 1.2**. Er wird vom StromGedacht Widget angeboten (Präfix `SGW`) und ist
**nur lesend**: Es gibt keine Steuerbefehle, keine Schreibfreigabe und kein `failsafeSec`.

## Das Prinzip in drei Sätzen

1. Dein Modul bietet eine Funktion `<PRÄFIX>_GetState($id)` an, die **den aktuellen Zustand des Signals** als Array liefert.
2. Optional bietet es `<PRÄFIX>_GetForecast($id, $Von, $Bis)` mit einer **Vorschau** an.
3. Beide Antworten beschreiben sich selbst (`contractType` und `contractVersion`). Der Konsument findet dein Modul über den
   Präfix und prüft Typ und Hauptversion.

Der Präfix steht in der `module.json` deines Moduls (`"prefix": "MNZ"`). Eine öffentliche Methode `GetState()` in der
Modulklasse stellt Symcon automatisch als `MNZ_GetState($id)` bereit.

## `<PRÄFIX>_GetState($id)`

Rückgabe: **ein Array** (kein JSON-String). Pflichtfelder sind markiert. **Fehlt ein optionales Feld, heißt das
„unbekannt“, nicht „nein“.**

| Feld | Pflicht | Bedeutung |
|---|---|---|
| `contractType` | ja | immer `'gridsignal'` |
| `contractVersion` | ja | `'1.2'`. Konsumenten prüfen die Hauptversion (vor dem Punkt). Fehlt das Feld, gilt `'1.0'`. |
| `region` | nein | Postleitzahl (String), für die das Signal gilt, oder `null` (bundesweit oder unbekannt). Hilft dem Konsumenten, bei mehreren Instanzen die passende zu wählen. Fehlt das Feld, heißt das „unbekannt“. |
| `state` | ja | Ampelzustand als Zahl oder `null` (siehe Skala unten). `null` heißt: kein Wert bekannt. |
| `label` | nein | Anzeigetext zum Zustand. Er folgt der Symcon-Sprache und ist **nur zur Anzeige** gedacht. Zum Auswerten immer `state` nehmen. |
| `updated` | ja | Unix-Zeit der letzten erfolgreichen Aktualisierung, `0` = noch nie. Daran erkennt der Konsument veraltete Werte. |
| `gsi` | nein | Öko-Index 0 bis 100 (Float) oder `null` |
| `ecSignal` | nein | zweites Ampelsignal als Zahl (siehe unten) oder `null` |
| `ecShare` | nein | Anteil erneuerbarer Energien in Prozent (Float) oder `null` |

`gsi`, `ecSignal` und `ecShare` sind quellenspezifisch (GrünstromIndex und Energy-Charts im StromGedacht Widget). Ein
anderes Modul darf sie weglassen.

### Skala von `state` (verbindlich)

| Wert | Bedeutung |
|---|---|
| −1 | Supergrün: Strom bevorzugt jetzt nutzen, besonders viel erneuerbare Energie im Netz |
| 1 | Grün: Strom wie gewohnt nutzen |
| 2 | Gelb: angespannte Netzsituation |
| 3 | Orange: Verbrauch reduzieren |
| 4 | Rot: Verbrauch vermeiden |

Hat dein Signal eine andere Skala, bilde sie auf diese Werte ab. `ecSignal` (falls geliefert): −1 Rot (Netzengpass),
0 Rot, 1 Gelb, 2 Grün.

### Beispielantwort

```php
[
    'contractType'    => 'gridsignal',
    'contractVersion' => '1.2',
    'region'          => '12345',
    'state'           => 1,
    'label'           => 'Grün',
    'updated'         => 1794209033
]
```

## `<PRÄFIX>_GetForecast($id, int $Von, int $Bis)` (optional)

Rückgabe: eine **Liste** von Zeiträumen im Bereich `[$Von, $Bis]` (Unix-Zeit), aufsteigend sortiert. Ist nichts bekannt,
eine **leere Liste**, nie eine Exception.

| Feld | Bedeutung |
|---|---|
| `contractType` | `'gridsignal'` |
| `contractVersion` | `'1.2'` |
| `source` | Kennung deiner Quelle in Kleinbuchstaben, zum Beispiel `'meinnetz'` |
| `from`, `to` | Beginn und Ende des Zeitraums (Unix-Zeit, `from` < `to`), auf `[$Von, $Bis]` zugeschnitten |
| `value` | Wert im Zeitraum (Float). **Die Skala hängt von `source` ab**, siehe „Skala von `value`“ unten |

### Skala von `value`

`value` hat **nicht** für jede Quelle dieselbe Skala. Maßgeblich ist `source`:

| `source` | Skala von `value` |
|---|---|
| `stromgedacht` und **jede eigene Quelle deines Moduls** | wie `state`: −1, 1, 2, 3, 4 (Ampelzustand) |
| `gsi` (nur StromGedacht Widget) | Öko-Index 0 bis 100, **kein** Ampelzustand |
| `energycharts` (nur StromGedacht Widget) | wie `ecSignal`: −1, 0, 1, 2 |

Dein eigenes Modul liefert in `value` immer die Skala von `state`. Die Kennungen `gsi` und `energycharts` sind den beiden
Quellen des StromGedacht Widgets vorbehalten, verwende sie nicht für andere Werte. Ein Konsument, der die Ampel will,
liest nur Einträge mit der `state`-Skala und deutet Werte von `gsi` und `energycharts` nie als Ampelzustand: Ein
GrünstromIndex von 3,6 ist kein „Orange“.

Horizont und Zeitraster darfst du frei wählen (bei StromGedacht sind es unregelmäßige Zustands-Segmente bis 48 Stunden).
Der Konsument darf keine feste Rasterung voraussetzen. Eine leere Liste beschreibt sich nicht selbst, den Typ erkennt der
Konsument deshalb über `GetState`.

## Was der Konsument tut

- Er sucht über `IPS_GetModuleList` die Gerätemodule, bildet den Präfix und prüft `function_exists('<PRÄFIX>_GetState')`.
- Er ruft die Funktion nur im langsamen Zyklus (Minuten) auf, gekapselt und mit Zeitbudget. Ein Fehler führt dazu, dass dein
  Modul für diesen Zyklus übersprungen wird.
- Er nimmt nur Antworten mit `contractType === 'gridsignal'` und passender Hauptversion (hier 1).
- Er deutet `value` je nach `source` (siehe „Skala von `value`“), nie pauschal als Ampelzustand.
- Das Signal ist eine **Empfehlung, keine Pflicht**: Ein Konsument darf es überstimmen, etwa wegen §14a oder Direktvermarktung.

Hinweis: Auch das §14a-Modul des NRG-Stacks hat eine Funktion `GetState`, aber mit einem anderen `contractType`. Unterschieden
wird immer am `contractType`, nie am Funktionsnamen.

## Prüfliste für dein Modul

- [ ] `GetState()` ist **schnell und ohne Nebenwirkung**: nur Variablen und Eigenschaften lesen, nichts vom Netz oder Gerät abfragen.
- [ ] Rückgabe ist ein Array mit `contractType`, `contractVersion`, `state` und `updated`.
- [ ] `state` ist `null` oder eine Zahl der Skala, nie ein Text.
- [ ] Ohne Daten kommt `state => null` und `updated => 0`, keine Exception.
- [ ] `GetForecast()` (falls vorhanden) liefert bei fehlenden Daten eine leere Liste.
- [ ] Das Modul läuft auch ohne jedes andere Modul des NRG-Stacks.

Eine Referenzimplementierung ist das StromGedacht Widget in diesem Repository (`StromGedachtWidget/module.php`,
Funktionen `GetState()` und `GetForecast()`).
