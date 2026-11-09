# StromGedachtWidget

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Module version](https://img.shields.io/badge/Module_version-1.10.0-blue)
![Symcon version](https://img.shields.io/badge/Symcon_version-9.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![Check Style](https://github.com/DG65/NRGStromGedachtWidget/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/NRGStromGedachtWidget/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

🇩🇪 [Deutsche Fassung](README.md)

> Part of the **NRG-Stack**. The architecture/family manifest SUITE.md has not been public since 2026-08-31 (it only exists locally at DG65).

A Symcon module that queries electricity traffic-light signals from up to three sources in parallel and shows them side by side as a traffic-light widget:

- **[StromGedacht](https://www.stromgedacht.de)** (TransnetBW) — *grid signal*: when does it help the grid to shift consumption? Coverage: Baden-Württemberg and pilot areas (e.g. parts of Lower Saxony).
- **[GrünstromIndex](https://gruenstromindex.de)** (Corrently/STROMDAO) — *eco signal*: how green is the electricity mix in the region (0–100)? Coverage: all of Germany, by postal code.
- **[Energy-Charts traffic light](https://www.energy-charts.info)** (Fraunhofer ISE) — *eco signal*: share of renewable energy in the load, nationwide.

> **Note:** the grid signal and the eco signal are different quantities. StromGedacht reports looming grid bottlenecks (redispatch), GrünstromIndex and Energy-Charts rate the share of renewable energy. A green electricity mix can coincide with a strained grid — and the other way round. That is exactly why the widget shows all enabled sources side by side.

Every source can be enabled individually. If a source delivers no data (e.g. because the postal code is outside the StromGedacht area), its column in the widget shows "No data" with a grey LED — the other sources keep running normally.

## Language

The module is bilingual (English/German). Form, messages, status texts, variable names and the tile follow the Symcon language. Identifiers (variable idents, property names, function names and the keys of the `SGW_GetState()` contract) are language-independent and never change; only display texts such as the `label` field follow the language.

## States per data source

### StromGedacht (profile `SGW.State`)

| Value | State | Meaning |
|-------|-------|---------|
| −1 | Super green | Use electricity preferably now — especially a lot of renewable energy in the grid |
| 1 | Green | Use electricity as usual — normal operation |
| 2 | Yellow | Strained grid situation *(no longer used by the API)* |
| 3 | Orange | Reduce consumption — helps to save costs and CO₂ |
| 4 | Red | Avoid consumption — helps to prevent an electricity shortage |

*(Wording aligned with the official StromGedacht app/website of TransnetBW, as of 2026-07-27.)*

### GrünstromIndex (profile `NRG.Percent`)

Index 0–100 %. For the widget display: ≥ 66 green, ≥ 33 yellow, below that red.

### Energy-Charts (profile `SGW.ECSignal`)

| Value | State | Meaning |
|-------|-------|---------|
| −1 | Red (grid bottleneck) | Grid bottleneck — reduce consumption |
| 0 | Red | Low share of renewable energy |
| 1 | Yellow | Average share of renewable energy |
| 2 | Green | High share of renewable energy |

The values correspond 1:1 to the source API; each source uses its own variable profile.

## Requirements

- Symcon version 9.0 or later
- Internet access to the respective API
- For StromGedacht and GrünstromIndex: a German postal code (StromGedacht only in the covered grid area)

## Installation

1. Open the **Module Control** in the Symcon console
2. Add the repository: `https://github.com/DG65/NRGStromGedachtWidget`
3. Create an instance of **StromGedacht Widget**
4. Optionally create an instance of **StromGedachtTile** as well if you want a native, graphically configurable tile in the WebFront (see [Tile](#tile))

Updates are also installed via the Module Control ("Update").

## Configuration

| Setting | Description | Default |
|---------|-------------|---------|
| Enable StromGedacht | Query the TransnetBW grid signal | on |
| Enable GrünstromIndex | Query the eco signal from Corrently | on |
| Enable Energy-Charts | Query the eco signal from Fraunhofer ISE | on |
| Postal code | Postal code of the location (required as soon as StromGedacht or GrünstromIndex is enabled) | — |
| Update interval | Query interval in seconds (minimum 60) | 300 |

The **"Update now"** button triggers a query manually.

**When a source is disabled**, its variables are removed (including any logging history) and recreated when it is enabled again.

## Variables created

| Ident | Name (en / de) | Source | Type | Description |
|-------|----------------|--------|------|-------------|
| `State` | Traffic light / Ampel | StromGedacht | Integer (`SGW.State`) | Current grid state |
| `Text` | Status text / Status Text | StromGedacht | String | Recommendation text for the current state |
| `GSI` | GrünstromIndex | GrünstromIndex | Float (`NRG.Percent`) | Current index value 0–100 % |
| `ECSignal` | Electricity traffic light / Stromampel | Energy-Charts | Integer (`SGW.ECSignal`) | Current traffic-light signal |
| `ECShare` | Renewable share / EE-Anteil | Energy-Charts | Float (`NRG.Percent`) | Share of renewable energy in the load |
| `Updated` | Updated / Aktualisiert | always | Integer (`~UnixTimestamp`) | Time of the last successful update |
| `Widget` | Display / Anzeige | always | String (`~HTMLBox`) | All enabled sources side by side as traffic-light columns |

The source-specific variables only exist while the respective source is enabled.

## Tile

For a native, graphically configurable display in the WebFront there is the standalone module **StromGedachtTile**. It is deliberately separate from the data logic (own instance, reads the values of a StromGedachtWidget instance via its object tree) — a problem in the tile cannot affect the data retrieval.

- With exactly one StromGedachtWidget instance, it is detected automatically as the source; with several, you can choose manually. A status line in the form shows which instance was detected and which values are currently shown.
- Traffic-light colors (super green/green/yellow/orange/red), surface/text colors and font are freely adjustable; without your own setting the tile follows the light/dark design of the device. A tap on 🔄 updates the source immediately.
- The tile can show and fully manage the source's automations (see below).

## Automations (If → Then)

In the instance form of StromGedacht Widget you can create rules based on the traffic-light/signal values that switch any switchable variable in the system when the condition is met — e.g. "If StromGedacht traffic light = Red, then switch off wallbox". The values of the respective enabled sources are available as the If data point (StromGedacht traffic light, GrünstromIndex, Energy-Charts signal, Energy-Charts renewable share); several conditions are combined with AND. Rules are edge-triggered — they fire when the condition becomes true, not again on every data update. The StromGedachtTile tile can show, create, edit, delete and enable/disable the same rules.

If an NRG-Stack EMS module is installed in the system, the module additionally checks whether the target variable of a rule is already controlled by the EMS ("one controller per setpoint") — as a notice in the form, as a ⚠️ symbol before the rule in the tile. A status line in the automations section always shows whether an EMS was detected. Information only, blocks nothing; without an EMS in the system nothing changes.

## Instance status

| Code | Meaning |
|------|---------|
| 102 | Active — at least one enabled source delivers data |
| 104 | Inactive (deliberate, not an error) — no data source enabled yet or postal code missing (required for StromGedacht and GrünstromIndex) |
| 201 | No data for the configured postal code from any enabled source |
| 202 | No enabled source reachable (details in the debug window) |

If only some of the sources fail, the instance stays active — the failure is visible in the affected widget column ("No data", grey LED) and in the debug window.

## PHP functions

```php
// Query the configured sources immediately and update the variables.
// Returns a short result text (e.g. "✅ 3 of 3 source(s) updated (12:34:56)").
// At most every 30 seconds - on faster repetition (button, tile refresh,
// external call) a "⏳ ... please wait" notice is returned instead of a new query
// (protects the three free third-party APIs). The periodic timer is never
// affected (minimum interval 60 s).
SGW_Update(int $InstanceID): string;
```

Further functions manage the automations (mainly meant for the tile, see [Automations](#automations-if--then)): `SGW_GetDataActions`, `SGW_SetDataAction`, `SGW_DeleteDataAction`, `SGW_SetDataActionActive`, `SGW_GetDataActionEditor`, `SGW_GetTargetValueOptions`.

### NRG-Stack contract (for other modules, e.g. EMS)

```php
// Current state of all enabled sources (contractVersion 1.0)
SGW_GetState(int $InstanceID): array
// -> ['contractVersion' => '1.0', 'state' => int|null, 'label' => string,
//     'gsi' => float|null, 'ecSignal' => int|null, 'ecShare' => float|null, 'updated' => int]
// Fields are null if the respective source is not enabled in this instance.
// The field names are identical in every language; 'label' is display text and follows the Symcon language.

// Forecast in the range [$From, $To] (Unix timestamps), a list per enabled source
SGW_GetForecast(int $InstanceID, int $From, int $To): array
// -> list of ['contractVersion' => '1.0', 'source' => 'stromgedacht'|'gsi'|'energycharts', 'from' => int, 'to' => int, 'value' => float]
// Horizon/grid differ per source (API peculiarity, deliberately not unified so as not to
// suggest false precision):
//   - 'stromgedacht': max. 48 h from now, irregular state segments instead of a fixed grid.
//   - 'gsi' (GrünstromIndex): several days, HOURLY grid (no 15-minute step).
//   - 'energycharts': 15-minute grid, but only a short future window tied to "now"
//     (no guaranteed 24 h horizon). Value is ecSignal (-1/0/1/2);
//     the renewable share (ecShare) is not included here.
// Empty list per source if it is not enabled, the postal code is missing (needed for
// stromgedacht/gsi) or the respective API was unreachable — the other sources deliver independently.
```

StromGedacht is a **recommendation, not an obligation** — a consuming module (e.g. the EMS) may overrule it if legal requirements (§14a), direct marketing or user comfort speak against it.

## Data sources / APIs

```
GET https://api.stromgedacht.de/v1/now?zip=<postal code>            → {"state": <state>}
GET https://api.corrently.io/v2.0/gsi/prediction?zip=<postal code>  → {"forecast": [...]}
GET https://api.energy-charts.info/signal?country=de                → {"unix_seconds": [...], "share": [...], "signal": [...]}
```

All three APIs can be used without a key (StromGedacht and GrünstromIndex for private purposes, fair use). Please choose the query interval fairly — the default of 5 minutes is more than enough.

## Troubleshooting

**The instance shows "Please complete the configuration"**
No postal code is set. Enter a postal code and apply (only needed if StromGedacht or GrünstromIndex is enabled).

**The StromGedacht column shows "No data"**
The postal code is outside the StromGedacht area (essentially Baden-Württemberg). GrünstromIndex and Energy-Charts cover all of Germany and keep running independently.

**The traffic light shows a different state than the StromGedacht app**
Most likely the "Traffic light" variable is still attached to a variable profile of an older module version. Solution: open the variable and remove the custom profile or set it to `SGW.State` — or delete the variable and click "Apply" in the instance (it is recreated with the correct profile).

**No data / variables stay empty**
Check the debug window of the instance: it logs the HTTP code and API response of every query.

## Changes

See [CHANGELOG.md](CHANGELOG.md) (German).

## License

[PolyForm Noncommercial License 1.0.0](LICENSE) — © 2026 Dietmar Gureth (DG65). Private and non-commercial use is free; commercial use requires a license (contact: DG65). Older versions that were published under MIT remain MIT.

StromGedacht is an offering of TransnetBW GmbH, the GrünstromIndex an offering of STROMDAO GmbH, the traffic light an offering of Fraunhofer ISE. This module is a private community project and is not affiliated with these providers.
