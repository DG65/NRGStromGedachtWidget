<?php

declare(strict_types=1);

class StromGedachtWidget extends IPSModule
{
    // Instanz-Status
    private const STATUS_NO_ZIP = 104;
    private const STATUS_ZIP_UNKNOWN = 201;
    private const STATUS_API_ERROR = 202;
    private const STATUS_NO_SOURCE = 203;

    private const COLOR_GREY = '#9e9e9e';

    // Muss bei jeder Version mit sichtbaren Neuerungen mitgezogen werden (Formular-Konvention
    // des NRG-Stack: "🆕 Neu in Version"-Panel + Versionsnummer im Doku-Panel)
    private const MODULE_VERSION = '1.7.1';
    private const NEWS_ITEMS = [
        'Die Schaltfläche "Jetzt aktualisieren" zeigt jetzt eine sichtbare Rückmeldung (z. B. "✅ 3 von 3 Quelle(n) aktualisiert"), ohne dass du das Formular neu öffnen musst.',
        '🆕 SGW_GetForecast() liefert jetzt auch Vorschau-Einträge für GrünstromIndex (source: "gsi", stündliches Raster) und Energy-Charts (source: "energycharts", 15-Minuten-Raster, begrenztes Zukunftsfenster) — nicht mehr nur StromGedacht.',
        'Formular macht jetzt deutlich, dass StromGedacht nur Baden-Württemberg + Pilotgebiete abdeckt (GrünstromIndex/Energy-Charts bundesweit) und dass "Wenn Datenpunkt" nur aktivierte Quellen zeigt.',
        'Texte der StromGedacht-Ampel an die offizielle TransnetBW-App/Website angeglichen (Wiedererkennbarkeit für Nutzer, die die App bereits kennen).',
        '🆕 Automationen warnen jetzt, wenn ihre Zielvariable aktuell auch vom EMS gesteuert wird (Zwei-Regler-Kollision) — Hinweis im Formular und ⚠️-Symbol in der Kachel.',
        'Neue Funktionen SGW_GetState()/SGW_GetForecast() für andere Module des NRG-Stack (z. B. EMS) — Grundlage für automatische netzdienliche Steuerung.',
        'Lizenzwechsel: PolyForm Noncommercial 1.0.0 statt MIT — private/nicht-kommerzielle Nutzung bleibt frei, gewerbliche Nutzung ist ab jetzt lizenzpflichtig.',
        'Teil des NRG-Stack (DG65-Modulverbund) — siehe SUITE.md, welche Modulstände zusammenpassen.'
    ];

    // Zustände laut StromGedacht API:
    // -1 = Supergrün, 1 = Grün, 2 = Gelb (veraltet), 3 = Orange, 4 = Rot
    private const SG_COLORS = [
        -1 => '#00bfa5',
        1  => '#00c853',
        2  => '#ffd600',
        3  => '#ff6d00',
        4  => '#d50000'
    ];

    private const SG_LABELS = [
        -1 => 'Supergrün',
        1  => 'Grün',
        2  => 'Gelb',
        3  => 'Orange',
        4  => 'Rot'
    ];

    // Wortwahl an die offizielle StromGedacht-App/Website (TransnetBW) angeglichen
    // (Stand 2026-07-27, www.stromgedacht.de) - Wiedererkennbarkeit für Nutzer, die die
    // App bereits kennen.
    private const SG_TEXTS = [
        -1 => 'Strom bevorzugt jetzt nutzen – besonders viel erneuerbare Energie im Netz',
        1  => 'Strom wie gewohnt nutzen – Normalbetrieb',
        2  => 'Angespannte Netzsituation',
        3  => 'Verbrauch reduzieren – hilft, Kosten und CO₂ zu sparen',
        4  => 'Verbrauch vermeiden – hilft, einen Strommangel zu verhindern'
    ];

    // Signale laut Energy-Charts API:
    // -1 = Rot (Netzengpass), 0 = Rot (wenig EE), 1 = Gelb, 2 = Grün
    private const EC_COLORS = [
        -1 => '#d50000',
        0  => '#d50000',
        1  => '#ffd600',
        2  => '#00c853'
    ];

    private const EC_LABELS = [
        -1 => 'Rot (Netzengpass)',
        0  => 'Rot',
        1  => 'Gelb',
        2  => 'Grün'
    ];

    private const EC_TEXTS = [
        -1 => 'Netzengpass – Verbrauch reduzieren',
        0  => 'Niedriger Anteil erneuerbarer Energien',
        1  => 'Durchschnittlicher Anteil erneuerbarer Energien',
        2  => 'Hoher Anteil erneuerbarer Energien'
    ];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('EnableStromGedacht', true);
        $this->RegisterPropertyBoolean('EnableGSI', true);
        $this->RegisterPropertyBoolean('EnableEnergyCharts', true);
        $this->RegisterPropertyString('ZipCode', '');
        $this->RegisterPropertyInteger('UpdateInterval', 300);
        $this->RegisterPropertyString('DataActions', '[]');

        $this->RegisterAttributeString('RuleState', '{}');
        $this->RegisterAttributeBoolean('ReviewHintDismissed', false);
        $this->RegisterAttributeString('SeenNews', '');

        // Profile bewusst getrennt je Datenquelle
        if (!IPS_VariableProfileExists('SGW.State')) {
            IPS_CreateVariableProfile('SGW.State', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('SGW.State', -1, 'Supergrün', '', 0x00BFA5);
            IPS_SetVariableProfileAssociation('SGW.State', 1, 'Grün', '', 0x00C853);
            IPS_SetVariableProfileAssociation('SGW.State', 2, 'Gelb', '', 0xFFD600);
            IPS_SetVariableProfileAssociation('SGW.State', 3, 'Orange', '', 0xFF6D00);
            IPS_SetVariableProfileAssociation('SGW.State', 4, 'Rot', '', 0xD50000);
        }

        if (!IPS_VariableProfileExists('SGW.ECSignal')) {
            IPS_CreateVariableProfile('SGW.ECSignal', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('SGW.ECSignal', -1, 'Rot (Netzengpass)', '', 0xD50000);
            IPS_SetVariableProfileAssociation('SGW.ECSignal', 0, 'Rot', '', 0xD50000);
            IPS_SetVariableProfileAssociation('SGW.ECSignal', 1, 'Gelb', '', 0xFFD600);
            IPS_SetVariableProfileAssociation('SGW.ECSignal', 2, 'Grün', '', 0x00C853);
        }

        // Gemeinsames NRG-Stack-Profil für physikalische Grundgrößen (kein Eigentümer-Modul,
        // idempotent anlegen) statt eigener SGW.GSI/SGW.Percent-Duplikate — siehe EMS/SUITE.md
        if (!IPS_VariableProfileExists('NRG.Percent')) {
            IPS_CreateVariableProfile('NRG.Percent', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileValues('NRG.Percent', 0, 100, 1);
            IPS_SetVariableProfileDigits('NRG.Percent', 1);
            IPS_SetVariableProfileText('NRG.Percent', '', ' %');
        }

        $this->RegisterTimer('UpdateTimer', 0, 'SGW_Update($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        $sg = $this->ReadPropertyBoolean('EnableStromGedacht');
        $gsi = $this->ReadPropertyBoolean('EnableGSI');
        $ec = $this->ReadPropertyBoolean('EnableEnergyCharts');

        // Variablen je aktivierter Quelle pflegen (nicht benötigte werden entfernt)
        $this->MaintainVariable('State', 'Ampel', VARIABLETYPE_INTEGER, 'SGW.State', 1, $sg);
        $this->MaintainVariable('Text', 'Status Text', VARIABLETYPE_STRING, '', 2, $sg);
        $this->MaintainVariable('GSI', 'GrünstromIndex', VARIABLETYPE_FLOAT, 'NRG.Percent', 3, $gsi);
        $this->MaintainVariable('ECSignal', 'Stromampel', VARIABLETYPE_INTEGER, 'SGW.ECSignal', 4, $ec);
        $this->MaintainVariable('ECShare', 'EE-Anteil', VARIABLETYPE_FLOAT, 'NRG.Percent', 5, $ec);
        $this->MaintainVariable('Updated', 'Aktualisiert', VARIABLETYPE_INTEGER, '~UnixTimestamp', 6, true);
        $this->MaintainVariable('Widget', 'Anzeige', VARIABLETYPE_STRING, '~HTMLBox', 7, true);

        // Baseline ohne Auslösen: verhindert Fehlauslösung einer Regel direkt nach
        // dem Übernehmen, falls ihre Bedingung zufällig schon erfüllt ist
        try {
            $this->evaluateDataActions(false);
        } catch (Throwable $e) {
            // ignorieren
        }

        if (!$sg && !$gsi && !$ec) {
            $this->SetStatus(self::STATUS_NO_SOURCE);
            $this->SetTimerInterval('UpdateTimer', 0);
            return;
        }

        if (($sg || $gsi) && trim($this->ReadPropertyString('ZipCode')) === '') {
            $this->SetStatus(self::STATUS_NO_ZIP);
            $this->SetTimerInterval('UpdateTimer', 0);
            return;
        }

        $this->SetStatus(IS_ACTIVE);
        $this->SetTimerInterval('UpdateTimer', $this->ReadPropertyInteger('UpdateInterval') * 1000);
        $this->Update();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        if (!is_array($form)) {
            $form = ['elements' => [], 'actions' => [], 'status' => []];
        }

        $sourceOptions = $this->getAutomationSourceOptions();

        // Datenpunkt-Optionen der Automationsliste befüllen (liegt in einem ExpansionPanel),
        // Versionsnummer ins Doku-Panel patchen (Formular-Konvention des NRG-Stack)
        $patch = function (array &$elements) use (&$patch, $sourceOptions) {
            foreach ($elements as &$element) {
                if (!is_array($element)) {
                    continue;
                }
                if (($element['name'] ?? '') === 'DataActions' && isset($element['columns']) && is_array($element['columns'])) {
                    foreach ($element['columns'] as &$col) {
                        if (($col['name'] ?? '') === 'Source') {
                            $col['edit']['options'] = $sourceOptions;
                        }
                    }
                    unset($col);
                }
                if (($element['name'] ?? '') === 'DocPanel') {
                    $element['caption'] = '📖 Dokumentation & Hilfe (v' . self::MODULE_VERSION . ')';
                }
                if (isset($element['items']) && is_array($element['items'])) {
                    $patch($element['items']);
                }
            }
            unset($element);
        };
        $patch($form['elements']);

        // Zwei-Regler-Warnhinweis: eine unserer Regel-Zielvariablen wird aktuell auch vom EMS
        // gesteuert ("Ein Regler pro Stellgröße"-Verbundregel). Nur Information, keine
        // Blockade - der Nutzer entscheidet selbst, ob die Regel noch sinnvoll ist.
        $emsControlled = $this->getEmsControlledVariableIds();
        $conflictNames = [];
        $rules = json_decode((string) $this->ReadPropertyString('DataActions'), true);
        if (is_array($rules)) {
            foreach ($rules as $rule) {
                if (is_array($rule) && $this->ruleHasEmsConflict($rule, $emsControlled)) {
                    $tVid = (int) ($rule['Target'] ?? 0);
                    $conflictNames[$tVid] = ($tVid > 0 && IPS_VariableExists($tVid)) ? IPS_GetName($tVid) : ('#' . $tVid);
                }
            }
        }
        if (count($conflictNames) > 0) {
            $warningInserted = false;
            $insertWarning = function (array &$elements) use (&$insertWarning, &$warningInserted, $conflictNames) {
                foreach ($elements as &$element) {
                    if ($warningInserted || !is_array($element) || !isset($element['items']) || !is_array($element['items'])) {
                        continue;
                    }
                    foreach ($element['items'] as $idx => $child) {
                        if (is_array($child) && ($child['name'] ?? '') === 'DataActions') {
                            array_splice($element['items'], $idx + 1, 0, [[
                                'type'    => 'Label',
                                'caption' => '⚠️ Wird auch vom EMS gesteuert (Zwei-Regler-Kollision möglich): ' . implode(', ', $conflictNames)
                            ]]);
                            $warningInserted = true;
                            break;
                        }
                    }
                    if (!$warningInserted) {
                        $insertWarning($element['items']);
                    }
                }
                unset($element);
            };
            $insertWarning($form['elements']);
        }

        // Einmaliger Feedback-Hinweis: erscheint, bis er per Button ausgeblendet wird
        if (!$this->ReadAttributeBoolean('ReviewHintDismissed')) {
            $form['elements'][] = [
                'type'  => 'RowLayout',
                'name'  => 'ReviewHint',
                'items' => [
                    [
                        'type'    => 'Label',
                        'caption' => '⭐ Gefällt dir dieses Modul? Über eine Bewertung im Module Store oder eine Rückmeldung in der Symcon-Community freue ich mich!'
                    ],
                    [
                        'type'    => 'Label',
                        'link'    => true,
                        'caption' => 'https://community.symcon.de/t/modul-strom-gedacht-ampel-widget/143960'
                    ],
                    [
                        'type'    => 'Button',
                        'caption' => 'Nicht mehr anzeigen',
                        'onClick' => 'SGW_DismissReviewHint($id);'
                    ]
                ]
            ];
        }

        // "🆕 Neu in Version X.Y"-Panel ganz oben: erscheint bis zur Bestätigung, danach je
        // Version erneut (Attribut speichert die zuletzt bestätigte Version)
        $banner = $this->newsBanner();
        if ($banner !== null) {
            array_unshift($form['elements'], $banner);
        }

        return json_encode($form);
    }

    /** "🆕 Neu in Version"-Panel: null, wenn der Nutzer diese Version schon bestätigt hat. */
    private function newsBanner(): ?array
    {
        if ($this->ReadAttributeString('SeenNews') === self::MODULE_VERSION) {
            return null;
        }
        $items = [['type' => 'Label', 'caption' => '🆕 Neu in diesem Modul — bitte kurz ansehen und ggf. die Einstellungen prüfen:']];
        foreach (self::NEWS_ITEMS as $line) {
            $items[] = ['type' => 'Label', 'caption' => '• ' . $line];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'SGW_AckNews($id);'];
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => '🆕 Neu in Version ' . self::MODULE_VERSION, 'expanded' => true, 'items' => $items];
    }

    public function AckNews(): void
    {
        $this->WriteAttributeString('SeenNews', self::MODULE_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    public function DismissReviewHint(): void
    {
        $this->WriteAttributeBoolean('ReviewHintDismissed', true);
        $this->UpdateFormField('ReviewHint', 'visible', false);
    }

    /**
     * Ruft alle aktivierten Quellen ab. Gibt einen kurzen Ergebnistext zurück
     * (Verbund-Konvention "Sichtbare Rückmeldung bei jeder Aktion", SUITE.md) -
     * der Formular-Button nutzt das per "echo SGW_Update($id);".
     */
    public function Update(): string
    {
        $zip = trim($this->ReadPropertyString('ZipCode'));

        $columns = [];
        $ok = 0;
        $zipUnknown = 0;
        $failed = 0;

        if ($this->ReadPropertyBoolean('EnableStromGedacht')) {
            $columns[] = $this->FetchStromGedacht($zip, $ok, $zipUnknown, $failed);
        }
        if ($this->ReadPropertyBoolean('EnableGSI')) {
            $columns[] = $this->FetchGSI($zip, $ok, $zipUnknown, $failed);
        }
        if ($this->ReadPropertyBoolean('EnableEnergyCharts')) {
            $columns[] = $this->FetchEnergyCharts($ok, $failed);
        }

        $now = date('H:i:s');

        if (count($columns) === 0) {
            return 'ℹ️ Keine Datenquelle aktiviert (' . $now . ' Uhr)';
        }

        if ($ok > 0) {
            // Mindestens eine Quelle liefert: Instanz bleibt aktiv,
            // ausgefallene Quellen sind im Widget als "Keine Daten" sichtbar
            $this->SetStatus(IS_ACTIVE);
            $this->SetValue('Updated', time());
            $result = '✅ ' . $ok . ' von ' . count($columns) . ' Quelle(n) aktualisiert (' . $now . ' Uhr)';
        } elseif ($failed === 0 && $zipUnknown > 0) {
            $this->SetStatus(self::STATUS_ZIP_UNKNOWN);
            $result = '⚠️ Für diese Postleitzahl liegen keine Daten vor (' . $now . ' Uhr)';
        } else {
            $this->SetStatus(self::STATUS_API_ERROR);
            $result = '❌ Keine aktivierte Quelle erreichbar (' . $now . ' Uhr)';
        }

        $this->RenderWidget($columns);

        try {
            $this->evaluateDataActions();
        } catch (Throwable $e) {
            $this->SendDebug('Automation', $e->getMessage(), 0);
        }

        return $result;
    }

    // ---------------------------------------------------------------------
    // NRG-Stack-Vertrag (contractVersion 1.0): stabile Getter für andere
    // Module des Verbunds (z. B. EMS). StromGedacht ist eine Empfehlung,
    // keine Pflicht - anders als z. B. EMS_GetSpecialEvents (erzwungene
    // externe Eingriffe).
    // ---------------------------------------------------------------------

    /**
     * Aktueller Zustand aller aktivierten Quellen. Felder sind null, wenn die
     * jeweilige Quelle in dieser Instanz nicht aktiviert ist.
     */
    public function GetState(): array
    {
        $stateRaw = $this->ReadPropertyBoolean('EnableStromGedacht') ? $this->GetOwnValue('State') : null;
        $state = $stateRaw !== null ? (int) $stateRaw : null;
        $gsiRaw = $this->ReadPropertyBoolean('EnableGSI') ? $this->GetOwnValue('GSI') : null;
        $ecSignalRaw = $this->ReadPropertyBoolean('EnableEnergyCharts') ? $this->GetOwnValue('ECSignal') : null;
        $ecShareRaw = $this->ReadPropertyBoolean('EnableEnergyCharts') ? $this->GetOwnValue('ECShare') : null;
        $updatedRaw = $this->GetOwnValue('Updated');

        return [
            'contractVersion' => '1.0',
            'state'    => $state,
            'label'    => $state !== null ? (self::SG_LABELS[$state] ?? 'Unbekannt') : 'StromGedacht deaktiviert',
            'gsi'      => $gsiRaw !== null ? (float) $gsiRaw : null,
            'ecSignal' => $ecSignalRaw !== null ? (int) $ecSignalRaw : null,
            'ecShare'  => $ecShareRaw !== null ? (float) $ecShareRaw : null,
            'updated'  => $updatedRaw !== null ? (int) $updatedRaw : 0
        ];
    }

    /**
     * Vorschau im Zeitraum [$Von, $Bis] (Unix-Timestamps). Aktuell nur die
     * StromGedacht-Netzampel implementiert (per Verbund-Vorgabe die einzige
     * planungsrelevante Quelle) - 'gsi'/'energycharts' liefern noch keine
     * Einträge. API-Horizont: maximal 48 Stunden ab jetzt; die Zeiträume
     * sind unregelmäßige Zustands-Segmente, kein festes Raster.
     */
    /**
     * Vorschau im Zeitraum [$Von, $Bis] über alle aktivierten Quellen (source:
     * 'stromgedacht'/'gsi'/'energycharts'). Achtung: Horizont und Zeitraster
     * unterscheiden sich je Quelle (API-Eigenheit, nicht angleichbar ohne
     * Rohdaten zu verfälschen) - siehe die einzelnen fetch*Forecast()-Methoden.
     */
    public function GetForecast(int $Von, int $Bis): array
    {
        if ($Bis <= $Von) {
            return [];
        }
        $entries = [];
        if ($this->ReadPropertyBoolean('EnableStromGedacht')) {
            $entries = array_merge($entries, $this->fetchStromGedachtForecast($Von, $Bis));
        }
        if ($this->ReadPropertyBoolean('EnableGSI')) {
            $entries = array_merge($entries, $this->fetchGsiForecast($Von, $Bis));
        }
        if ($this->ReadPropertyBoolean('EnableEnergyCharts')) {
            $entries = array_merge($entries, $this->fetchEnergyChartsForecast($Von, $Bis));
        }
        return $entries;
    }

    /** Horizont: API-Maximum 48h ab jetzt. Unregelmäßige Zustands-Segmente, kein festes Raster. */
    private function fetchStromGedachtForecast(int $Von, int $Bis): array
    {
        $zip = trim($this->ReadPropertyString('ZipCode'));
        if ($zip === '') {
            return [];
        }

        $hoursInFuture = (int) ceil(max(1, $Bis - time()) / 3600);
        $hoursInFuture = max(1, min(48, $hoursInFuture));

        [$code, $body] = $this->HttpGet('https://api.stromgedacht.de/v1/statesRelative?zip=' . urlencode($zip) . '&hoursInFuture=' . $hoursInFuture);
        $data = $this->DecodeJson($code, $body);
        if ($data === null || !isset($data['states']) || !is_array($data['states'])) {
            $this->SendDebug('GetForecast', 'StromGedacht: API nicht erreichbar oder ungültige Antwort', 0);
            return [];
        }

        $entries = [];
        foreach ($data['states'] as $period) {
            if (!is_array($period) || !isset($period['from'], $period['to'], $period['state'])) {
                continue;
            }
            $from = strtotime((string) $period['from']);
            $to = strtotime((string) $period['to']);
            if ($from === false || $to === false) {
                continue;
            }
            $from = max($from, $Von);
            $to = min($to, $Bis);
            if ($to <= $from) {
                continue;
            }
            $entries[] = [
                'contractVersion' => '1.0',
                'source' => 'stromgedacht',
                'from'   => $from,
                'to'     => $to,
                'value'  => (float) $period['state']
            ];
        }

        return $entries;
    }

    /**
     * Horizont: mehrere Tage (API-abhängig, kein fester Wert). Raster: STÜNDLICH
     * (Corrently liefert echte 1h-Zeitfenster, kein 15-Minuten-Raster - wird hier
     * nicht künstlich verfeinert, um keine falsche Genauigkeit vorzutäuschen).
     */
    private function fetchGsiForecast(int $Von, int $Bis): array
    {
        $zip = trim($this->ReadPropertyString('ZipCode'));
        if ($zip === '') {
            return [];
        }

        [$code, $body] = $this->HttpGet('https://api.corrently.io/v2.0/gsi/prediction?zip=' . urlencode($zip));
        $data = $this->DecodeJson($code, $body);
        if ($data === null || !isset($data['forecast']) || !is_array($data['forecast'])) {
            $this->SendDebug('GetForecast', 'GSI: API nicht erreichbar oder ungültige Antwort', 0);
            return [];
        }

        $entries = [];
        foreach ($data['forecast'] as $period) {
            if (!is_array($period) || !isset($period['timeframe']['start'], $period['timeframe']['end'], $period['gsi'])) {
                continue;
            }
            $from = (int) ((int) $period['timeframe']['start'] / 1000);
            $to = (int) ((int) $period['timeframe']['end'] / 1000);
            $from = max($from, $Von);
            $to = min($to, $Bis);
            if ($to <= $from) {
                continue;
            }
            $entries[] = [
                'contractVersion' => '1.0',
                'source' => 'gsi',
                'from'   => $from,
                'to'     => $to,
                'value'  => (float) $period['gsi']
            ];
        }

        return $entries;
    }

    /**
     * Horizont: fest ans "Jetzt" gekoppeltes API-Fenster, in der Praxis nur wenige
     * Stunden in die Zukunft (kein garantierter 24h-Horizont). Raster: echte
     * 15-Minuten-Schritte. Wert = ecSignal (-1/0/1/2); der zugehörige EE-Anteil
     * (ecShare) ist in diesem Vertrag NICHT enthalten (passt nicht ins bestehende
     * source-Schema stromgedacht/gsi/energycharts - bei Bedarf Rückmeldung an uns).
     */
    private function fetchEnergyChartsForecast(int $Von, int $Bis): array
    {
        [$code, $body] = $this->HttpGet('https://api.energy-charts.info/signal?country=de');
        $data = $this->DecodeJson($code, $body);
        if ($data === null || !isset($data['unix_seconds'], $data['signal']) || !is_array($data['unix_seconds']) || !is_array($data['signal'])) {
            $this->SendDebug('GetForecast', 'Energy-Charts: API nicht erreichbar oder ungültige Antwort', 0);
            return [];
        }

        $times = $data['unix_seconds'];
        $signals = $data['signal'];
        $count = count($times);
        $entries = [];
        for ($i = 0; $i < $count; $i++) {
            if (!isset($signals[$i])) {
                continue;
            }
            $from = (int) $times[$i];
            $to = ($i + 1 < $count) ? (int) $times[$i + 1] : $from + 900;
            $from = max($from, $Von);
            $to = min($to, $Bis);
            if ($to <= $from) {
                continue;
            }
            $entries[] = [
                'contractVersion' => '1.0',
                'source' => 'energycharts',
                'from'   => $from,
                'to'     => $to,
                'value'  => (float) $signals[$i]
            ];
        }

        return $entries;
    }

    /** Liest eine eigene Instanz-Variable per Ident, oder null wenn sie nicht existiert. */
    private function GetOwnValue(string $ident)
    {
        $vid = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        return ($vid !== false && $vid > 0) ? GetValue($vid) : null;
    }

    private function FetchStromGedacht(string $zip, int &$ok, int &$zipUnknown, int &$failed): array
    {
        $column = ['title' => 'StromGedacht', 'color' => self::COLOR_GREY, 'label' => 'Keine Daten', 'text' => ''];

        if ($zip === '') {
            $column['text'] = 'Postleitzahl fehlt';
            $zipUnknown++;
            return $column;
        }

        [$code, $body] = $this->HttpGet('https://api.stromgedacht.de/v1/now?zip=' . urlencode($zip));

        // Die API antwortet mit 400 + Hinweistext, wenn sie die PLZ nicht
        // kennt bzw. keine Daten für das PLZ-Gebiet vorliegen
        if ($code === 400 && stripos((string) $body, 'no data') !== false) {
            $message = 'Für die Postleitzahl ' . $zip . ' liegen keine Daten vor';
            $this->SendDebug('StromGedacht', $message, 0);
            $this->SetValue('Text', $message);
            $column['text'] = $message;
            $zipUnknown++;
            return $column;
        }

        $data = $this->DecodeJson($code, $body);
        if ($data === null || !isset($data['state'])) {
            $this->SendDebug('StromGedacht', 'API nicht erreichbar oder ungültige Antwort', 0);
            $column['text'] = 'API nicht erreichbar';
            $failed++;
            return $column;
        }

        $state = (int) $data['state'];
        $text = self::SG_TEXTS[$state] ?? 'Unbekannter Zustand (' . $state . ')';

        $this->SetValue('State', $state);
        $this->SetValue('Text', $text);

        $ok++;

        return [
            'title' => 'StromGedacht',
            'color' => self::SG_COLORS[$state] ?? self::COLOR_GREY,
            'label' => self::SG_LABELS[$state] ?? 'Unbekannt',
            'text'  => $text
        ];
    }

    private function FetchGSI(string $zip, int &$ok, int &$zipUnknown, int &$failed): array
    {
        $column = ['title' => 'GrünstromIndex', 'color' => self::COLOR_GREY, 'label' => 'Keine Daten', 'text' => ''];

        if ($zip === '') {
            $column['text'] = 'Postleitzahl fehlt';
            $zipUnknown++;
            return $column;
        }

        [$code, $body] = $this->HttpGet('https://api.corrently.io/v2.0/gsi/prediction?zip=' . urlencode($zip));

        $data = $this->DecodeJson($code, $body);
        if ($data === null) {
            $this->SendDebug('GrünstromIndex', 'API nicht erreichbar oder ungültige Antwort', 0);
            $column['text'] = 'API nicht erreichbar';
            $failed++;
            return $column;
        }

        // Bei unbekannter PLZ liefert die API ein leeres forecast-Array
        $forecast = $data['forecast'] ?? [];
        if (count($forecast) === 0) {
            $message = 'Für die Postleitzahl ' . $zip . ' liegen keine Daten vor';
            $this->SendDebug('GrünstromIndex', $message, 0);
            $column['text'] = $message;
            $zipUnknown++;
            return $column;
        }

        // Eintrag suchen, dessen Zeitfenster jetzt abdeckt (sonst den ersten)
        $now = time();
        $current = $forecast[0];
        foreach ($forecast as $entry) {
            $start = (int) ($entry['timeframe']['start'] ?? 0) / 1000;
            $end = (int) ($entry['timeframe']['end'] ?? 0) / 1000;
            if ($start <= $now && $now < $end) {
                $current = $entry;
                break;
            }
        }

        $gsiValue = (float) ($current['gsi'] ?? 0);

        if ($gsiValue >= 66) {
            $color = '#00c853';
            $text = 'Hoher Grünstrom-Anteil in der Region';
        } elseif ($gsiValue >= 33) {
            $color = '#ffd600';
            $text = 'Durchschnittlicher Grünstrom-Anteil in der Region';
        } else {
            $color = '#d50000';
            $text = 'Niedriger Grünstrom-Anteil in der Region';
        }

        $this->SetValue('GSI', $gsiValue);

        $ok++;

        return [
            'title' => 'GrünstromIndex',
            'color' => $color,
            'label' => number_format($gsiValue, 0) . ' %',
            'text'  => $text
        ];
    }

    private function FetchEnergyCharts(int &$ok, int &$failed): array
    {
        $column = ['title' => 'Energy-Charts', 'color' => self::COLOR_GREY, 'label' => 'Keine Daten', 'text' => ''];

        [$code, $body] = $this->HttpGet('https://api.energy-charts.info/signal?country=de');

        $data = $this->DecodeJson($code, $body);
        if ($data === null || !isset($data['unix_seconds'], $data['signal'])) {
            $this->SendDebug('Energy-Charts', 'API nicht erreichbar oder ungültige Antwort', 0);
            $column['text'] = 'API nicht erreichbar';
            $failed++;
            return $column;
        }

        // Letzten 15-Minuten-Slot suchen, der bereits begonnen hat
        $now = time();
        $index = 0;
        foreach ($data['unix_seconds'] as $i => $ts) {
            if ($ts > $now) {
                break;
            }
            $index = $i;
        }

        $signal = (int) ($data['signal'][$index] ?? 0);
        $share = (float) ($data['share'][$index] ?? 0);

        $this->SetValue('ECSignal', $signal);
        $this->SetValue('ECShare', $share);

        $ok++;

        return [
            'title' => 'Energy-Charts',
            'color' => self::EC_COLORS[$signal] ?? self::COLOR_GREY,
            'label' => self::EC_LABELS[$signal] ?? 'Unbekannt',
            'text'  => (self::EC_TEXTS[$signal] ?? 'Unbekanntes Signal') . ' (EE-Anteil: ' . number_format($share, 1, ',', '.') . ' %)'
        ];
    }

    // Liefert [HTTP-Code, Body]; Code 0 = Verbindungsfehler
    private function HttpGet(string $url): array
    {
        [$code, $body] = $this->TryHttpGet($url, false);

        // PHP-Streams haben keinen IPv6→IPv4-Fallback; bei Verbindungsfehler
        // (z. B. nicht erreichbarer IPv6-Endpunkt) erzwungen über IPv4 wiederholen.
        // Ein leerer Body trotz Statuscode bedeutet eine vorzeitig beendete
        // Verbindung und wird ebenfalls als Fehler behandelt
        if ($body === null || trim($body) === '') {
            $this->SendDebug('API', 'Verbindungsfehler, wiederhole über IPv4: ' . $url, 0);
            [$code, $body] = $this->TryHttpGet($url, true);
            if ($body !== null && trim($body) === '') {
                $body = null;
            }
        }

        $this->SendDebug('API', 'HTTP ' . $code . ' ' . $url, 0);
        if ($body !== null) {
            $this->SendDebug('API-Antwort', $body, 0);
        }

        return [$code, $body];
    }

    private function TryHttpGet(string $url, bool $forceIPv4): array
    {
        $options = [
            'http' => [
                'method'        => 'GET',
                'header'        => "User-Agent: Symcon-StromGedacht\r\nAccept: application/json\r\n",
                'timeout'       => 10,
                'ignore_errors' => true
            ]
        ];

        if ($forceIPv4) {
            $options['socket'] = ['bindto' => '0:0'];
        }

        $body = @file_get_contents($url, false, stream_context_create($options));

        $code = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match)) {
                $code = (int) $match[1];
            }
        }

        return [$code, $body === false ? null : $body];
    }

    private function DecodeJson(int $code, ?string $body): ?array
    {
        if ($code !== 200 || $body === null) {
            return null;
        }

        $data = json_decode($body, true);

        return is_array($data) ? $data : null;
    }

    private function RenderWidget(array $columns)
    {
        $html = '<div style="font-family:Arial; padding:16px; text-align:center;">';
        $html .= '<div style="display:flex; justify-content:center; gap:28px; flex-wrap:wrap;">';

        foreach ($columns as $column) {
            $html .= '
            <div style="min-width:150px; max-width:200px;">
                <div style="font-size:12px; color:gray; margin-bottom:10px;">
                    ' . htmlspecialchars($column['title']) . '
                </div>
                <div style="
                    width:60px;
                    height:60px;
                    border-radius:50%;
                    margin:0 auto 12px auto;
                    background:' . $column['color'] . ';
                    box-shadow:0 0 16px ' . $column['color'] . ';
                "></div>
                <div style="font-size:18px; font-weight:bold; color:' . $column['color'] . '; margin-bottom:8px;">
                    ' . htmlspecialchars($column['label']) . '
                </div>
                <div style="font-size:12px;">
                    ' . htmlspecialchars($column['text']) . '
                </div>
            </div>';
        }

        $html .= '</div>';
        $html .= '<div style="font-size:11px; color:gray; margin-top:14px;">' . date('d.m.Y H:i:s') . '</div>';
        $html .= '</div>';

        $this->SetValue('Widget', $html);
    }

    // ---------------------------------------------------------------------
    // Automationen (Wenn -> Dann): generische Regeln über die Ampel-/Signal-Werte
    // dieser Instanz, Ziel ist eine beliebige schaltbare Variable im System.
    // ---------------------------------------------------------------------

    /** Datenpunkte, die als Wenn-Bedingung wählbar sind (nur aktivierte Quellen). */
    private function getAutomationSourceOptions(): array
    {
        $options = [];
        if ($this->ReadPropertyBoolean('EnableStromGedacht')) {
            $options[] = ['caption' => 'StromGedacht-Ampel', 'value' => 'State'];
        }
        if ($this->ReadPropertyBoolean('EnableGSI')) {
            $options[] = ['caption' => 'GrünstromIndex', 'value' => 'GSI'];
        }
        if ($this->ReadPropertyBoolean('EnableEnergyCharts')) {
            $options[] = ['caption' => 'Energy-Charts-Signal', 'value' => 'ECSignal'];
            $options[] = ['caption' => 'Energy-Charts EE-Anteil', 'value' => 'ECShare'];
        }
        return $options;
    }

    /** Setzt Zielvariable per Aktion (wenn vorhanden) oder direkt per SetValue. */
    private function applyActionToVariable(int $vid, string $action, string $rawValue, string $context): bool
    {
        if ($vid <= 0 || !IPS_VariableExists($vid)) {
            $this->SendDebug('Aktion', sprintf('%s: Zielvariable #%d existiert nicht', $context, $vid), 0);
            return false;
        }

        $var = IPS_GetVariable($vid);
        switch ($action) {
            case 'off':    $value = false; break;
            case 'toggle': $value = !(bool) GetValue($vid); break;
            case 'value':  $value = $this->castToVariableType($rawValue, (int) $var['VariableType']); break;
            case 'on':
            default:       $value = true; break;
        }
        // Bool-Aktionen auf Nicht-Bool-Variablen sinnvoll abbilden (0/1)
        if (is_bool($value) && (int) $var['VariableType'] !== VARIABLETYPE_BOOLEAN) {
            $value = $this->castToVariableType($value ? '1' : '0', (int) $var['VariableType']);
        }

        $hasAction = ((int) $var['VariableAction'] > 0 || (int) $var['VariableCustomAction'] > 0);
        $ok = $hasAction ? @RequestAction($vid, $value) : @SetValue($vid, $value);

        $this->SendDebug('Aktion', sprintf(
            '%s -> %s #%d = %s (%s)',
            $context, $hasAction ? 'RequestAction' : 'SetValue',
            $vid, json_encode($value), ($ok === false) ? 'FEHLER' : 'ok'
        ), 0);
        if ($ok === false) {
            $this->LogMessage(sprintf('Aktion "%s" auf Variable #%d fehlgeschlagen', $context, $vid), KL_WARNING);
        }
        return $ok !== false;
    }

    /** Wandelt den Regel-Wert (Text) in den Typ der Zielvariable um. */
    private function castToVariableType(string $raw, int $type)
    {
        $raw = trim($raw);
        switch ($type) {
            case VARIABLETYPE_BOOLEAN:
                return in_array(strtolower($raw), ['1', 'true', 'ein', 'on', 'ja', 'an'], true);
            case VARIABLETYPE_INTEGER:
                return (int) $raw;
            case VARIABLETYPE_FLOAT:
                return (float) str_replace(',', '.', $raw);
            default:
                return $raw;
        }
    }

    /**
     * Liest die Bedingungsliste einer Regel, egal ob im neuen Mehrfach-Format
     * ({Conditions:[{Source,Op,Compare},...]}) oder im alten flachen Format
     * (Source/Op/Compare direkt in der Regel) gespeichert. Alle Bedingungen
     * werden mit UND verknüpft ausgewertet.
     */
    private function normalizeRuleConditions(array $rule): array
    {
        if (isset($rule['Conditions']) && is_array($rule['Conditions'])) {
            $out = [];
            foreach ($rule['Conditions'] as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $src = (string) ($c['Source'] ?? '');
                if ($src === '') {
                    continue;
                }
                $out[] = ['Source' => $src, 'Op' => (string) ($c['Op'] ?? 'true'), 'Compare' => (string) ($c['Compare'] ?? '')];
            }
            return $out;
        }
        $src = (string) ($rule['Source'] ?? '');
        if ($src === '') {
            return [];
        }
        return [['Source' => $src, 'Op' => (string) ($rule['Op'] ?? 'true'), 'Compare' => (string) ($rule['Compare'] ?? '')]];
    }

    /**
     * Wertet alle Wenn->Dann-Regeln aus. Flankengesteuert: eine Regel feuert nur,
     * wenn ihre Bedingung von unerfüllt auf erfüllt wechselt (bzw. bei 'change',
     * wenn sich der Wert ändert) - nicht bei jeder Datenmeldung erneut.
     * $fire=false aktualisiert nur den Zustand ohne auszulösen (Baseline nach
     * Übernehmen, verhindert Fehlauslösungen durch alte Flanken).
     */
    private function evaluateDataActions(bool $fire = true): void
    {
        $rules = json_decode((string) $this->ReadPropertyString('DataActions'), true);
        if (!is_array($rules)) {
            $rules = [];
        }
        $state = json_decode($this->ReadAttributeString('RuleState'), true);
        if (!is_array($state)) {
            $state = [];
        }
        $stateChanged = false;

        foreach ($rules as $i => $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $key = (string) $i;

            $conditions = $this->normalizeRuleConditions($rule);
            if (count($conditions) === 0) {
                continue;
            }

            $prevState = $state[$key] ?? null;
            if (is_array($prevState) && !array_key_exists('overall', $prevState)) {
                $prevState = null;
            }
            $prevVals = is_array($prevState['vals'] ?? null) ? $prevState['vals'] : [];

            $newVals = [];
            $allSatisfied = true;
            $hasMomentary = false;
            $sourcesValid = true;

            foreach ($conditions as $ci => $cond) {
                $srcIdent = $cond['Source'];
                $op = $cond['Op'];

                $vid = @IPS_GetObjectIDByIdent($srcIdent, $this->InstanceID);
                if ($vid <= 0) {
                    $sourcesValid = false;
                    break;
                }
                $cur = GetValue($vid);
                $serial = json_encode($cur);
                $newVals[$ci] = $serial;

                if ($op === 'change') {
                    $hasMomentary = true;
                    $changed = array_key_exists($ci, $prevVals) && ($prevVals[$ci] !== $serial);
                    if (!$changed) {
                        $allSatisfied = false;
                    }
                } elseif (!$this->evalRuleCondition($cur, $op, $cond['Compare'])) {
                    $allSatisfied = false;
                }
            }

            if (!$sourcesValid) {
                continue; // eine der Quellen existiert (aktuell) nicht
            }

            if ($hasMomentary) {
                $fireNow = $allSatisfied;
            } else {
                $prevOverall = (bool) ($prevState['overall'] ?? false);
                $fireNow = ($prevState !== null) && !$prevOverall && $allSatisfied;
            }

            $newState = ['overall' => $allSatisfied, 'vals' => $newVals];
            if ($prevState === null || ($prevState['overall'] ?? null) !== $allSatisfied || ($prevState['vals'] ?? null) !== $newVals) {
                $state[$key] = $newState;
                $stateChanged = true;
            }

            if ($fire && $fireNow && (bool) ($rule['Active'] ?? true)) {
                $this->applyActionToVariable(
                    (int) ($rule['Target'] ?? 0),
                    (string) ($rule['Action'] ?? 'on'),
                    (string) ($rule['Value'] ?? ''),
                    'Automation ' . $this->describeDataAction($rule)
                );
            }
        }

        foreach (array_keys($state) as $k) {
            if (!isset($rules[(int) $k])) {
                unset($state[$k]);
                $stateChanged = true;
            }
        }
        if ($stateChanged) {
            $this->WriteAttributeString('RuleState', json_encode($state));
        }
    }

    /** Prüft, ob der aktuelle Wert die Bedingung erfüllt. */
    private function evalRuleCondition($cur, string $op, string $cmp): bool
    {
        switch ($op) {
            case 'true':   return (bool) $cur === true;
            case 'false':  return (bool) $cur === false;
            case 'change': return false; // Sonderfall, wird über den Wertvergleich behandelt
        }

        $cmp = trim($cmp);
        if (is_bool($cur)) {
            $cur = $cur ? 1 : 0;
        }
        $numeric = is_numeric($cur) && is_numeric(str_replace(',', '.', $cmp));
        if ($numeric) {
            $a = (float) $cur;
            $b = (float) str_replace(',', '.', $cmp);
        } else {
            $a = (string) $cur;
            $b = $cmp;
        }

        switch ($op) {
            case 'eq': return $numeric ? (abs($a - $b) < 1e-9) : (strcasecmp($a, $b) === 0);
            case 'ne': return $numeric ? (abs($a - $b) >= 1e-9) : (strcasecmp($a, $b) !== 0);
            case 'gt': return $numeric && $a > $b;
            case 'ge': return $numeric && $a >= $b;
            case 'lt': return $numeric && $a < $b;
            case 'le': return $numeric && $a <= $b;
        }
        return false;
    }

    /** Menschenlesbare Beschreibung einer einzelnen Bedingung, z. B. „StromGedacht-Ampel = 4". */
    private function describeCondition(array $cond): string
    {
        $opText = [
            'true' => 'wird EIN', 'false' => 'wird AUS', 'change' => 'ändert sich',
            'eq' => '=', 'ne' => '≠', 'gt' => '>', 'ge' => '≥', 'lt' => '<', 'le' => '≤'
        ];

        $srcIdent = (string) ($cond['Source'] ?? '');
        $srcName = $srcIdent;
        foreach ($this->getAutomationSourceOptions() as $o) {
            if ($o['value'] === $srcIdent) {
                $srcName = $o['caption'];
                break;
            }
        }

        $op = (string) ($cond['Op'] ?? 'true');
        $condText = $opText[$op] ?? $op;
        if (!in_array($op, ['true', 'false', 'change'], true)) {
            $condText .= ' ' . (string) ($cond['Compare'] ?? '');
        }

        return $srcName . ' ' . $condText;
    }

    /** Menschenlesbare Beschreibung einer Regel (alle Bedingungen mit UND), z. B. für Kachel und Debug. */
    private function describeDataAction(array $rule): string
    {
        $conditions = $this->normalizeRuleConditions($rule);
        $parts = array_map([$this, 'describeCondition'], $conditions);
        $condText = (count($parts) > 0) ? implode(' UND ', $parts) : '?';

        $tVid = (int) ($rule['Target'] ?? 0);
        $tName = ($tVid > 0 && IPS_VariableExists($tVid)) ? IPS_GetName($tVid) : ('#' . $tVid);
        switch ((string) ($rule['Action'] ?? 'on')) {
            case 'off':    $do = $tName . ' ausschalten'; break;
            case 'toggle': $do = $tName . ' umschalten'; break;
            case 'value':  $do = $tName . ' = ' . (string) ($rule['Value'] ?? ''); break;
            default:       $do = $tName . ' einschalten'; break;
        }

        return sprintf('Wenn %s → %s', $condText, $do);
    }

    /**
     * Variablen-IDs, die aktuell aktiv vom EMS gesteuert werden (Zwei-Regler-Kollisionscheck,
     * Verbund-Vorschlag 27.07.2026). Eigenständigkeitsregel: nur über function_exists() rufen,
     * niemals blind - ein Aufruf einer undefinierten Funktion ist in PHP ein Fatal Error und
     * ein vorangestelltes @ unterdrückt das NICHT. Ohne EMS im System: leeres Ergebnis, keine
     * Warnung - reine Zusatzinformation, keine Abhängigkeit.
     */
    private function getEmsControlledVariableIds(): array
    {
        if (!function_exists('EMS_GetControlledVariables')) {
            return [];
        }
        try {
            $list = EMS_GetControlledVariables();
        } catch (Throwable $e) {
            $this->SendDebug('EMS-Abgleich', $e->getMessage(), 0);
            return [];
        }
        $ids = [];
        foreach ((is_array($list) ? $list : []) as $entry) {
            if (is_array($entry) && isset($entry['variableID'])) {
                $ids[(int) $entry['variableID']] = true;
            }
        }
        return $ids;
    }

    /** Prüft, ob die Zielvariable einer Regel aktuell auch vom EMS gesteuert wird. */
    private function ruleHasEmsConflict(array $rule, array $emsControlled): bool
    {
        $tVid = (int) ($rule['Target'] ?? 0);
        return $tVid > 0 && isset($emsControlled[$tVid]);
    }

    /**
     * Daten für den Regel-Editor der Kachel: Datenpunkte (Quellen) und
     * schaltbare Zielvariablen mit Objektbaum-Pfad. JSON: {sources:[{v,c}], targets:[{v,c,p}]}
     */
    public function GetDataActionEditor(): string
    {
        $sources = [];
        foreach ($this->getAutomationSourceOptions() as $o) {
            $sources[] = ['v' => $o['value'], 'c' => $o['caption']];
        }

        $targets = [];
        foreach (IPS_GetVariableList() as $vid) {
            $var = IPS_GetVariable($vid);
            if ((int) $var['VariableAction'] <= 0 && (int) $var['VariableCustomAction'] <= 0) {
                continue;
            }
            $targets[] = ['v' => $vid, 'c' => IPS_GetName($vid), 'p' => IPS_GetLocation($vid)];
            if (count($targets) >= 1000) {
                break;
            }
        }
        usort($targets, function ($a, $b) {
            return strcasecmp($a['p'], $b['p']);
        });

        return json_encode(['sources' => $sources, 'targets' => $targets]);
    }

    /**
     * Auswählbare Werte einer Zielvariable (Presentation-Enumeration/-Switch bzw.
     * Legacy-Profil-Assoziationen) als JSON [{v, c}]. Leer, wenn frei einzugeben.
     */
    public function GetTargetValueOptions(int $VariableID): string
    {
        if ($VariableID <= 0 || !IPS_VariableExists($VariableID)) {
            return '[]';
        }
        $out = [];
        $var = IPS_GetVariable($VariableID);

        $pres = @IPS_GetVariablePresentation($VariableID);
        if (is_array($pres)) {
            $p = $pres['PRESENTATION'] ?? '';
            if ($p === VARIABLE_PRESENTATION_ENUMERATION) {
                $opts = json_decode((string) ($pres['OPTIONS'] ?? '[]'), true);
                if (is_array($opts)) {
                    foreach ($opts as $o) {
                        if (is_array($o) && isset($o['Value'])) {
                            $out[] = ['v' => $o['Value'], 'c' => (string) ($o['Caption'] ?? $o['Value'])];
                        }
                    }
                }
            } elseif ($p === VARIABLE_PRESENTATION_SWITCH) {
                $out[] = ['v' => 1, 'c' => (string) ($pres['CAPTION_ON'] ?? 'Ein')];
                $out[] = ['v' => 0, 'c' => (string) ($pres['CAPTION_OFF'] ?? 'Aus')];
            }
        }

        if (count($out) === 0) {
            $profile = ($var['VariableCustomProfile'] !== '') ? $var['VariableCustomProfile'] : $var['VariableProfile'];
            if ($profile !== '' && IPS_VariableProfileExists($profile)) {
                foreach (IPS_GetVariableProfile($profile)['Associations'] as $a) {
                    $out[] = ['v' => $a['Value'], 'c' => (string) $a['Name']];
                }
            }
        }

        if (count($out) === 0 && (int) $var['VariableType'] === VARIABLETYPE_BOOLEAN) {
            $out = [['v' => 1, 'c' => 'Ein'], ['v' => 0, 'c' => 'Aus']];
        }
        return json_encode($out);
    }

    /** Regeln als JSON für die Kachel: [{i, text, active, emsConflict, rule}] */
    public function GetDataActions(): string
    {
        $rules = json_decode((string) $this->ReadPropertyString('DataActions'), true);
        $emsControlled = $this->getEmsControlledVariableIds();
        $out = [];
        if (is_array($rules)) {
            foreach ($rules as $i => $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $out[] = [
                    'i'           => $i,
                    'text'        => $this->describeDataAction($rule),
                    'active'      => (bool) ($rule['Active'] ?? true),
                    'emsConflict' => $this->ruleHasEmsConflict($rule, $emsControlled),
                    'rule'        => [
                        'Conditions' => $this->normalizeRuleConditions($rule),
                        'Target'     => (int) ($rule['Target'] ?? 0),
                        'Action'     => (string) ($rule['Action'] ?? 'on'),
                        'Value'      => (string) ($rule['Value'] ?? '')
                    ]
                ];
            }
        }
        return json_encode($out);
    }

    /**
     * Legt eine Regel an oder überschreibt sie ($Index < 0 = anhängen).
     * $RuleJSON: {Active, Conditions:[{Source,Op,Compare},...] (mit UND verknüpft), Target, Action, Value}
     */
    public function SetDataAction(int $Index, string $RuleJSON): void
    {
        $in = json_decode($RuleJSON, true);
        if (!is_array($in)) {
            return;
        }
        $ops = ['true', 'false', 'eq', 'ne', 'gt', 'ge', 'lt', 'le', 'change'];
        $acts = ['on', 'off', 'toggle', 'value'];

        $conditions = [];
        foreach ((array) ($in['Conditions'] ?? []) as $c) {
            if (!is_array($c)) {
                continue;
            }
            $src = trim((string) ($c['Source'] ?? ''));
            if ($src === '') {
                continue;
            }
            $conditions[] = [
                'Source'  => $src,
                'Op'      => in_array(($c['Op'] ?? ''), $ops, true) ? (string) $c['Op'] : 'true',
                'Compare' => (string) ($c['Compare'] ?? '')
            ];
        }
        $conditions = array_slice($conditions, 0, 5);
        if (count($conditions) === 0) {
            return;
        }

        $rule = [
            'Active'     => (bool) ($in['Active'] ?? true),
            'Conditions' => $conditions,
            // Erste Bedingung zusätzlich flach spiegeln, für die klassische
            // Formular-Liste (Source/Op/Compare-Spalten)
            'Source'     => $conditions[0]['Source'],
            'Op'         => $conditions[0]['Op'],
            'Compare'    => $conditions[0]['Compare'],
            'Target'     => (int) ($in['Target'] ?? 0),
            'Action'     => in_array(($in['Action'] ?? ''), $acts, true) ? (string) $in['Action'] : 'on',
            'Value'      => (string) ($in['Value'] ?? '')
        ];
        if ($rule['Target'] <= 0) {
            return;
        }

        $rules = json_decode((string) $this->ReadPropertyString('DataActions'), true);
        if (!is_array($rules)) {
            $rules = [];
        }
        if ($Index >= 0 && isset($rules[$Index])) {
            $rules[$Index] = $rule;
        } else {
            $rules[] = $rule;
        }
        IPS_SetProperty($this->InstanceID, 'DataActions', json_encode(array_values($rules)));
        IPS_ApplyChanges($this->InstanceID);
    }

    public function DeleteDataAction(int $Index): void
    {
        $rules = json_decode((string) $this->ReadPropertyString('DataActions'), true);
        if (!is_array($rules) || !isset($rules[$Index])) {
            return;
        }
        unset($rules[$Index]);
        IPS_SetProperty($this->InstanceID, 'DataActions', json_encode(array_values($rules)));
        IPS_ApplyChanges($this->InstanceID);
    }

    /** Aktiviert/deaktiviert eine Regel (z. B. aus der Kachel). */
    public function SetDataActionActive(int $Index, bool $Active): void
    {
        $rules = json_decode((string) $this->ReadPropertyString('DataActions'), true);
        if (!is_array($rules) || !isset($rules[$Index]) || !is_array($rules[$Index])) {
            return;
        }
        if ((bool) ($rules[$Index]['Active'] ?? true) === $Active) {
            return;
        }
        $rules[$Index]['Active'] = $Active;
        IPS_SetProperty($this->InstanceID, 'DataActions', json_encode($rules));
        IPS_ApplyChanges($this->InstanceID);
    }
}
