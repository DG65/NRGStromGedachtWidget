<?php

// Smoke-Test außerhalb von IP-Symcon: simuliert die IPSModule-Basisklasse
// und ruft Update() mit verschiedenen Quellen-Kombinationen gegen die
// echten APIs auf.
// Aufruf: php tests/smoke.php

declare(strict_types=1);

const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT = 2;
const VARIABLETYPE_STRING = 3;
const KR_READY = 10103;
const IPS_KERNELSTARTED = 10001;
const IS_ACTIVE = 102;
const IS_INACTIVE = 104;

function IPS_VariableProfileExists($name) { return true; }
function IPS_CreateVariableProfile($name, $type) {}
function IPS_SetVariableProfileAssociation($name, $value, $caption, $icon, $color) {}
function IPS_SetVariableProfileValues($name, $min, $max, $step) {}
function IPS_SetVariableProfileDigits($name, $digits) {}
function IPS_SetVariableProfileText($name, $prefix, $suffix) {}
function IPS_GetKernelRunlevel() { return KR_READY; }

// Instanzregister für das Ausblenden-Teilen (PropagateDismiss()/AdoptDismissFromSibling()):
// __instancesByModule simuliert IPS_GetInstanceListByModuleID() (Test registriert Instanzen
// explizit, wo Geschwister-Verhalten geprüft wird - bleibt sonst leer, bestehende Fälle bleiben
// unberührt), __instancesById simuliert den generierten SGW_*-Funktionsaufruf auf eine andere
// Instanz (echtes IP-Symcon generiert PREFIX_Methode($id,...)-Wrapper automatisch, hier von Hand).
$GLOBALS['__instancesByModule'] = [];
$GLOBALS['__instancesById'] = [];
$GLOBALS['__formFieldCalls'] = [];
function IPS_GetInstanceListByModuleID($guid) { return $GLOBALS['__instancesByModule'][$guid] ?? []; }
function IPS_InstanceExists($id) { return isset($GLOBALS['__instancesById'][$id]); }
function SGW_AdoptDismissState($id, $what, $value) { $GLOBALS['__instancesById'][$id]->AdoptDismissState($what, $value); }
function SGW_GetDismissState($id) { return $GLOBALS['__instancesById'][$id]->GetDismissState(); }

// Fremde Zielvariablen (für DataActions-Regeln), für die GetDataActions()/
// GetConfigurationForm() Namen auflösen müssen - Register je Vid.
const VARIABLETYPE_BOOLEAN = 0;
$GLOBALS['__testVariables'] = [
    9001 => ['name' => 'Wallbox', 'path' => 'Test.Wallbox'],
    9002 => ['name' => 'Pool-Pumpe', 'path' => 'Test.Pool-Pumpe'],
];
function IPS_VariableExists($vid) { return isset($GLOBALS['__testVariables'][$vid]); }
function IPS_GetName($vid) { return $GLOBALS['__testVariables'][$vid]['name'] ?? ('#' . $vid); }
function IPS_GetLocation($vid) { return $GLOBALS['__testVariables'][$vid]['path'] ?? ('#' . $vid); }
function IPS_GetVariable($vid) { return ['VariableType' => VARIABLETYPE_BOOLEAN, 'VariableAction' => 1, 'VariableCustomAction' => 0]; }
function IPS_GetVariableList() { return array_keys($GLOBALS['__testVariables']); }

// Ident->Variablen-ID-Register je Instanz, für GetOwnValue() (IPS_GetObjectIDByIdent + GetValue)
$GLOBALS['__objTree'] = [];
$GLOBALS['__values'] = [];
function IPS_GetObjectIDByIdent($ident, $instanceID) { return $GLOBALS['__objTree'][$instanceID][$ident] ?? false; }
function GetValue($vid) { return $GLOBALS['__values'][$vid] ?? null; }

class IPSModule
{
    public $properties = [];
    public $attributes = [];
    public $values = [];
    public $status = 0;
    public $timer = null;
    public $InstanceID;

    private static $nextVid = 1000;

    public function __construct(array $properties)
    {
        $this->properties = $properties;
        $this->InstanceID = self::$nextVid++;
        $GLOBALS['__instancesById'][$this->InstanceID] = $this;
    }
    public function Create() {}
    public function ApplyChanges() {}
    public function RegisterPropertyBoolean($name, $default) { $this->properties[$name] ??= $default; }
    public function RegisterPropertyInteger($name, $default) { $this->properties[$name] ??= $default; }
    public function RegisterPropertyString($name, $default) { $this->properties[$name] ??= $default; }
    public function ReadPropertyBoolean($name) { return (bool) $this->properties[$name]; }
    public function ReadPropertyInteger($name) { return (int) $this->properties[$name]; }
    public function ReadPropertyString($name) { return (string) $this->properties[$name]; }
    public function RegisterPropertyFloat($name, $default) { $this->properties[$name] ??= $default; }
    public function ReadPropertyFloat($name) { return (float) $this->properties[$name]; }
    public function SetVisualizationType($type) {}
    public function RegisterAttributeBoolean($name, $default) { $this->attributes[$name] ??= $default; }
    public function RegisterAttributeString($name, $default) { $this->attributes[$name] ??= $default; }
    public function RegisterAttributeInteger($name, $default) { $this->attributes[$name] ??= $default; }
    public function ReadAttributeBoolean($name) { return (bool) $this->attributes[$name]; }
    public function ReadAttributeString($name) { return (string) $this->attributes[$name]; }
    public function ReadAttributeInteger($name) { return (int) $this->attributes[$name]; }
    public function WriteAttributeBoolean($name, $value) { $this->attributes[$name] = $value; }
    public function WriteAttributeString($name, $value) { $this->attributes[$name] = $value; }
    public function WriteAttributeInteger($name, $value) { $this->attributes[$name] = $value; }
    public function RegisterTimer($ident, $interval, $script) {}
    public function SetTimerInterval($ident, $interval) { $this->timer = $interval; }
    public function RegisterMessage($sender, $message) {}
    public function MaintainVariable($ident, $name, $type, $profile, $position, $keep)
    {
        // Nur die Ident->Vid-Zuordnung pflegen (für IPS_GetObjectIDByIdent/GetOwnValue);
        // $this->values bleibt bewusst unberührt - das spiegelt weiterhin nur echte
        // SetValue()-Aufrufe wider, damit die bestehenden "wurde wirklich befüllt"-Checks
        // unten unverändert funktionieren.
        if ($keep) {
            $GLOBALS['__objTree'][$this->InstanceID][$ident] ??= self::$nextVid++;
        } else {
            unset($GLOBALS['__objTree'][$this->InstanceID][$ident]);
        }
    }
    public function SetValue($ident, $value)
    {
        $this->values[$ident] = $value;
        if (isset($GLOBALS['__objTree'][$this->InstanceID][$ident])) {
            $GLOBALS['__values'][$GLOBALS['__objTree'][$this->InstanceID][$ident]] = $value;
        }
    }
    public function GetValue($ident) { return $this->values[$ident] ?? null; }
    public function SetStatus($status) { $this->status = $status; }
    public function UpdateFormField($field, $key, $value) { $GLOBALS['__formFieldCalls'][] = [$field, $key, $value]; }

    public function SendDebug($caption, $message, $format)
    {
        if (getenv('SMOKE_DEBUG')) {
            echo "    DEBUG [$caption] $message\n";
        }
    }
}

require __DIR__ . '/../StromGedachtWidget/module.php';
require __DIR__ . '/../StromGedachtTile/module.php';

// [Properties, erwarteter Status, erwartete Variablen, verbotene Variablen]
$cases = [
    'Alle Quellen, gültige PLZ (70173)' => [
        ['EnableStromGedacht' => true, 'EnableGSI' => true, 'EnableEnergyCharts' => true, 'ZipCode' => '70173'],
        IS_ACTIVE, ['State', 'Text', 'GSI', 'ECSignal', 'ECShare', 'Updated', 'Widget'], []
    ],
    'Alle Quellen, PLZ ohne StromGedacht-Daten (10115)' => [
        ['EnableStromGedacht' => true, 'EnableGSI' => true, 'EnableEnergyCharts' => true, 'ZipCode' => '10115'],
        IS_ACTIVE, ['GSI', 'ECSignal', 'Widget'], ['State']
    ],
    'Nur StromGedacht + GSI, PLZ unbekannt (00000)' => [
        ['EnableStromGedacht' => true, 'EnableGSI' => true, 'EnableEnergyCharts' => false, 'ZipCode' => '00000'],
        201, ['Widget'], ['State', 'GSI', 'ECSignal']
    ],
    'Nur Energy-Charts, ohne PLZ' => [
        ['EnableStromGedacht' => false, 'EnableGSI' => false, 'EnableEnergyCharts' => true, 'ZipCode' => ''],
        IS_ACTIVE, ['ECSignal', 'ECShare', 'Widget'], ['State', 'GSI']
    ],
    'Nur StromGedacht, gültige PLZ (70173)' => [
        ['EnableStromGedacht' => true, 'EnableGSI' => false, 'EnableEnergyCharts' => false, 'ZipCode' => '70173'],
        IS_ACTIVE, ['State', 'Text', 'Widget'], ['GSI', 'ECSignal']
    ],
];

$failures = 0;
foreach ($cases as $label => [$props, $expectedStatus, $expectedIdents, $forbiddenIdents]) {
    $module = new StromGedachtWidget($props + ['UpdateInterval' => 300]);
    $module->Create();
    // Bewusst OHNE ApplyChanges() davor: die ruft intern selbst schon Update() auf
    // (siehe module.php) und würde diesen expliziten Aufruf sonst in den neuen
    // Abruf-Cooldown laufen lassen (Rückgabe wäre dann die Wartehinweis-Meldung
    // statt des tatsächlichen Ergebnistexts, den dieser Test prüfen will).
    $module->status = IS_ACTIVE;
    $updateResult = $module->Update();

    $problems = [];
    if ($module->status !== $expectedStatus) {
        $problems[] = 'Status ' . $module->status . ' statt ' . $expectedStatus;
    }
    // Sichtbare Rückmeldung (Verbund-Konvention): Update() muss einen nicht-leeren
    // Ergebnistext mit dem zum Status passenden Icon zurückgeben.
    $expectedIcon = [IS_ACTIVE => '✅', 201 => '⚠️', 202 => '❌'][$expectedStatus] ?? null;
    if (!is_string($updateResult) || $updateResult === '') {
        $problems[] = 'Update() liefert keinen Ergebnistext';
    } elseif ($expectedIcon !== null && strpos($updateResult, $expectedIcon) !== 0) {
        $problems[] = "Update()-Ergebnistext beginnt nicht mit $expectedIcon: $updateResult";
    }
    foreach ($expectedIdents as $ident) {
        if (!array_key_exists($ident, $module->values)) {
            $problems[] = $ident . ' fehlt';
        }
    }
    foreach ($forbiddenIdents as $ident) {
        if (array_key_exists($ident, $module->values)) {
            $problems[] = $ident . ' gesetzt, obwohl nicht erwartet';
        }
    }

    $summary = [];
    foreach (['State', 'GSI', 'ECSignal', 'ECShare'] as $ident) {
        if (isset($module->values[$ident])) {
            $summary[] = $ident . ' = ' . var_export($module->values[$ident], true);
        }
    }

    printf(
        "%s %s — Status %d%s%s\n",
        count($problems) === 0 ? 'PASS' : 'FAIL',
        $label,
        $module->status,
        $summary === [] ? '' : ', ' . implode(', ', $summary),
        $problems === [] ? '' : ' [' . implode('; ', $problems) . ']'
    );
    if (count($problems) > 0) {
        $failures++;
    }
}

// Verbund-Regel 9d (SUITE.md, ausgelöst durch einen ModbusSlave-Vorfall): eine gewollt/
// dauerhaft ruhende Instanz (hier: keine Datenquelle aktiviert) muss IS_INACTIVE (104) sein,
// nicht ein Fehlercode >200 - sonst hält ein systemweiter Integrity-Check/Watchdog einen
// bewusst inaktiven Zustand für kaputt.
{
    $module = new StromGedachtWidget([
        'EnableStromGedacht' => false, 'EnableGSI' => false, 'EnableEnergyCharts' => false,
        'ZipCode' => '', 'UpdateInterval' => 300,
    ]);
    $module->Create();
    $module->ApplyChanges();

    $label = 'ApplyChanges: keine Quelle aktiviert -> Status IS_INACTIVE (104), kein Fehlercode';
    if ($module->status === IS_INACTIVE) {
        printf("PASS %s — Status %d\n", $label, $module->status);
    } else {
        printf("FAIL %s — Status %d statt %d\n", $label, $module->status, IS_INACTIVE);
        $failures++;
    }
}

// [Properties, {Feld => muss null sein?}] - dauerhafte Regression für den NRG-Stack-Vertrag
// SGW_GetState (contractVersion 1.0). Bisher nur mit Wegwerf-Skripten live geprüft; das war
// eine "KI-Krücke" (Ziel 3 im gemeinsamen Zielbild, SUITE.md) - hier jetzt fest verankert.
echo "\n";
$stateCases = [
    'Alle Quellen aktiv (70173)' => [
        ['EnableStromGedacht' => true, 'EnableGSI' => true, 'EnableEnergyCharts' => true, 'ZipCode' => '70173'],
        ['state' => false, 'gsi' => false, 'ecSignal' => false, 'ecShare' => false]
    ],
    'Nur Energy-Charts aktiv' => [
        ['EnableStromGedacht' => false, 'EnableGSI' => false, 'EnableEnergyCharts' => true, 'ZipCode' => ''],
        ['state' => true, 'gsi' => true, 'ecSignal' => false, 'ecShare' => false]
    ],
];

foreach ($stateCases as $label => [$props, $expectNull]) {
    $module = new StromGedachtWidget($props + ['UpdateInterval' => 300]);
    $module->Create();
    // ApplyChanges() ruft intern schon Update() auf - kein zusätzlicher, vom neuen
    // Abruf-Cooldown betroffener Update()-Aufruf nötig, GetState() liest ohnehin
    // dieselben, bereits gesetzten Werte.
    $module->ApplyChanges();
    $module->status = IS_ACTIVE;

    $state = $module->GetState();
    $problems = [];
    if (($state['contractVersion'] ?? null) !== '1.0') {
        $problems[] = 'contractVersion fehlt/falsch: ' . var_export($state['contractVersion'] ?? null, true);
    }
    foreach ($expectNull as $field => $mustBeNull) {
        if (!array_key_exists($field, $state)) {
            $problems[] = "$field fehlt komplett im Ergebnis";
            continue;
        }
        $isNull = $state[$field] === null;
        if ($mustBeNull && !$isNull) {
            $problems[] = "$field sollte null sein (Quelle deaktiviert), ist " . var_export($state[$field], true);
        }
        if (!$mustBeNull && $isNull) {
            $problems[] = "$field ist null, sollte einen Wert haben (Quelle aktiv)";
        }
    }
    if (!is_string($state['label'] ?? null) || $state['label'] === '') {
        $problems[] = 'label fehlt oder leer';
    }
    if (!array_key_exists('updated', $state) || !is_int($state['updated'])) {
        $problems[] = 'updated fehlt oder ist kein int';
    }

    printf(
        "%s GetState: %s — %s\n",
        count($problems) === 0 ? 'PASS' : 'FAIL',
        $label,
        $problems === [] ? json_encode($state) : implode('; ', $problems)
    );
    if (count($problems) > 0) {
        $failures++;
    }
}

// GetForecast: aktuell nur Quelle 'stromgedacht' liefert Einträge (Verbund-Vorgabe)
$forecastModule = new StromGedachtWidget([
    'EnableStromGedacht' => true, 'EnableGSI' => false, 'EnableEnergyCharts' => false,
    'ZipCode' => '70173', 'UpdateInterval' => 300
]);
$forecastModule->Create();
$forecastModule->ApplyChanges();
$entries = $forecastModule->GetForecast(time(), time() + 24 * 3600);

$problems = [];
if (!is_array($entries)) {
    $problems[] = 'kein Array zurückgegeben';
} else {
    foreach ($entries as $i => $entry) {
        if (($entry['contractVersion'] ?? null) !== '1.0') {
            $problems[] = "Eintrag $i: contractVersion falsch";
        }
        if (($entry['source'] ?? null) !== 'stromgedacht') {
            $problems[] = "Eintrag $i: source falsch";
        }
        if (!is_int($entry['from'] ?? null) || !is_int($entry['to'] ?? null)) {
            $problems[] = "Eintrag $i: from/to keine Unix-Timestamps";
        } elseif ($entry['to'] <= $entry['from']) {
            $problems[] = "Eintrag $i: to <= from";
        }
        if (!is_numeric($entry['value'] ?? null)) {
            $problems[] = "Eintrag $i: value kein Zahlenwert";
        }
    }
}
printf(
    "%s GetForecast: StromGedacht 24h-Vorschau — %d Eintrag/Einträge%s\n",
    count($problems) === 0 ? 'PASS' : 'FAIL',
    is_array($entries) ? count($entries) : 0,
    $problems === [] ? '' : ' [' . implode('; ', $problems) . ']'
);
if (count($problems) > 0) {
    $failures++;
}

// GetForecast: nur Energy-Charts aktiv -> keine 'stromgedacht'/'gsi'-Einträge, aber
// Energy-Charts liefert trotzdem (Quellen sind unabhängig voneinander, kein Alles-oder-Nichts)
$forecastModuleDisabled = new StromGedachtWidget([
    'EnableStromGedacht' => false, 'EnableGSI' => false, 'EnableEnergyCharts' => true,
    'ZipCode' => '', 'UpdateInterval' => 300
]);
$forecastModuleDisabled->Create();
$forecastModuleDisabled->ApplyChanges();
$mixedEntries = $forecastModuleDisabled->GetForecast(time(), time() + 24 * 3600);
$sourcesDisabled = is_array($mixedEntries) ? array_unique(array_column($mixedEntries, 'source')) : [];
$ok = is_array($mixedEntries) && count($mixedEntries) > 0
    && !in_array('stromgedacht', $sourcesDisabled, true) && !in_array('gsi', $sourcesDisabled, true)
    && in_array('energycharts', $sourcesDisabled, true);
printf("%s GetForecast: nur Energy-Charts aktiv -> nur diese Quelle im Ergebnis (%s)\n", $ok ? 'PASS' : 'FAIL', implode(', ', $sourcesDisabled));
if (!$ok) {
    $failures++;
}

/** Prüft eine GetForecast()-Ergebnisliste gegen die erwartete source (contractVersion/from<to/value). */
function checkForecastEntries(array $entries, string $expectedSource): array
{
    $problems = [];
    foreach ($entries as $i => $entry) {
        if (($entry['contractVersion'] ?? null) !== '1.0') {
            $problems[] = "Eintrag $i: contractVersion falsch";
        }
        if (($entry['source'] ?? null) !== $expectedSource) {
            $problems[] = "Eintrag $i: source falsch (" . ($entry['source'] ?? '?') . ")";
        }
        if (!is_int($entry['from'] ?? null) || !is_int($entry['to'] ?? null)) {
            $problems[] = "Eintrag $i: from/to keine Unix-Timestamps";
        } elseif ($entry['to'] <= $entry['from']) {
            $problems[] = "Eintrag $i: to <= from";
        }
        if (!is_numeric($entry['value'] ?? null)) {
            $problems[] = "Eintrag $i: value kein Zahlenwert";
        }
    }
    return $problems;
}

// GetForecast: GSI (Corrently, stündliches Raster laut Verbund-Anfrage 27.07.2026 verifiziert)
$gsiForecastModule = new StromGedachtWidget([
    'EnableStromGedacht' => false, 'EnableGSI' => true, 'EnableEnergyCharts' => false,
    'ZipCode' => '70173', 'UpdateInterval' => 300
]);
$gsiForecastModule->Create();
$gsiForecastModule->ApplyChanges();
$gsiEntries = $gsiForecastModule->GetForecast(time(), time() + 24 * 3600);
$problems = is_array($gsiEntries) ? checkForecastEntries($gsiEntries, 'gsi') : ['kein Array zurückgegeben'];
printf(
    "%s GetForecast: GrünstromIndex 24h-Vorschau — %d Eintrag/Einträge%s\n",
    count($problems) === 0 ? 'PASS' : 'FAIL',
    is_array($gsiEntries) ? count($gsiEntries) : 0,
    $problems === [] ? '' : ' [' . implode('; ', $problems) . ']'
);
if (count($problems) > 0) {
    $failures++;
}

// GetForecast: Energy-Charts (15-Minuten-Raster, aber nur begrenztes Zukunftsfenster)
$ecForecastModule = new StromGedachtWidget([
    'EnableStromGedacht' => false, 'EnableGSI' => false, 'EnableEnergyCharts' => true,
    'ZipCode' => '', 'UpdateInterval' => 300
]);
$ecForecastModule->Create();
$ecForecastModule->ApplyChanges();
$ecEntries = $ecForecastModule->GetForecast(time(), time() + 24 * 3600);
$problems = is_array($ecEntries) ? checkForecastEntries($ecEntries, 'energycharts') : ['kein Array zurückgegeben'];
printf(
    "%s GetForecast: Energy-Charts 24h-Vorschau — %d Eintrag/Einträge%s\n",
    count($problems) === 0 ? 'PASS' : 'FAIL',
    is_array($ecEntries) ? count($ecEntries) : 0,
    $problems === [] ? '' : ' [' . implode('; ', $problems) . ']'
);
if (count($problems) > 0) {
    $failures++;
}

// GetForecast: alle drei Quellen aktiv -> gemischte source-Werte im selben Ergebnis
$allForecastModule = new StromGedachtWidget([
    'EnableStromGedacht' => true, 'EnableGSI' => true, 'EnableEnergyCharts' => true,
    'ZipCode' => '70173', 'UpdateInterval' => 300
]);
$allForecastModule->Create();
$allForecastModule->ApplyChanges();
$allEntries = $allForecastModule->GetForecast(time(), time() + 24 * 3600);
$sources = is_array($allEntries) ? array_unique(array_column($allEntries, 'source')) : [];
$ok = is_array($allEntries) && count(array_intersect(['stromgedacht', 'gsi', 'energycharts'], $sources)) === 3;
printf(
    "%s GetForecast: alle drei Quellen aktiv -> Quellen im Ergebnis: %s\n",
    $ok ? 'PASS' : 'FAIL',
    implode(', ', $sources)
);
if (!$ok) {
    $failures++;
}

// Zwei-Regler-Kollisionscheck (EMS_GetControlledVariables, Verbund-Vorschlag 27.07.2026):
// erst ohne EMS im System prüfen (Funktion existiert noch nicht - siehe unten), danach mit.
function makeModuleWithRule(int $target): StromGedachtWidget
{
    $m = new StromGedachtWidget([
        'EnableStromGedacht' => true, 'EnableGSI' => false, 'EnableEnergyCharts' => false,
        'ZipCode' => '70173', 'UpdateInterval' => 300,
        'DataActions' => json_encode([[
            'Active' => true,
            'Conditions' => [['Source' => 'State', 'Op' => 'eq', 'Compare' => '4']],
            'Source' => 'State', 'Op' => 'eq', 'Compare' => '4',
            'Target' => $target, 'Action' => 'off', 'Value' => ''
        ]])
    ]);
    $m->Create();
    return $m;
}

$mNoEms = makeModuleWithRule(9001);
$actionsNoEms = json_decode($mNoEms->GetDataActions(), true);
$formNoEms = json_encode(json_decode($mNoEms->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE);
$ok = ($actionsNoEms[0]['emsConflict'] ?? true) === false && strpos($formNoEms, 'Kollision möglich)') === false
    && strpos($formNoEms, 'ℹ️ Kein EMS im System gefunden') !== false;
printf("%s EMS-Konfliktcheck: kein EMS im System -> kein Konflikt, keine Warnung\n", $ok ? 'PASS' : 'FAIL');
if (!$ok) {
    $failures++;
}

// Ab hier existiert EMS_GetControlledVariables() (bewusst in einem immer-wahren if-Block
// deklariert, nicht auf Top-Level - Top-Level-Funktionen werden von PHP unabhängig von ihrer
// Position im Skript schon beim Parsen deklariert ("gehoisted") und hätten damit auch den
// Test oben verfälscht).
if (true) {
    function EMS_GetControlledVariables()
    {
        return [['variableID' => 9001, 'instanceID' => 1, 'ident' => 'ctl_x', 'purpose' => 'test']];
    }
}

$mConflict = makeModuleWithRule(9001);
$actionsConflict = json_decode($mConflict->GetDataActions(), true);
$formConflict = json_encode(json_decode($mConflict->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE);
$ok = ($actionsConflict[0]['emsConflict'] ?? false) === true
    && strpos($formConflict, 'Kollision möglich)') !== false
    && strpos($formConflict, 'Wallbox') !== false
    && strpos($formConflict, '✅ EMS erkannt') !== false
    && strpos($formConflict, 'Kein EMS im System gefunden') === false;
printf("%s EMS-Konfliktcheck: EMS steuert dieselbe Zielvariable -> Konflikt + Warnung mit Name\n", $ok ? 'PASS' : 'FAIL');
if (!$ok) {
    $failures++;
}

$mNoConflict = makeModuleWithRule(9002);
$actionsNoConflict = json_decode($mNoConflict->GetDataActions(), true);
$formNoConflict = json_encode(json_decode($mNoConflict->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE);
$ok = ($actionsNoConflict[0]['emsConflict'] ?? true) === false
    && strpos($formNoConflict, '✅ EMS erkannt') !== false
    && strpos($formNoConflict, 'Kollision möglich)') === false;
printf("%s EMS-Konfliktcheck: EMS steuert andere Variable -> kein Fehlalarm\n", $ok ? 'PASS' : 'FAIL');
if (!$ok) {
    $failures++;
}

// Ausblenden-Teilen über mehrere Geschwister-Instanzen (SUITE.md "Ausblenden über mehrere
// Instanzen desselben Moduls teilen", 14.09.2026). Keine Quelle aktiviert (Status 104, kein
// echter API-Aufruf) - hier geht es nur um die Attribut-Propagation, nicht um Update().
const GUID_WIDGET_TEST = '{D5A8C3A1-2222-4A55-8888-123456789003}';
$noSourceProps = ['EnableStromGedacht' => false, 'EnableGSI' => false, 'EnableEnergyCharts' => false, 'ZipCode' => '', 'UpdateInterval' => 300];
// MODULE_VERSION ist private - per Reflection lesen statt im Test zu duplizieren (sonst
// veraltet die erwartete Versionsnummer hier bei jedem künftigen Versionsbump lautlos).
$moduleVersion = (new ReflectionClass('StromGedachtWidget'))->getConstant('MODULE_VERSION');

$sibA = new StromGedachtWidget($noSourceProps);
$sibA->Create();
$GLOBALS['__instancesByModule'][GUID_WIDGET_TEST][] = $sibA->InstanceID;
$sibB = new StromGedachtWidget($noSourceProps);
$sibB->Create();
$GLOBALS['__instancesByModule'][GUID_WIDGET_TEST][] = $sibB->InstanceID;
$sibA->status = IS_ACTIVE;
$sibB->status = IS_ACTIVE;

$sibA->AckPurposeIntro();
$sibA->AckForumHint();
$sibA->AckNews();
$ok = $sibB->ReadAttributeBoolean('PurposeIntroGone') === true
    && $sibB->ReadAttributeBoolean('ForumHintGone') === true
    && $sibB->ReadAttributeString('SeenNews') === $moduleVersion;
printf("%s Ausblenden-Teilen: Bestätigung an Instanz A propagiert sofort zu Geschwister-Instanz B\n", $ok ? 'PASS' : 'FAIL');
if (!$ok) {
    $failures++;
}

// Später hinzugekommene Instanz übernimmt beim allerersten ApplyChanges() automatisch den
// Stand bereits registrierter Geschwister-Instanzen (A/B, oben bereits bestätigt) - ganz
// ohne selbst je Ack* aufzurufen. Muss dafür NICHT selbst vorher registriert sein: die
// Registrierung ist nur relevant, damit ANDERE Instanzen diese hier später finden können.
$sibC = new StromGedachtWidget($noSourceProps);
$sibC->Create();
$sibC->ApplyChanges();
$ok = $sibC->ReadAttributeBoolean('PurposeIntroGone') === true
    && $sibC->ReadAttributeBoolean('ForumHintGone') === true
    && $sibC->ReadAttributeString('SeenNews') === $moduleVersion;
printf("%s Ausblenden-Teilen: neu hinzugekommene Instanz C übernimmt beim ersten ApplyChanges() den Stand vorhandener Geschwister-Instanzen\n", $ok ? 'PASS' : 'FAIL');
if (!$ok) {
    $failures++;
}

// Kachel: live berechnete Statuszeile zur Datenquelle (SUITE.md "Verbund-Verbindungen im Formular
// sichtbar machen"). Geprüft wird das ausgelieferte Formular-JSON (rekursiv gesucht, nicht nur
// oberste Ebene) für jeden Zustand: ℹ️ keine Quelle, ✅ eine Quelle automatisch erkannt,
// ⚠️ mehrere ohne Auswahl, ⚠️ verbunden ohne Werte, ✅ manuell gewählt, plus Hinweis auf
// nicht mehr vorhandene gewählte Instanz. Der frühere statische Satz darf nie mehr erscheinen.
function findFormElement(array $elements, string $name): ?array
{
    foreach ($elements as $el) {
        if (!is_array($el)) {
            continue;
        }
        if (($el['name'] ?? '') === $name) {
            return $el;
        }
        if (isset($el['items']) && is_array($el['items'])) {
            $hit = findFormElement($el['items'], $name);
            if ($hit !== null) {
                return $hit;
            }
        }
    }
    return null;
}
function tileForm(array $props): array
{
    $tile = new StromGedachtTile($props);
    $tile->Create();
    return json_decode($tile->GetConfigurationForm(), true);
}
// Sichtbarkeit des Auswahlfelds "Datenquelle" (fehlendes visible = sichtbar)
function tileSelectVisible(array $props): bool
{
    $el = findFormElement(tileForm($props)['elements'], 'SourceInstance');
    return $el !== null && ($el['visible'] ?? true) !== false;
}
// Farbe der Statuszeile "SourceStatus" im ausgelieferten Formular (fehlend = Standardfarbe)
function tileStatusColor(array $props): int
{
    $el = findFormElement(tileForm($props)['elements'], 'SourceStatus');
    return (int) ($el['color'] ?? -1);
}
function tileSourceLine(array $props): string
{
    $form = tileForm($props);
    $el = findFormElement($form['elements'], 'SourceStatus');
    $json = json_encode($form, JSON_UNESCAPED_UNICODE);
    if ($el === null || strpos($json, 'wird automatisch erkannt') !== false || strpos($json, 'Datenquelle wird ermittelt') !== false) {
        return '!! Statuszeile fehlt oder statischer Ersatztext noch im Formular';
    }
    return (string) $el['caption'];
}
function makeTestWidget(string $name, array $idents): StromGedachtWidget
{
    $w = new StromGedachtWidget(['UpdateInterval' => 300]);
    $GLOBALS['__testVariables'][$w->InstanceID] = ['name' => $name, 'path' => $name];
    foreach ($idents as $ident => $value) {
        $vid = 700000 + $w->InstanceID * 10 + count($GLOBALS['__objTree'][$w->InstanceID] ?? []);
        $GLOBALS['__objTree'][$w->InstanceID][$ident] = $vid;
        $GLOBALS['__values'][$vid] = $value;
    }
    return $w;
}
$tileBase = ['SourceInstance' => 0, 'AdoptWidgetName' => true, 'ShowAutomations' => true, 'FontScale' => 1.0,
    'ColorBackground' => -1, 'ColorBox' => -1, 'ColorText' => -1, 'ColorTextMuted' => -1, 'FontFamily' => 'system',
    'ColorSuperGreen' => 0, 'ColorGreen' => 0, 'ColorYellow' => 0, 'ColorOrange' => 0, 'ColorRed' => 0];

$GLOBALS['__instancesByModule'][GUID_WIDGET_TEST] = [];
$line = tileSourceLine($tileBase);
$ok = strpos($line, 'ℹ️ Keine StromGedachtWidget-Instanz gefunden') === 0 && tileSelectVisible($tileBase);
printf("%s Kachel-Statuszeile: keine Quelle vorhanden -> ℹ️, Auswahlfeld sichtbar (%s)\n", $ok ? 'PASS' : 'FAIL', $line);
if (!$ok) {
    $failures++;
}

$tw1 = makeTestWidget('Strom Gedacht Ampel', ['State' => 1, 'GSI' => 20.4, 'ECSignal' => 2, 'ECShare' => 18.7]);
$GLOBALS['__instancesByModule'][GUID_WIDGET_TEST] = [$tw1->InstanceID];
$line = tileSourceLine($tileBase);
$ok = strpos($line, '🔗 ') === 0
    && tileStatusColor($tileBase) === 0x2E8B3D
    && !tileSelectVisible($tileBase)
    && strpos($line, '#' . $tw1->InstanceID . ' „Strom Gedacht Ampel“ (automatisch erkannt)') !== false
    && strpos($line, 'StromGedacht: Grün') !== false && strpos($line, 'GrünstromIndex: 20 %') !== false
    && strpos($line, 'Energy-Charts: Grün') !== false;
printf("%s Kachel-Statuszeile: genau eine Quelle -> 🔗, Auswahlfeld AUSGEBLENDET, Instanz/Name/Werte in der Zeile (%s)\n", $ok ? 'PASS' : 'FAIL', $line);
if (!$ok) {
    $failures++;
}

$tw2 = makeTestWidget('Zweite Region', ['GSI' => 55.0]);
$GLOBALS['__instancesByModule'][GUID_WIDGET_TEST] = [$tw1->InstanceID, $tw2->InstanceID];
$line = tileSourceLine($tileBase);
$ok = strpos($line, '⚠️ ') === 0 && tileSelectVisible($tileBase) && strpos($line, '2 StromGedachtWidget-Instanzen') !== false
    && strpos($line, '#' . $tw1->InstanceID) !== false && strpos($line, '#' . $tw2->InstanceID) !== false;
printf("%s Kachel-Statuszeile: mehrere Quellen ohne Auswahl -> ⚠️, Auswahlfeld sichtbar (%s)\n", $ok ? 'PASS' : 'FAIL', $line);
if (!$ok) {
    $failures++;
}

$line = tileSourceLine(['SourceInstance' => $tw2->InstanceID] + $tileBase);
$ok = strpos($line, '✏️ ') === 0 && tileSelectVisible(['SourceInstance' => $tw2->InstanceID] + $tileBase)
    && tileStatusColor(['SourceInstance' => $tw2->InstanceID] + $tileBase) === -1
    && strpos($line, '(manuell gewählt)') !== false && strpos($line, 'GrünstromIndex: 55 %') !== false;
printf("%s Kachel-Statuszeile: mehrere Quellen, eine manuell gewählt -> ✏️, Auswahlfeld sichtbar (%s)\n", $ok ? 'PASS' : 'FAIL', $line);
if (!$ok) {
    $failures++;
}

$line = tileSourceLine(['SourceInstance' => 99999] + $tileBase);
$ok = strpos($line, '⚠️ Die gewählte Instanz #99999 existiert nicht mehr.') === 0;
printf("%s Kachel-Statuszeile: gewählte Instanz existiert nicht mehr -> ⚠️ (%s)\n", $ok ? 'PASS' : 'FAIL', $line);
if (!$ok) {
    $failures++;
}

// Eigene Wahl bei genau einer Instanz: Feld bleibt sichtbar (✏️ hat Vorrang, nichts wird ausgeblendet)
$GLOBALS['__instancesByModule'][GUID_WIDGET_TEST] = [$tw1->InstanceID];
$ok = tileSelectVisible(['SourceInstance' => $tw1->InstanceID] + $tileBase)
    && strpos(tileSourceLine(['SourceInstance' => $tw1->InstanceID] + $tileBase), '✏️ ') === 0;
printf("%s Kachel: eigene Wahl bei genau einer Instanz -> ✏️, Auswahlfeld bleibt sichtbar\n", $ok ? 'PASS' : 'FAIL');
if (!$ok) {
    $failures++;
}

// onChange: Zeile folgt der Auswahl im offenen Formular (noch ungespeichert)
$GLOBALS['__instancesByModule'][GUID_WIDGET_TEST] = [$tw1->InstanceID, $tw2->InstanceID];
$GLOBALS['__formFieldCalls'] = [];
$onChangeTile = new StromGedachtTile($tileBase);
$onChangeTile->Create();
$onChangeTile->OnChangeSource($tw2->InstanceID);
$call = $GLOBALS['__formFieldCalls'][0] ?? null;
$colorCall = $GLOBALS['__formFieldCalls'][1] ?? null;
$ok = $call !== null && $call[0] === 'SourceStatus' && $call[1] === 'caption'
    && strpos($call[2], '✏️ ') === 0 && strpos($call[2], '#' . $tw2->InstanceID) !== false && strpos($call[2], '(manuell gewählt)') !== false
    && $colorCall !== null && $colorCall[1] === 'color' && $colorCall[2] === -1;
// Gegenprobe: leere Auswahl bei genau einer Instanz = automatisch -> Zeile im onChange grün gesetzt
$GLOBALS['__instancesByModule'][GUID_WIDGET_TEST] = [$tw1->InstanceID];
$GLOBALS['__formFieldCalls'] = [];
$onChangeTile->OnChangeSource(0);
$autoCaption = $GLOBALS['__formFieldCalls'][0][2] ?? '';
$autoColor = $GLOBALS['__formFieldCalls'][1] ?? null;
$ok = $ok && strpos($autoCaption, '🔗 ') === 0 && $autoColor !== null && $autoColor[1] === 'color' && $autoColor[2] === 0x2E8B3D;
$GLOBALS['__instancesByModule'][GUID_WIDGET_TEST] = [$tw1->InstanceID, $tw2->InstanceID];
printf("%s Kachel: onChange aktualisiert Statuszeile und Farbe live (✏️ Standardfarbe, 🔗 grün)\n", $ok ? 'PASS' : 'FAIL');
if (!$ok) {
    $failures++;
}

$tw3 = makeTestWidget('Ohne Werte', []);
$GLOBALS['__instancesByModule'][GUID_WIDGET_TEST] = [$tw3->InstanceID];
$line = tileSourceLine($tileBase);
$ok = strpos($line, '⚠️ ') === 0 && strpos($line, 'liefert aber noch keine Werte') !== false;
printf("%s Kachel-Statuszeile: Quelle gefunden, aber ohne Werte -> ⚠️ (%s)\n", $ok ? 'PASS' : 'FAIL', $line);
if (!$ok) {
    $failures++;
}

exit($failures === 0 ? 0 : 1);
