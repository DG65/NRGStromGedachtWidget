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

function IPS_VariableProfileExists($name) { return true; }
function IPS_CreateVariableProfile($name, $type) {}
function IPS_SetVariableProfileAssociation($name, $value, $caption, $icon, $color) {}
function IPS_SetVariableProfileValues($name, $min, $max, $step) {}
function IPS_SetVariableProfileDigits($name, $digits) {}
function IPS_SetVariableProfileText($name, $prefix, $suffix) {}
function IPS_GetKernelRunlevel() { return KR_READY; }

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
    }
    public function Create() {}
    public function ApplyChanges() {}
    public function RegisterPropertyBoolean($name, $default) { $this->properties[$name] ??= $default; }
    public function RegisterPropertyInteger($name, $default) { $this->properties[$name] ??= $default; }
    public function RegisterPropertyString($name, $default) { $this->properties[$name] ??= $default; }
    public function ReadPropertyBoolean($name) { return (bool) $this->properties[$name]; }
    public function ReadPropertyInteger($name) { return (int) $this->properties[$name]; }
    public function ReadPropertyString($name) { return (string) $this->properties[$name]; }
    public function RegisterAttributeBoolean($name, $default) { $this->attributes[$name] ??= $default; }
    public function RegisterAttributeString($name, $default) { $this->attributes[$name] ??= $default; }
    public function ReadAttributeBoolean($name) { return (bool) $this->attributes[$name]; }
    public function ReadAttributeString($name) { return (string) $this->attributes[$name]; }
    public function WriteAttributeBoolean($name, $value) { $this->attributes[$name] = $value; }
    public function WriteAttributeString($name, $value) { $this->attributes[$name] = $value; }
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

    public function SendDebug($caption, $message, $format)
    {
        if (getenv('SMOKE_DEBUG')) {
            echo "    DEBUG [$caption] $message\n";
        }
    }
}

require __DIR__ . '/../StromGedachtWidget/module.php';

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
    $module->ApplyChanges();
    $module->status = IS_ACTIVE;
    $module->Update();

    $problems = [];
    if ($module->status !== $expectedStatus) {
        $problems[] = 'Status ' . $module->status . ' statt ' . $expectedStatus;
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
    $module->ApplyChanges();
    $module->status = IS_ACTIVE;
    $module->Update();

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

// GetForecast ohne aktivierte StromGedacht-Quelle -> muss leer sein (kein Versehen)
$forecastModuleDisabled = new StromGedachtWidget([
    'EnableStromGedacht' => false, 'EnableGSI' => false, 'EnableEnergyCharts' => true,
    'ZipCode' => '', 'UpdateInterval' => 300
]);
$forecastModuleDisabled->Create();
$forecastModuleDisabled->ApplyChanges();
$emptyEntries = $forecastModuleDisabled->GetForecast(time(), time() + 24 * 3600);
$ok = is_array($emptyEntries) && count($emptyEntries) === 0;
printf("%s GetForecast: StromGedacht deaktiviert -> leeres Ergebnis\n", $ok ? 'PASS' : 'FAIL');
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
$ok = ($actionsNoEms[0]['emsConflict'] ?? true) === false && strpos($formNoEms, 'Kollision möglich)') === false;
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
    && strpos($formConflict, 'Wallbox') !== false;
printf("%s EMS-Konfliktcheck: EMS steuert dieselbe Zielvariable -> Konflikt + Warnung mit Name\n", $ok ? 'PASS' : 'FAIL');
if (!$ok) {
    $failures++;
}

$mNoConflict = makeModuleWithRule(9002);
$actionsNoConflict = json_decode($mNoConflict->GetDataActions(), true);
$ok = ($actionsNoConflict[0]['emsConflict'] ?? true) === false;
printf("%s EMS-Konfliktcheck: EMS steuert andere Variable -> kein Fehlalarm\n", $ok ? 'PASS' : 'FAIL');
if (!$ok) {
    $failures++;
}

exit($failures === 0 ? 0 : 1);
