<?php

declare(strict_types=1);

/**
 * StromGedachtTile
 *
 * Eigenständige HTML-SDK-Kachel für die Tile-Visualisierung. Liest die Ampel-/Signal-Werte
 * einer StromGedachtWidget-Instanz (Quelle) und stellt sie als randlose, frei gestaltbare
 * Status-Kachel dar. Verwaltet zusätzlich die Wenn->Dann-Automationen der Quelle.
 *
 * Bewusst von der Datenlogik getrennt (Vorbild da8ter / TibberGridRewardTile / TessieVehicleTile):
 * Ein Problem in der Kachel kann die Datenverbindung der Quell-Instanz nicht beeinträchtigen.
 */
class StromGedachtTile extends IPSModule
{
    // GUID des Datenmoduls StromGedachtWidget (für die Quellen-Auswahl)
    private const SOURCE_MODULE = '{D5A8C3A1-2222-4A55-8888-123456789003}';

    private const WATCH_IDENTS = ['State', 'Text', 'GSI', 'ECSignal', 'ECShare', 'Updated'];

    private const COLOR_NODATA = '#9e9e9e';

    // Zustände laut StromGedacht API: -1 Supergrün, 1 Grün, 2 Gelb, 3 Orange, 4 Rot
    private const SG_LABELS = [-1 => 'Supergrün', 1 => 'Grün', 2 => 'Gelb', 3 => 'Orange', 4 => 'Rot'];
    // Wortwahl an die offizielle StromGedacht-App/Website (TransnetBW) angeglichen,
    // siehe StromGedachtWidget/module.php.
    private const SG_TEXTS = [
        -1 => 'Strom bevorzugt jetzt nutzen – besonders viel erneuerbare Energie im Netz',
        1  => 'Strom wie gewohnt nutzen – Normalbetrieb',
        2  => 'Angespannte Netzsituation',
        3  => 'Verbrauch reduzieren – hilft, Kosten und CO₂ zu sparen',
        4  => 'Verbrauch vermeiden – hilft, einen Strommangel zu verhindern'
    ];
    private const SG_LEVEL = [-1 => 'supergreen', 1 => 'green', 2 => 'yellow', 3 => 'orange', 4 => 'red'];

    // Signale laut Energy-Charts API: -1/0 Rot, 1 Gelb, 2 Grün
    private const EC_LABELS = [-1 => 'Rot (Netzengpass)', 0 => 'Rot', 1 => 'Gelb', 2 => 'Grün'];
    private const EC_TEXTS = [
        -1 => 'Netzengpass – Verbrauch reduzieren',
        0  => 'Niedriger Anteil erneuerbarer Energien',
        1  => 'Durchschnittlicher Anteil erneuerbarer Energien',
        2  => 'Hoher Anteil erneuerbarer Energien'
    ];
    private const EC_LEVEL = [-1 => 'red', 0 => 'red', 1 => 'yellow', 2 => 'green'];

    // Standardwerte (auch für „Zurücksetzen")
    private const DEF_SUPERGREEN = 0x00BFA5;
    private const DEF_GREEN      = 0x00C853;
    private const DEF_YELLOW     = 0xFFD600;
    private const DEF_ORANGE     = 0xFF6D00;
    private const DEF_RED        = 0xD50000;
    private const DEF_BACKGROUND = -1;
    private const DEF_BOX        = -1;
    private const DEF_TEXT       = -1;
    private const DEF_TEXTMUTED  = -1;
    private const DEF_FONT       = 'system';
    private const DEF_SCALE      = 1.0;

    // Muss bei jeder Version mit sichtbaren Neuerungen mitgezogen werden (Formular-Konvention
    // des NRG-Stack: "🆕 Neu in Version"-Panel + Versionsnummer im Doku-Panel)
    private const MODULE_VERSION = '1.5.0';
    private const NEWS_ITEMS = [
        'Lizenzwechsel: PolyForm Noncommercial 1.0.0 statt MIT — private/nicht-kommerzielle Nutzung bleibt frei, gewerbliche Nutzung ist ab jetzt lizenzpflichtig.',
        'Teil des NRG-Stack (DG65-Modulverbund) — siehe SUITE.md, welche Modulstände zusammenpassen.'
    ];

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterPropertyInteger('SourceInstance', 0);
        $this->RegisterPropertyBoolean('AdoptWidgetName', true);
        $this->RegisterPropertyInteger('ColorSuperGreen', self::DEF_SUPERGREEN);
        $this->RegisterPropertyInteger('ColorGreen', self::DEF_GREEN);
        $this->RegisterPropertyInteger('ColorYellow', self::DEF_YELLOW);
        $this->RegisterPropertyInteger('ColorOrange', self::DEF_ORANGE);
        $this->RegisterPropertyInteger('ColorRed', self::DEF_RED);
        $this->RegisterPropertyInteger('ColorBackground', self::DEF_BACKGROUND);
        $this->RegisterPropertyInteger('ColorBox', self::DEF_BOX);
        $this->RegisterPropertyInteger('ColorText', self::DEF_TEXT);
        $this->RegisterPropertyInteger('ColorTextMuted', self::DEF_TEXTMUTED);
        $this->RegisterPropertyString('FontFamily', self::DEF_FONT);
        $this->RegisterPropertyFloat('FontScale', self::DEF_SCALE);
        $this->RegisterPropertyBoolean('ShowAutomations', true);

        $this->SetVisualizationType(1);
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        $this->SetVisualizationType(1);

        // Bisherige VM_UPDATE-Registrierungen lösen
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $msg) {
                if ($msg === VM_UPDATE) {
                    $this->UnregisterMessage($senderID, VM_UPDATE);
                }
            }
        }

        $src = $this->ResolveSource();
        if ($src > 0 && IPS_InstanceExists($src)) {
            foreach (self::WATCH_IDENTS as $ident) {
                $vid = @IPS_GetObjectIDByIdent($ident, $src);
                if ($vid !== false && $vid > 0) {
                    $this->RegisterReference($vid);
                    $this->RegisterMessage($vid, VM_UPDATE);
                }
            }
            $this->SetStatus(102);

            if ($this->ReadPropertyBoolean('AdoptWidgetName')) {
                $sourceName = IPS_GetName($src);
                if ($sourceName !== '' && IPS_GetName($this->InstanceID) !== $sourceName) {
                    IPS_SetName($this->InstanceID, $sourceName);
                }
            }
        } else {
            $this->SetStatus(104);
        }

        $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === VM_UPDATE) {
            $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        if (!is_array($form)) {
            $form = ['elements' => [], 'actions' => [], 'status' => []];
        }

        // Versionsnummer ins Doku-Panel patchen (Formular-Konvention des NRG-Stack)
        foreach ($form['elements'] as &$element) {
            if (is_array($element) && ($element['name'] ?? '') === 'DocPanel') {
                $element['caption'] = '📖 Dokumentation & Hilfe (v' . self::MODULE_VERSION . ')';
            }
        }
        unset($element);

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
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'SGWTILE_AckNews($id);'];
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => '🆕 Neu in Version ' . self::MODULE_VERSION, 'expanded' => true, 'items' => $items];
    }

    public function AckNews(): void
    {
        $this->WriteAttributeString('SeenNews', self::MODULE_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    /**
     * Aktion aus der Kachel: an die Quell-Instanz weiterreichen.
     */
    public function RequestAction($Ident, $Value)
    {
        $src = $this->ResolveSource();
        if ($src <= 0) {
            return;
        }

        if ($Ident === 'refresh') {
            @SGW_Update($src);
            $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
            return;
        }
        if ($Ident === 'rule') {
            $data = json_decode((string) $Value, true);
            if (is_array($data) && isset($data['i'])) {
                @SGW_SetDataActionActive($src, (int) $data['i'], (bool) ($data['on'] ?? false));
                $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
            }
            return;
        }
        if ($Ident === 'ruleEditor') {
            $editor = json_decode((string) @SGW_GetDataActionEditor($src), true);
            $this->UpdateVisualizationValue(json_encode(['editor' => is_array($editor) ? $editor : ['sources' => [], 'targets' => []]]));
            return;
        }
        if ($Ident === 'targetOpts') {
            $vid = (int) $Value;
            $opts = json_decode((string) @SGW_GetTargetValueOptions($src, $vid), true);
            $this->UpdateVisualizationValue(json_encode(['targetOpts' => ['vid' => $vid, 'options' => is_array($opts) ? $opts : []]]));
            return;
        }
        if ($Ident === 'condOpts') {
            // Profilwerte des gewählten Wenn-Datenpunkts (z. B. SGW.State) für den
            // Vergleichswert-Dropdown im Regel-Editor; leer = freie Eingabe
            $source = (string) $Value;
            $vid = $source !== '' ? @IPS_GetObjectIDByIdent($source, $src) : false;
            $opts = ($vid !== false && $vid > 0) ? json_decode((string) @SGW_GetTargetValueOptions($src, $vid), true) : [];
            $this->UpdateVisualizationValue(json_encode(['condOpts' => ['source' => $source, 'options' => is_array($opts) ? $opts : []]]));
            return;
        }
        if ($Ident === 'ruleSave') {
            $data = json_decode((string) $Value, true);
            if (is_array($data) && isset($data['rule'])) {
                @SGW_SetDataAction($src, (int) ($data['i'] ?? -1), json_encode($data['rule']));
                $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
            }
            return;
        }
        if ($Ident === 'ruleDelete') {
            @SGW_DeleteDataAction($src, (int) $Value);
            $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
            return;
        }
    }

    /**
     * Button-Aktion: alle Farben und Schrifteinstellungen auf Standard zurücksetzen.
     */
    public function ResetStyle(): void
    {
        // Nur die offene Konfiguration setzen; der Nutzer bestätigt selbst mit
        // „Änderungen übernehmen" (vom Symcon-Review empfohlenes Muster).
        $this->UpdateFormField('ColorSuperGreen', 'value', self::DEF_SUPERGREEN);
        $this->UpdateFormField('ColorGreen', 'value', self::DEF_GREEN);
        $this->UpdateFormField('ColorYellow', 'value', self::DEF_YELLOW);
        $this->UpdateFormField('ColorOrange', 'value', self::DEF_ORANGE);
        $this->UpdateFormField('ColorRed', 'value', self::DEF_RED);
        $this->UpdateFormField('ColorBackground', 'value', self::DEF_BACKGROUND);
        $this->UpdateFormField('ColorBox', 'value', self::DEF_BOX);
        $this->UpdateFormField('ColorText', 'value', self::DEF_TEXT);
        $this->UpdateFormField('ColorTextMuted', 'value', self::DEF_TEXTMUTED);
        $this->UpdateFormField('FontFamily', 'value', self::DEF_FONT);
        $this->UpdateFormField('FontScale', 'value', self::DEF_SCALE);
    }

    public function GetVisualizationTile()
    {
        $module = file_get_contents(__DIR__ . '/module.html');
        // handleMessage() ist erst im HTML definiert -> initialen Aufruf ans Ende hängen.
        $module .= '<script>handleMessage(' . json_encode($this->GetFullUpdateMessage()) . ');</script>';
        return $module;
    }

    // ---------------------------------------------------------------------
    // Datenaufbereitung
    // ---------------------------------------------------------------------

    private function GetFullUpdateMessage(): string
    {
        $style = [
            'bg'        => $this->ColorOrEmpty($this->ReadPropertyInteger('ColorBackground')),
            'box'       => $this->ColorOrEmpty($this->ReadPropertyInteger('ColorBox')),
            'text'      => $this->ColorOrEmpty($this->ReadPropertyInteger('ColorText')),
            'textmuted' => $this->ColorOrEmpty($this->ReadPropertyInteger('ColorTextMuted')),
            'font'      => $this->FontStack($this->ReadPropertyString('FontFamily')),
            'scale'     => $this->FontScaleValue()
        ];

        $src = $this->ResolveSource();
        if ($src <= 0 || !IPS_InstanceExists($src)) {
            return json_encode(array_merge($style, [
                'name'    => 'StromGedacht',
                'missing' => true,
                'columns' => [],
                'updated' => null,
                'rules'   => null
            ]));
        }

        return json_encode(array_merge($style, [
            'name'    => IPS_GetName($src),
            'missing' => false,
            'columns' => $this->BuildColumns($src),
            'updated' => $this->ReadSourceValue($src, 'Updated'),
            'rules'   => $this->ReadSourceRules($src)
        ]));
    }

    private function BuildColumns(int $src): array
    {
        $columns = [];

        $state = $this->ReadSourceValue($src, 'State');
        if ($state !== null) {
            $state = (int) $state;
            $columns[] = [
                'title' => 'StromGedacht',
                'color' => $this->ColorForLevel(self::SG_LEVEL[$state] ?? null),
                'label' => self::SG_LABELS[$state] ?? 'Unbekannt',
                'text'  => self::SG_TEXTS[$state] ?? ''
            ];
        }

        $gsi = $this->ReadSourceValue($src, 'GSI');
        if ($gsi !== null) {
            $gsi = (float) $gsi;
            if ($gsi >= 66) {
                $level = 'green';
                $text = 'Hoher Grünstrom-Anteil in der Region';
            } elseif ($gsi >= 33) {
                $level = 'yellow';
                $text = 'Durchschnittlicher Grünstrom-Anteil in der Region';
            } else {
                $level = 'red';
                $text = 'Niedriger Grünstrom-Anteil in der Region';
            }
            $columns[] = [
                'title' => 'GrünstromIndex',
                'color' => $this->ColorForLevel($level),
                'label' => number_format($gsi, 0) . ' %',
                'text'  => $text
            ];
        }

        $ecSignal = $this->ReadSourceValue($src, 'ECSignal');
        if ($ecSignal !== null) {
            $ecSignal = (int) $ecSignal;
            $share = $this->ReadSourceValue($src, 'ECShare');
            $text = self::EC_TEXTS[$ecSignal] ?? 'Unbekanntes Signal';
            if ($share !== null) {
                $text .= ' (EE-Anteil: ' . number_format((float) $share, 1, ',', '.') . ' %)';
            }
            $columns[] = [
                'title' => 'Energy-Charts',
                'color' => $this->ColorForLevel(self::EC_LEVEL[$ecSignal] ?? null),
                'label' => self::EC_LABELS[$ecSignal] ?? 'Unbekannt',
                'text'  => $text
            ];
        }

        return $columns;
    }

    private function ColorForLevel(?string $level): string
    {
        switch ($level) {
            case 'supergreen': return $this->ColorHex($this->ReadPropertyInteger('ColorSuperGreen'), '#00bfa5');
            case 'green':       return $this->ColorHex($this->ReadPropertyInteger('ColorGreen'), '#00c853');
            case 'yellow':      return $this->ColorHex($this->ReadPropertyInteger('ColorYellow'), '#ffd600');
            case 'orange':      return $this->ColorHex($this->ReadPropertyInteger('ColorOrange'), '#ff6d00');
            case 'red':         return $this->ColorHex($this->ReadPropertyInteger('ColorRed'), '#d50000');
            default:            return self::COLOR_NODATA;
        }
    }

    private function ResolveSource(): int
    {
        $configured = $this->ReadPropertyInteger('SourceInstance');
        if ($configured > 0 && IPS_InstanceExists($configured)) {
            return $configured;
        }
        $list = IPS_GetInstanceListByModuleID(self::SOURCE_MODULE);
        if (count($list) === 1) {
            return (int) $list[0];
        }
        return 0;
    }

    /**
     * Wenn->Dann-Regeln der Quelle für die Kachel ([{i,text,active,rule}] oder null,
     * wenn Automationen in der Kachel ausgeblendet sind).
     */
    private function ReadSourceRules(int $instanceID): ?array
    {
        if (!$this->ReadPropertyBoolean('ShowAutomations')) {
            return null;
        }
        $json = @SGW_GetDataActions($instanceID);
        $rules = is_string($json) ? json_decode($json, true) : null;
        return is_array($rules) ? $rules : null;
    }

    private function ReadSourceValue(int $instanceID, string $ident)
    {
        $vid = @IPS_GetObjectIDByIdent($ident, $instanceID);
        if ($vid === false || $vid <= 0) {
            return null;
        }
        return GetValue($vid);
    }

    private function FontStack(string $key): string
    {
        switch ($key) {
            case 'arial':     return 'Arial, Helvetica, sans-serif';
            case 'verdana':   return 'Verdana, Geneva, sans-serif';
            case 'tahoma':    return 'Tahoma, Geneva, sans-serif';
            case 'trebuchet': return '"Trebuchet MS", Helvetica, sans-serif';
            case 'georgia':   return 'Georgia, "Times New Roman", serif';
            case 'courier':   return '"Courier New", Courier, monospace';
            case 'system':
            default:          return "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";
        }
    }

    private function FontScaleValue(): float
    {
        $v = $this->ReadPropertyFloat('FontScale');
        if ($v < 0.5) {
            $v = 0.5;
        }
        if ($v > 2.5) {
            $v = 2.5;
        }
        return $v;
    }

    private function ColorHex(int $value, string $fallback): string
    {
        if ($value < 0) {
            return $fallback;
        }
        return sprintf('#%06X', $value & 0xFFFFFF);
    }

    private function ColorOrEmpty(int $value): string
    {
        return $value < 0 ? '' : sprintf('#%06X', $value & 0xFFFFFF);
    }
}
