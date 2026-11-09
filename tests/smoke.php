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
function SGW_GetDataActions($id) { return $GLOBALS['__instancesById'][$id]->GetDataActions(); }

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

    // Translate()-Stub wie die Symcon-Kernfunktion: sucht den Schlüssel in der locale.json des
    // Moduls (Verzeichnis der Klassendatei); unbekannte Schlüssel und Sprache 'en' liefern den
    // Schlüssel selbst zurück. Sprache über $GLOBALS['__lang'] ('de' ist Standard, damit die
    // bestehenden deutschen Prüfungen unverändert gelten). Aufrufe werden gezählt (Stolperstein 24).
    public function Translate($text)
    {
        $GLOBALS['__translateCalls'] = ($GLOBALS['__translateCalls'] ?? 0) + 1;
        $GLOBALS['__translateKeys'][$text] = true;
        if (($GLOBALS['__lang'] ?? 'de') !== 'de') {
            return $text;
        }
        $dir = dirname((new ReflectionClass($this))->getFileName());
        if (!isset($GLOBALS['__locale'][$dir])) {
            $json = json_decode((string) file_get_contents($dir . '/locale.json'), true);
            $GLOBALS['__locale'][$dir] = $json['translations']['de'] ?? [];
        }
        return $GLOBALS['__locale'][$dir][$text] ?? $text;
    }
    public function SendDebug($caption, $message, $format)
    {
        if (getenv('SMOKE_DEBUG')) {
            echo "    DEBUG [$caption] $message\n";
        }
    }
}

require __DIR__ . '/../StromGedachtWidget/module.php';
require __DIR__ . '/../StromGedachtTile/module.php';

// Emuliert die Symcon-Konsole: Sie übersetzt Beschriftungen eines Formulars (caption/label/suffix/prefix
// und Status-/Options-Texte) anhand der locale.json selbst (Store-Checkliste Punkt 25) - das Modul
// liefert deshalb englischen Quelltext. Für Prüfungen auf die deutsche Darstellung.
function consoleForm(IPSModule $module, string $json): array
{
    $walk = function ($node) use (&$walk, $module) {
        if (!is_array($node)) {
            return $node;
        }
        foreach ($node as $k => $v) {
            if (in_array($k, ['caption', 'label', 'suffix', 'prefix'], true) && is_string($v)) {
                $node[$k] = $module->Translate($v);
            } else {
                $node[$k] = $walk($v);
            }
        }
        return $node;
    };
    return $walk(json_decode($json, true));
}

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

// Später hinzugekommene Instanz übernimmt den Stand bereits registrierter Geschwister-Instanzen
// (A/B, oben bereits bestätigt) beim ÖFFNEN des Formulars - ganz ohne selbst je Ack* aufzurufen.
// Nicht in ApplyChanges(): dort sind die Geschwister beim Neuladen von Modulen evtl. noch nicht
// geladen (Store-Checkliste Punkt 27). Muss dafür NICHT selbst vorher registriert sein.
$sibC = new StromGedachtWidget($noSourceProps);
$sibC->Create();
$sibC->ApplyChanges();
$notYet = $sibC->ReadAttributeBoolean('PurposeIntroGone') === false
    && $sibC->ReadAttributeBoolean('ForumHintGone') === false;
$sibC->GetConfigurationForm();
$ok = $notYet
    && $sibC->ReadAttributeBoolean('PurposeIntroGone') === true
    && $sibC->ReadAttributeBoolean('ForumHintGone') === true
    && $sibC->ReadAttributeString('SeenNews') === $moduleVersion;
printf("%s Ausblenden-Teilen: neu hinzugekommene Instanz C übernimmt den Stand der Geschwister beim Öffnen des Formulars (nicht in ApplyChanges, Punkt 27)\n", $ok ? 'PASS' : 'FAIL');
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
    return consoleForm($tile, $tile->GetConfigurationForm());
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

// "🪟 Formular: Aufbau" (SUITE.md 3a) in der Kachel: Reihenfolge, Aufklapp-Zustand, visible:false statt
// Weglassen, Rückfall bei unbekanntem/fehlendem Schlüssel, Lage des Aufbau-Panels, Reset-Knopf.
function tileLayoutRows(array $overrides): string
{
    return json_encode($overrides, JSON_UNESCAPED_UNICODE);
}
function tileTopPanels(array $props): array
{
    $out = [];
    foreach (tileForm($props)['elements'] as $el) {
        if (is_array($el) && ($el['type'] ?? '') === 'ExpansionPanel') {
            $out[] = $el;
        }
    }
    return $out;
}
function panelNames(array $panels): array
{
    return array_map(function ($p) {
        return $p['name'] ?? ('#' . preg_replace('/\s+/u', '', $p['caption']));
    }, $panels);
}

$GLOBALS['__instancesByModule'][GUID_WIDGET_TEST] = [];
// 1) Standard: Reihenfolge Farben, Flächen, Schrift; keines ausgeblendet; kein erzwungener Aufklapp-Zustand
$panels = tileTopPanels($tileBase);
$names = panelNames($panels);
$fach = array_values(array_filter($panels, function ($p) { return in_array($p['name'] ?? '', ['PanelColors', 'PanelSurface', 'PanelFont'], true); }));
$ok = array_column($fach, 'name') === ['PanelColors', 'PanelSurface', 'PanelFont']
    && !array_filter($fach, function ($p) { return array_key_exists('visible', $p) || array_key_exists('expanded', $p); });
printf("%s Formular-Aufbau: Standard = Reihenfolge Farben/Flächen/Schrift, nichts ausgeblendet, Aufklapp-Zustand unverändert\n", $ok ? 'PASS' : 'FAIL');
if (!$ok) {
    $failures++;
}

// 2) Lage: feste Panels oben (Wozu, Neu, Doku), Fachpanels, Aufbau, Forum, Über dieses Modul unten
$names = array_column($panels, 'name');
$posOf = function (string $n) use ($names) { $i = array_search($n, $names, true); return $i === false ? -1 : $i; };
$captions = array_map(function ($p) { return $p['caption']; }, $panels);
$ok = $posOf('PurposeIntroPanel') < $posOf('NewsPanel') && $posOf('NewsPanel') < $posOf('DocPanel')
    && $posOf('DocPanel') < $posOf('PanelColors') && $posOf('PanelFont') < $posOf('LayoutPanel')
    && $posOf('LayoutPanel') < $posOf('ForumHintPanel')
    && strpos((string) end($captions), 'Über dieses Modul') !== false
    && strpos((string) $panels[$posOf('LayoutPanel')]['caption'], 'Formular: Aufbau') !== false
    && ($panels[$posOf('LayoutPanel')]['expanded'] ?? null) === false;
printf("%s Formular-Aufbau: Panel liegt nach den Fachpanels, vor dem Forum-Hinweis, eingeklappt; feste Panels oben/unten bleiben\n", $ok ? 'PASS' : 'FAIL');
if (!$ok) {
    $failures++;
}

// 3) Reihenfolge ändern: Schrift an Position 1, Farben 2, Flächen 3
$p = ['FormLayout' => tileLayoutRows([
    ['Key' => 'font', 'Pos' => 1], ['Key' => 'colors', 'Pos' => 2], ['Key' => 'surface', 'Pos' => 3],
])] + $tileBase;
$fach = array_values(array_filter(tileTopPanels($p), function ($x) { return in_array($x['name'] ?? '', ['PanelColors', 'PanelSurface', 'PanelFont'], true); }));
$ok = array_column($fach, 'name') === ['PanelFont', 'PanelColors', 'PanelSurface'];
printf("%s Formular-Aufbau: Position bestimmt die Reihenfolge der Fachpanels\n", $ok ? 'PASS' : 'FAIL');
if (!$ok) {
    $failures++;
}

// 4) Aufgeklappt / Zugeklappt / Ausgeblendet; Widerspruch: ausgeblendet vor zugeklappt vor aufgeklappt
$p = ['FormLayout' => tileLayoutRows([
    ['Key' => 'colors', 'Opened' => true, 'Pos' => 1],
    ['Key' => 'surface', 'Opened' => true, 'Closed' => true, 'Pos' => 2],
    ['Key' => 'font', 'Opened' => true, 'Closed' => true, 'Hidden' => true, 'Pos' => 3],
])] + $tileBase;
$by = [];
foreach (tileTopPanels($p) as $x) {
    $by[$x['name'] ?? ''] = $x;
}
$ok = ($by['PanelColors']['expanded'] ?? null) === true
    && ($by['PanelSurface']['expanded'] ?? null) === false
    && ($by['PanelFont']['visible'] ?? null) === false
    && isset($by['PanelFont']['items']) && count($by['PanelFont']['items']) > 0
    && strpos($by['LayoutPanel']['caption'], '(1 ausgeblendet)') !== false;
printf("%s Formular-Aufbau: Aufgeklappt/Zugeklappt, Ausgeblendet = visible:false MIT Inhalt, Vorrang bei Widerspruch, Zähler in der Caption\n", $ok ? 'PASS' : 'FAIL');
if (!$ok) {
    $failures++;
}

// 5) Rückfall: unbekannter Schlüssel ignoriert, fehlender (neues Fachpanel) = Standard und Standardposition,
//    kaputtes JSON = Standard
$p = ['FormLayout' => tileLayoutRows([['Key' => 'gibt-es-nicht', 'Hidden' => true, 'Pos' => 1], ['Key' => 'font', 'Pos' => 1]])] + $tileBase;
$fach = array_values(array_filter(tileTopPanels($p), function ($x) { return in_array($x['name'] ?? '', ['PanelColors', 'PanelSurface', 'PanelFont'], true); }));
// Farben: kein Eintrag -> Standardposition 1; Schrift: Pos 1 (Gleichstand, stabil über Ursprungsindex danach); Flächen: Standardposition 2
$ok = array_column($fach, 'name') === ['PanelColors', 'PanelFont', 'PanelSurface']
    && !array_filter($fach, function ($x) { return array_key_exists('visible', $x); });
$broken = array_column(array_values(array_filter(tileTopPanels(['FormLayout' => '{kaputt'] + $tileBase), function ($x) { return in_array($x['name'] ?? '', ['PanelColors', 'PanelSurface', 'PanelFont'], true); })), 'name');
$ok = $ok && $broken === ['PanelColors', 'PanelSurface', 'PanelFont'];
printf("%s Formular-Aufbau: unbekannter Schlüssel ignoriert, fehlender/kaputter Eintrag fällt auf Standard zurück\n", $ok ? 'PASS' : 'FAIL');
if (!$ok) {
    $failures++;
}

// 6) Reset-Knopf: Klartext zurück, Liste per UpdateFormField auf Standard, keine Selbstpersistenz
$GLOBALS['__formFieldCalls'] = [];
$resetTile = new StromGedachtTile($tileBase);
$resetTile->Create();
$text = $resetTile->ResetFormLayout();
$call = $GLOBALS['__formFieldCalls'][0] ?? null;
$rows = $call ? json_decode($call[2], true) : [];
$ok = strpos($text, 'Übernehmen') !== false && $call !== null && $call[0] === 'FormLayout' && $call[1] === 'values'
    && array_column($rows, 'Key') === ['colors', 'surface', 'font'] && array_column($rows, 'Pos') === [1, 2, 3]
    && !array_filter($rows, function ($r) { return $r['Opened'] || $r['Closed'] || $r['Hidden']; })
    && count($GLOBALS['__formFieldCalls']) === 1;
printf("%s Formular-Aufbau: Reset setzt die Liste per UpdateFormField auf Standard und meldet Klartext\n", $ok ? 'PASS' : 'FAIL');
if (!$ok) {
    $failures++;
}

// 7) Die Liste im Aufbau-Panel: Schlüsselspalte unsichtbar mit save:true, Property FormLayout, Standardwert '[]'
$layoutPanel = null;
foreach (tileTopPanels($tileBase) as $x) {
    if (($x['name'] ?? '') === 'LayoutPanel') {
        $layoutPanel = $x;
    }
}
$list = null;
foreach ($layoutPanel['items'] ?? [] as $it) {
    if (($it['name'] ?? '') === 'FormLayout') {
        $list = $it;
    }
}
$keyCol = null;
foreach ($list['columns'] ?? [] as $c) {
    if (($c['name'] ?? '') === 'Key') {
        $keyCol = $c;
    }
}
$ok = $list !== null && ($list['loadValuesFromConfiguration'] ?? true) === false && count($list['values']) === 3
    && $keyCol !== null && ($keyCol['save'] ?? false) === true && ($keyCol['visible'] ?? true) === false
    && (function () use ($tileBase) { $t = new StromGedachtTile($tileBase); $t->Create(); return $t->ReadPropertyString('FormLayout'); })() === '[]';
printf("%s Formular-Aufbau: Liste mit unsichtbarem, gespeichertem Schlüssel, Werte direkt im Formular\n", $ok ? 'PASS' : 'FAIL');
if (!$ok) {
    $failures++;
}

// HTTP-Antwortkodierung (Befund 23.10.2026): Die StromGedacht-API antwortete bei ca. jedem sechsten
// Abruf mit Content-Encoding: br, obwohl keine Kompression angefordert war; PHP-Streams entpacken
// das nicht, der Body begann mit Binärbytes und der Stand blieb leer ("State fehlt"). Geprüft
// gegen einen lokalen Testserver (php -S), unabhängig von der echten API.
$port = 18000 + (getmypid() % 1000);
$serverProc = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/encoding-router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $serverPipes
);
$serverUp = false;
for ($i = 0; $i < 50 && $serverProc !== false; $i++) {
    $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($sock) {
        fclose($sock);
        $serverUp = true;
        break;
    }
    usleep(100000);
}
if (!$serverUp) {
    printf("FAIL HTTP-Antwortkodierung: Testserver nicht gestartet\n");
    $failures++;
} else {
    $httpModule = new StromGedachtWidget(['UpdateInterval' => 300]);
    $tryGet = new ReflectionMethod($httpModule, 'TryHttpGet');
    $base = 'http://127.0.0.1:' . $port;

    [$code, $body] = $tryGet->invoke($httpModule, $base . '/echo-encoding', false);
    $ok = $code === 200 && (json_decode((string) $body, true)['acceptEncoding'] ?? null) === 'identity';
    printf("%s HTTP: Anfrage fordert ausdrücklich Accept-Encoding: identity an\n", $ok ? 'PASS' : 'FAIL');
    if (!$ok) {
        $failures++;
    }

    [$code, $body] = $tryGet->invoke($httpModule, $base . '/plain', false);
    $ok = $code === 200 && json_decode((string) $body, true) === ['state' => 1];
    printf("%s HTTP: unkomprimierte Antwort wird unverändert geliefert\n", $ok ? 'PASS' : 'FAIL');
    if (!$ok) {
        $failures++;
    }

    [$code, $body] = $tryGet->invoke($httpModule, $base . '/gzip', false);
    $ok = $code === 200 && json_decode((string) $body, true) === ['state' => 1];
    printf("%s HTTP: trotzdem gelieferte gzip-Antwort wird entpackt\n", $ok ? 'PASS' : 'FAIL');
    if (!$ok) {
        $failures++;
    }

    [$code, $body] = $tryGet->invoke($httpModule, $base . '/br', false);
    $ok = $code === 200 && $body === null;
    printf("%s HTTP: nicht entpackbare Brotli-Antwort wird als Fehler (null) gemeldet statt als Binärmüll durchgereicht\n", $ok ? 'PASS' : 'FAIL');
    if (!$ok) {
        $failures++;
    }

    // Ganzer Abrufweg (HttpGet): hartnäckiger Server liefert trotz "identity" Brotli, gibt aber nach
    // ausdrücklicher gzip-Anforderung nach -> der Abruf gelingt im selben Lauf, ohne Datenlücke.
    $httpGet = new ReflectionMethod($httpModule, 'HttpGet');
    [$code, $body] = $httpGet->invoke($httpModule, $base . '/br-unless-gzip');
    $ok = $code === 200 && json_decode((string) $body, true) === ['state' => 1];
    printf("%s HTTP: Brotli trotz identity -> sofortige Wiederholung mit gzip liefert die Daten\n", $ok ? 'PASS' : 'FAIL');
    if (!$ok) {
        $failures++;
    }

    [$code, $body] = $httpGet->invoke($httpModule, $base . '/br');
    $ok = $body === null;
    printf("%s HTTP: Server liefert immer Brotli -> sauberer Fehler (null) statt Binärmüll, keine Endlosschleife\n", $ok ? 'PASS' : 'FAIL');
    if (!$ok) {
        $failures++;
    }
}
if ($serverProc !== false) {
    proc_terminate($serverProc);
    proc_close($serverProc);
}

// ---------------------------------------------------------------------------
// Zweisprachigkeit (Englisch im Quelltext, Deutsch in locale.json - SUITE.md Punkt 9, Store-Checkliste 25)
// ---------------------------------------------------------------------------
$unq = function (string $t): string { return str_replace(["\\'", '\\\\'], ["'", '\\'], $t); };
// Alle Quelltext-Schlüssel eines Moduls einsammeln: Translate()-Literale, Konstanten-Texte,
// 'caption' => '...'-Literale im PHP-Formular, form.json-Texte und {{t:...}}-Token der Kachel.
$collectKeys = function (string $dir) use ($unq): array {
    $src = (string) file_get_contents($dir . '/module.php');
    $q = "'((?:[^'\\\\]|\\\\.)*)'";
    $keys = [];
    preg_match_all('/Translate\(\s*' . $q . '/', $src, $m);
    foreach ($m[1] as $t) { $keys[] = $unq($t); }
    preg_match_all("/'caption' => " . $q . '/', $src, $m);
    foreach ($m[1] as $t) { $keys[] = $unq($t); }
    // Rückfallwerte wie Translate(self::X[$i] ?? 'Unknown')
    preg_match_all('/Translate\([^;\n]*?\?\? *' . $q . '/', $src, $m);
    foreach ($m[1] as $t) { $keys[] = $unq($t); }
    foreach (['SG_LABELS', 'SG_TEXTS', 'EC_LABELS', 'EC_TEXTS', 'NEWS_ITEMS'] as $const) {
        if (preg_match('/const ' . $const . ' = \[(.*?)\n?\s*\];/s', $src, $block)) {
            $pattern = $const === 'NEWS_ITEMS' ? '/' . $q . '/' : '/=> *' . $q . '/';
            preg_match_all($pattern, $block[1], $m);
            foreach ($m[1] as $t) { $keys[] = $unq($t); }
        }
    }
    $walk = function ($node) use (&$walk, &$keys) {
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                if (in_array($k, ['caption', 'suffix', 'label', 'prefix'], true) && is_string($v)) { $keys[] = $v; } else { $walk($v); }
            }
        }
    };
    $walk(json_decode((string) file_get_contents($dir . '/form.json'), true));
    if (is_file($dir . '/module.html')) {
        preg_match_all('/\{\{t:(.*?)\}\}/u', (string) file_get_contents($dir . '/module.html'), $m);
        foreach ($m[1] as $t) { $keys[] = $t; }
    }
    // reine Zeichen-/Zahlenketten (Trenner, Bullet, Einheiten) sind keine übersetzbaren Texte
    return array_values(array_unique(array_filter($keys, function ($k) { return preg_match('/\p{L}/u', $k) === 1; })));
};
$germanWords = '/\b(nicht|und|für|der|die|das|mit|wird|ist|eine|oder|bei|zum|zur|noch|bitte|keine|wenn|dann)\b|[äöüß]/iu';
$productWords = ['GrünstromIndex', 'Baden-Württemberg', 'Württemberg', 'MIT'];
foreach (['StromGedachtWidget' => 'Widget', 'StromGedachtTile' => 'Kachel'] as $dirName => $label) {
    $dir = __DIR__ . '/../' . $dirName;
    $loc = json_decode((string) file_get_contents($dir . '/locale.json'), true)['translations']['de'] ?? null;
    $keys = $collectKeys($dir);
    $missing = array_values(array_filter($keys, function ($k) use ($loc) { return !isset($loc[$k]); }));
    $ok = is_array($loc) && count($missing) === 0;
    printf("%s Zweisprachigkeit %s: alle %d Quelltext-Schlüssel stehen in locale.json%s\n", $ok ? 'PASS' : 'FAIL', $label, count($keys), $ok ? '' : ' - fehlt: ' . implode(' | ', array_map(function ($k) { return mb_substr($k, 0, 60); }, $missing)));
    if (!$ok) { $failures++; }

    $unused = array_values(array_diff(array_keys($loc ?? []), $keys));
    // Schlüssel, die nur dynamisch zusammengesetzt werden, dürfen ungenutzt wirken - nur melden
    if ($unused) {
        printf("INFO Zweisprachigkeit %s: %d locale.json-Einträge ohne gefundenen Quelltext-Bezug: %s\n", $label, count($unused), implode(' | ', array_map(function ($k) { return mb_substr($k, 0, 40); }, $unused)));
    }

    $badSource = [];
    foreach ($keys as $k) {
        $clean = str_replace($productWords, '', $k);
        if (preg_match($germanWords, $clean) === 1) { $badSource[] = mb_substr($k, 0, 60); }
    }
    $ok = count($badSource) === 0;
    printf("%s Zweisprachigkeit %s: Quelltext-Schlüssel sind Englisch (kein deutsches Wort/Umlaut)%s\n", $ok ? 'PASS' : 'FAIL', $label, $ok ? '' : ' - verdächtig: ' . implode(' | ', $badSource));
    if (!$ok) { $failures++; }

    // Deutsch darf sich nicht selbst widersprechen: gleiche Platzhalter wie im Schlüssel
    $badPh = [];
    foreach ($loc ?? [] as $en => $de) {
        preg_match_all('/%(?:\d\$)?[sd%]/', $en, $a);
        preg_match_all('/%(?:\d\$)?[sd%]/', $de, $b);
        $x = $a[0]; $y = $b[0]; sort($x); sort($y);
        if ($x !== $y && $en !== $de) { $badPh[] = mb_substr($en, 0, 50); }
    }
    $ok = count($badPh) === 0;
    printf("%s Zweisprachigkeit %s: Platzhalter (%%s/%%d) in Englisch und Deutsch gleich%s\n", $ok ? 'PASS' : 'FAIL', $label, $ok ? '' : ' - abweichend: ' . implode(' | ', $badPh));
    if (!$ok) { $failures++; }
}

// Kachel-Token: Übersetzungen stehen in HTML UND in JavaScript-Zeichenketten -> keine Anführungszeichen,
// Backslashes, Backticks, Zeilenumbrüche oder HTML-Tags (sonst bricht die Kachel nach der Übersetzung).
$tileLoc = json_decode((string) file_get_contents(__DIR__ . '/../StromGedachtTile/locale.json'), true)['translations']['de'];
preg_match_all('/\{\{t:(.*?)\}\}/u', (string) file_get_contents(__DIR__ . '/../StromGedachtTile/module.html'), $tokens);
$badTok = [];
foreach (array_unique($tokens[1]) as $k) {
    foreach ([$k, $tileLoc[$k] ?? ''] as $variant) {
        if (preg_match('/[\'"\\\\`\r\n]|<[a-zA-Z\/!]|\$\{/', $variant) === 1) { $badTok[] = mb_substr($k, 0, 40); }
    }
}
$ok = count($badTok) === 0 && count($tokens[1]) > 0;
printf("%s Zweisprachigkeit Kachel: %d Token ohne Zeichen, die HTML/JavaScript brechen%s\n", $ok ? 'PASS' : 'FAIL', count(array_unique($tokens[1])), $ok ? '' : ' - kritisch: ' . implode(' | ', $badTok));
if (!$ok) { $failures++; }

// Englisch: die ausgelieferten Formulare und die Kachel enthalten kein Deutsch
$GLOBALS['__lang'] = 'en';
$GLOBALS['__instancesByModule'][GUID_WIDGET_TEST] = [];
$enWidget = new StromGedachtWidget($noSourceProps);
$enWidget->Create();
$enWidgetForm = consoleForm($enWidget, $enWidget->GetConfigurationForm());
$enTile = new StromGedachtTile($tileBase);
$enTile->Create();
$enTileForm = consoleForm($enTile, $enTile->GetConfigurationForm());
$flatten = function ($node) use (&$flatten): array {
    $out = [];
    if (is_array($node)) {
        foreach ($node as $k => $v) {
            if (is_string($v) && in_array($k, ['caption', 'label', 'suffix', 'prefix'], true)) { $out[] = $v; } else { $out = array_merge($out, $flatten($v)); }
        }
    }
    return $out;
};
foreach (['Widget' => $enWidgetForm, 'Kachel' => $enTileForm] as $label => $form) {
    $texts = $flatten($form);
    $bad = [];
    foreach ($texts as $t) {
        if (preg_match($germanWords, str_replace($productWords, '', $t)) === 1) { $bad[] = mb_substr($t, 0, 60); }
    }
    $ok = count($texts) > 10 && count($bad) === 0;
    printf("%s Englisch: Formular %s (%d Texte) ohne deutsche Reste%s\n", $ok ? 'PASS' : 'FAIL', $label, count($texts), $ok ? '' : ' - gefunden: ' . implode(' | ', $bad));
    if (!$ok) { $failures++; }
}

// Kachel in beiden Sprachen: Token ersetzt, Sprache folgt Symcon
$tw = makeTestWidget('Messung', ['State' => 1, 'GSI' => 70.0, 'ECSignal' => 2, 'ECShare' => 40.5]);
$GLOBALS['__instancesByModule'][GUID_WIDGET_TEST] = [$tw->InstanceID];
foreach (['en' => ['Edit rule', 'Updated:'], 'de' => ['Regel bearbeiten', 'Aktualisiert:']] as $lang => $expect) {
    $GLOBALS['__lang'] = $lang;
    $t = new StromGedachtTile($tileBase);
    $t->Create();
    $html = (string) $t->GetVisualizationTile();
    $ok = strpos($html, '{{t:') === false && strpos($html, $expect[0]) !== false && strpos($html, $expect[1]) !== false;
    printf("%s Kachel (%s): Token ersetzt, Texte in der Symcon-Sprache\n", $ok ? 'PASS' : 'FAIL', $lang);
    if (!$ok) { $failures++; }
}

// Spalten/GetState in beiden Sprachen: Bezeichnungen und Dezimalzeichen folgen der Sprache
$ref = new ReflectionMethod(StromGedachtTile::class, 'BuildColumns');
$GLOBALS['__lang'] = 'en';
$t = new StromGedachtTile($tileBase);
$t->Create();
$colsEn = $ref->invoke($t, $tw->InstanceID);
$GLOBALS['__lang'] = 'de';
$colsDe = $ref->invoke($t, $tw->InstanceID);
$ok = ($colsEn[0]['label'] ?? '') === 'Green' && ($colsDe[0]['label'] ?? '') === 'Grün'
    && strpos((string) ($colsEn[2]['text'] ?? ''), 'renewable share: 40.5 %') !== false
    && strpos((string) ($colsDe[2]['text'] ?? ''), 'EE-Anteil: 40,5 %') !== false;
printf("%s Kachel-Spalten: Ampel-Bezeichnung und Dezimalzeichen folgen der Sprache (en: %s | de: %s)\n", $ok ? 'PASS' : 'FAIL', $colsEn[2]['text'] ?? '?', $colsDe[2]['text'] ?? '?');
if (!$ok) { $failures++; }

// Stolperstein 24: wenige Translate()-Aufrufe je Kachel-Lauf (kein Hunderter-Aufruf je Timer-/Tile-Lauf)
$GLOBALS['__lang'] = 'de';
$GLOBALS['__translateCalls'] = 0;
$ref->invoke($t, $tw->InstanceID);
$callsColumns = $GLOBALS['__translateCalls'];
$GLOBALS['__translateCalls'] = 0;
$start = microtime(true);
$gs = new StromGedachtWidget(['EnableStromGedacht' => true, 'EnableGSI' => false, 'EnableEnergyCharts' => true, 'ZipCode' => '', 'UpdateInterval' => 300]);
$gs->Create();
$gs->status = IS_ACTIVE;
$gs->SetValue('State', 1);
$gs->SetValue('ECSignal', 2);
$gs->SetValue('ECShare', 40.5);
$gs->GetState();
$callsState = $GLOBALS['__translateCalls'];
$ok = $callsColumns <= 12 && $callsState <= 12;
printf("%s Stolperstein 24: Translate()-Aufrufe je Lauf klein (Kachel-Spalten %d, GetState %d, <= 12)\n", $ok ? 'PASS' : 'FAIL', $callsColumns, $callsState);
if (!$ok) { $failures++; }
$GLOBALS['__lang'] = 'de';

exit($failures === 0 ? 0 : 1);
