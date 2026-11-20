# Connecting your own grid-signal module to the NRG-Stack

🇩🇪 [Deutsche Fassung](EIGENES_NETZSIGNAL_MODUL.md)

This guide is for developers who have their **own Symcon module for a grid signal** (for example another grid traffic light
or a signal from a grid operator) and want to make it visible to the EMS and other NRG-Stack modules. Your module stays a
standalone module. It does not need StromGedacht Widget or any other NRG-Stack module installed.

It covers the **contract `gridsignal`, version 1.2**. It is offered by StromGedacht Widget (prefix `SGW`) and is
**read-only**: there are no control commands, no write approval and no `failsafeSec`.

## The principle in three sentences

1. Your module offers a function `<PREFIX>_GetState($id)` that returns **the current state of the signal** as an array.
2. Optionally it offers `<PREFIX>_GetForecast($id, $From, $To)` with a **forecast**.
3. Both answers describe themselves (`contractType` and `contractVersion`). The consumer finds your module by its prefix and
   checks type and major version.

The prefix is in the `module.json` of your module (`"prefix": "MNZ"`). A public method `GetState()` in the module class is
provided by Symcon automatically as `MNZ_GetState($id)`.

## `<PREFIX>_GetState($id)`

Return value: **an array** (not a JSON string). Required fields are marked. **A missing optional field means "unknown",
not "no".**

| Field | Required | Meaning |
|---|---|---|
| `contractType` | yes | always `'gridsignal'` |
| `contractVersion` | yes | `'1.2'`. Consumers check the major version (before the dot). If the field is missing, `'1.0'` applies. |
| `region` | no | postal code (string) the signal applies to, or `null` (nationwide or unknown). Helps the consumer pick the right instance when there are several. If the field is missing, it means "unknown". |
| `state` | yes | traffic-light state as a number or `null` (see scale below). `null` means: no value known. |
| `label` | no | display text for the state. It follows the Symcon language and is **for display only**. Always evaluate `state`. |
| `updated` | yes | Unix time of the last successful update, `0` = never. The consumer uses it to detect stale values. |
| `gsi` | no | eco index 0 to 100 (float) or `null` |
| `ecSignal` | no | second traffic-light signal as a number (see below) or `null` |
| `ecShare` | no | share of renewable energy in percent (float) or `null` |

`gsi`, `ecSignal` and `ecShare` are source-specific (GrünstromIndex and Energy-Charts in StromGedacht Widget). Another module
may leave them out.

### Scale of `state` (binding)

| Value | Meaning |
|---|---|
| −1 | Super green: use electricity preferably now, especially a lot of renewable energy in the grid |
| 1 | Green: use electricity as usual |
| 2 | Yellow: strained grid situation |
| 3 | Orange: reduce consumption |
| 4 | Red: avoid consumption |

If your signal has a different scale, map it onto these values. `ecSignal` (if delivered): −1 red (grid bottleneck), 0 red,
1 yellow, 2 green.

### Example answer

```php
[
    'contractType'    => 'gridsignal',
    'contractVersion' => '1.2',
    'region'          => '12345',
    'state'           => 1,
    'label'           => 'Green',
    'updated'         => 1794209033
]
```

## `<PREFIX>_GetForecast($id, int $From, int $To)` (optional)

Return value: a **list** of periods within `[$From, $To]` (Unix time), sorted ascending. If nothing is known, an **empty
list**, never an exception.

| Field | Meaning |
|---|---|
| `contractType` | `'gridsignal'` |
| `contractVersion` | `'1.2'` |
| `source` | identifier of your source in lower case, for example `'mygrid'` |
| `from`, `to` | start and end of the period (Unix time, `from` < `to`), clipped to `[$From, $To]` |
| `value` | state during the period (float) on the scale of `state` |

You may choose horizon and time grid freely (for StromGedacht they are irregular state segments up to 48 hours). The consumer
must not assume a fixed grid. An empty list does not describe itself, so the consumer detects the type through `GetState`.

## What the consumer does

- It looks through `IPS_GetModuleList` for device modules, builds the prefix and checks `function_exists('<PREFIX>_GetState')`.
- It calls the function only in the slow cycle (minutes), encapsulated and with a time budget. An error makes it skip your
  module for that cycle.
- It accepts only answers with `contractType === 'gridsignal'` and a matching major version (here 1).
- The signal is a **recommendation, not an obligation**: a consumer may overrule it, for example because of §14a or direct marketing.

Note: the §14a module of the NRG-Stack also has a function `GetState`, but with a different `contractType`. Always tell them
apart by `contractType`, never by the function name.

## Checklist for your module

- [ ] `GetState()` is **fast and free of side effects**: only read variables and properties, query nothing from the network or device.
- [ ] The return value is an array with `contractType`, `contractVersion`, `state` and `updated`.
- [ ] `state` is `null` or a number of the scale, never text.
- [ ] Without data it returns `state => null` and `updated => 0`, no exception.
- [ ] `GetForecast()` (if present) returns an empty list when data is missing.
- [ ] The module also runs without any other NRG-Stack module.

A reference implementation is StromGedacht Widget in this repository (`StromGedachtWidget/module.php`, functions `GetState()`
and `GetForecast()`).
