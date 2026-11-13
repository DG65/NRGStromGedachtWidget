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
    // Eigene Modul-GUID (= module.json "id") für die Geschwister-Instanz-Suche beim
    // Ausblenden-Teilen (Cross-Instanz, siehe PropagateDismiss()).
    private const GUID_TILE = '{E9B65213-BA33-426D-8486-D350A7DFCFEF}';
    private const FORUM_THREAD_URL = 'https://community.symcon.de/t/modul-strom-gedacht-ampel-widget/143960';
    private const LICENSE_URL = 'https://github.com/DG65/NRGStromGedachtWidget/blob/main/LICENSE';
    private const PAYPAL_URL = 'https://paypal.me/DietmarGureth';

    // Wählbare Fachpanels für "Formular: Aufbau" (SUITE.md 3a): stabiler Schlüssel => Element-Name
    // in form.json, in Standardreihenfolge. Die Schlüssel werden in der Eigenschaft FormLayout
    // gespeichert und dürfen sich nie ändern (die Beschriftungen schon).
    private const LAYOUT_PANELS_BY_KEY = ['colors' => 'PanelColors', 'surface' => 'PanelSurface', 'font' => 'PanelFont'];
    private const LAYOUT_PANELS = ['PanelColors' => 'colors', 'PanelSurface' => 'surface', 'PanelFont' => 'font'];

    private const WATCH_IDENTS = ['State', 'Text', 'GSI', 'ECSignal', 'ECShare', 'Updated'];

    private const COLOR_NODATA = '#9e9e9e';

    // Zustände laut StromGedacht API: -1 Supergrün, 1 Grün, 2 Gelb, 3 Orange, 4 Rot
    private const SG_LABELS = [-1 => 'Super green', 1 => 'Green', 2 => 'Yellow', 3 => 'Orange', 4 => 'Red'];
    // Wortwahl an die offizielle StromGedacht-App/Website (TransnetBW) angeglichen,
    // siehe StromGedachtWidget/module.php.
    private const SG_TEXTS = [
        -1 => 'Use electricity preferably now – especially a lot of renewable energy in the grid',
        1  => 'Use electricity as usual – normal operation',
        2  => 'Strained grid situation',
        3  => 'Reduce consumption – helps to save costs and CO₂',
        4  => 'Avoid consumption – helps to prevent an electricity shortage'
    ];
    private const SG_LEVEL = [-1 => 'supergreen', 1 => 'green', 2 => 'yellow', 3 => 'orange', 4 => 'red'];

    // Signale laut Energy-Charts API: -1/0 Rot, 1 Gelb, 2 Grün
    private const EC_LABELS = [-1 => 'Red (grid bottleneck)', 0 => 'Red', 1 => 'Yellow', 2 => 'Green'];
    private const EC_TEXTS = [
        -1 => 'Grid bottleneck – reduce consumption',
        0  => 'Low share of renewable energy',
        1  => 'Average share of renewable energy',
        2  => 'High share of renewable energy'
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
    private const MODULE_VERSION = '1.10.1';
    private const NEWS_ITEMS = [
        'Light/dark detection fixed: the tile now takes its text color from the Symcon design (the colors of the app itself) instead of the device setting. Before, white text on a light background could appear when the app was light but the device was dark.',
        '🌐 Now bilingual (English/German): form, messages, status texts and the tile follow the Symcon language. The "don\'t show again" state is now synchronized with other tiles when the form is opened, no longer while changes are applied.',
        '🪟 New: choose the form layout yourself. In the new, collapsed panel "Form: layout" you decide for traffic-light colors, surfaces & text and font whether they are expanded, collapsed or hidden when the form opens, and in which order they appear. Hidden sections keep working, their settings are kept. No checkbox = as before.',
        'If the data source is detected automatically (exactly one StromGedachtWidget instance, no choice of your own), the "Data source" field is now hidden and only the line "🔗 Data source: ..." is shown. If you choose an instance yourself, the field stays visible. The line also follows your selection immediately, before applying.',
        'The form now shows which data source was detected: instance, name, how it was determined (automatic/manual) and which values are currently shown. With several instances and no selection, or with no instance found, there is a clear notice instead of a static sentence.',
        'New panel "🧡 About this module" (license/donations) at the very bottom of the form, plus a new dismissible forum hint "💬 Feedback in the Symcon forum" (there was none before).',
        'Do you have several tiles (e.g. for different StromGedachtWidget sources)? "What is this module for?", "What is new?" and the forum hint no longer have to be dismissed on every tile separately — one click on one tile is enough for all.',
        '👋 New panel "What is this module for?" at the very top of the form — a short introduction, can be hidden once.',
        '🔧 Internal robustness: form values read in (colors, font, scaling) are now consistently type-checked before further processing (prevents rare crashes while a module is reloading).',
        'Security fix: the automation management (create/edit/delete rule, target variable list) is now also blocked on the server side when "Show automations" is disabled — before, it was only hidden on the client.',
        'New button "🔄 Force apply" calls IPS_ApplyChanges() directly, without you having to change anything in the form first.',
        'License change: PolyForm Noncommercial 1.0.0 instead of MIT — private/non-commercial use stays free, commercial use now requires a license.',
        'Part of the NRG-Stack (DG65 module family) — see SUITE.md for which module versions fit together.'
    ];

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        $this->RegisterAttributeBoolean('ForumHintGone', false);
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
        $this->RegisterPropertyString('FormLayout', '[]');

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

        // Abgleich des "nicht mehr anzeigen"-Stands mit Geschwister-Kacheln hier statt in
        // ApplyChanges: Beim Neuladen von Modulen sind die anderen Instanzen evtl. noch nicht
        // geladen (Symcon-Review Tessie 2.38.3, Store-Checkliste 27, SUITE.md).
        $this->AdoptDismissFromSibling();

        // Versionsnummer ins Doku-Panel patchen (Formular-Konvention des NRG-Stack)
        foreach ($form['elements'] as &$element) {
            if (is_array($element) && ($element['name'] ?? '') === 'DocPanel') {
                $element['caption'] = sprintf($this->Translate('📖 Documentation & help (v%s)'), self::MODULE_VERSION);
            }
        }
        unset($element);

        // Live berechnete Statuszeile zur automatisch erkannten/gewählten Datenquelle
        // (Verbund-Regel "Verbund-Verbindungen im Formular sichtbar machen", SUITE.md) -
        // ein statischer Satz "wird automatisch erkannt" sagt nicht, ob es geklappt hat.
        $statusLine = $this->sourceStatusLine();
        $this->setElementProperty($form['elements'], 'SourceStatus', 'caption', $statusLine);
        $this->setElementProperty($form['elements'], 'SourceStatus', 'color', $this->sourceStatusColor($statusLine));
        // Wert kommt automatisch: Eingabefeld ausblenden statt nur erklären (SUITE.md "Wert kommt
        // automatisch: Eingabefeld ersetzen"). Nur bei genau einer Instanz und ohne eigene Wahl -
        // sonst (eigene Wahl, mehrere oder keine Instanz) bleibt das Auswahlfeld sichtbar. Nie den
        // automatischen Wert ins Feld schreiben (würde beim Übernehmen als eigene Angabe gespeichert).
        if ($this->sourceIsAutomatic()) {
            $this->setElementProperty($form['elements'], 'SourceInstance', 'visible', false);
        }

        // Fachpanels (Farben, Flächen & Text, Schrift) nach der Einstellung "Formular: Aufbau"
        // anordnen, aufklappen/zuklappen oder ausblenden (Formular-Konvention Punkt 3a, SUITE.md).
        // Sie werden an der Stelle des ersten Fachpanels wieder eingesetzt; die losen Felder
        // davor und danach (Datenquelle, "Automationen anzeigen", Reset-Knopf) bleiben stehen.
        $free = [];
        $firstIndex = null;
        foreach ($form['elements'] as $i => $element) {
            $key = is_array($element) ? (self::LAYOUT_PANELS[$element['name'] ?? ''] ?? null) : null;
            if ($key !== null) {
                $free[$key] = $element;
                $firstIndex ??= $i;
                unset($form['elements'][$i]);
            }
        }
        $form['elements'] = array_values($form['elements']);
        $layout = $this->formLayout(array_keys(self::LAYOUT_PANELS_BY_KEY));
        $sorted = [];
        foreach ($layout as $key => $l) {
            if (!isset($free[$key])) {
                continue;
            }
            $panel = $free[$key];
            if ($l['open'] === 'open') {
                $panel['expanded'] = true;
            } elseif ($l['open'] === 'closed') {
                $panel['expanded'] = false;
            } elseif ($l['open'] === 'hidden') {
                // Nicht weglassen: die Felder bleiben im Formular, gespeicherte Werte bleiben beim "Übernehmen" erhalten
                $panel['visible'] = false;
            }
            $sorted[] = $panel;
        }
        array_splice($form['elements'], $firstIndex ?? count($form['elements']), 0, $sorted);
        $form['elements'][] = $this->LayoutPanel($layout, $free);

        // Symcon-Forum-Hinweis, dann Lizenz-/Spenden-Hinweis - beide ganz unten,
        // nach den Haupteinstellungen (Formular-Konvention Punkt 4/5, SUITE.md)
        $forumHint = $this->ForumHint();
        if ($forumHint !== null) {
            $form['elements'][] = $forumHint;
        }
        $form['elements'][] = $this->LicenseHint();

        // "🆕 Neu in Version X.Y"-Panel ganz oben: erscheint bis zur Bestätigung, danach je
        // Version erneut (Attribut speichert die zuletzt bestätigte Version)
        $banner = $this->newsBanner();
        if ($banner !== null) {
            array_unshift($form['elements'], $banner);
        }

        // "👋 Wozu dieses Modul?"-Panel ganz oben, VOR dem News-Panel (NRG-Stack-Formular-
        // Konvention Punkt 0, SUITE.md) - zuletzt unshiften, damit es vor dem News-Panel landet
        $purposeIntro = $this->PurposeIntro();
        if ($purposeIntro !== null) {
            array_unshift($form['elements'], $purposeIntro);
        }

        return json_encode($form);
    }

    /** "👋 Wozu dieses Modul?"-Panel: null, wenn der Nutzer es schon bestätigt hat. */
    private function PurposeIntro(): ?array
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true,
            'caption' => '👋  What is this module for?',
            'items' => [
                ['type' => 'Label', 'caption' => 'This tile shows the traffic-light/signal values of a StromGedachtWidget instance directly in the WebFront — handy when you are on the go or on a tablet and want to keep an eye on when electricity in the grid is particularly green or particularly scarce.'],
                ['type' => 'Label', 'caption' => 'In addition, you can create, edit and enable your own If→Then rules here without opening the management console (e.g. switch on hot water at super green) — the same rules that are available in the widget\'s classic instance form.'],
                ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => 'SGWTILE_AckPurposeIntro($id);'],
            ],
        ];
    }

    public function AckPurposeIntro(): void
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
        $this->PropagateDismiss('PurposeIntro');
    }

    /** "🆕 Neu in Version"-Panel: null, wenn der Nutzer diese Version schon bestätigt hat. */
    private function newsBanner(): ?array
    {
        if ($this->ReadAttributeString('SeenNews') === self::MODULE_VERSION) {
            return null;
        }
        $items = [['type' => 'Label', 'caption' => $this->Translate('🆕 New in this module — please take a quick look and check your settings if necessary:')]];
        foreach (self::NEWS_ITEMS as $line) {
            $items[] = ['type' => 'Label', 'caption' => '• ' . $this->Translate($line)];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => 'SGWTILE_AckNews($id);'];
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => sprintf($this->Translate('🆕 New in version %s'), self::MODULE_VERSION), 'expanded' => true, 'items' => $items];
    }

    public function AckNews(): void
    {
        $this->WriteAttributeString('SeenNews', self::MODULE_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
        $this->PropagateDismiss('News', self::MODULE_VERSION);
    }

    /** Symcon-Forum-Hinweis — einmalig dismissible, kein Versionsbezug (Formular-Konvention Punkt 4, SUITE.md). */
    private function ForumHint(): ?array
    {
        if ($this->ReadAttributeBoolean('ForumHintGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true,
            'caption' => '💬  Feedback in the Symcon forum',
            'items' => [
                ['type' => 'Label', 'caption' => '⭐ Do you like this tile? I am happy about a rating in the Module Store or feedback in the community thread!'],
                ['type' => 'Button', 'caption' => 'Go to the forum thread', 'onClick' => "echo '" . self::FORUM_THREAD_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => 'SGWTILE_AckForumHint($id);'],
            ],
        ];
    }

    public function AckForumHint(): void
    {
        $this->WriteAttributeBoolean('ForumHintGone', true);
        $this->UpdateFormField('ForumHintPanel', 'visible', false);
        $this->PropagateDismiss('ForumHint');
    }

    /**
     * Reihenfolge und Aufklapp-Zustand der Fachpanels aus der Eigenschaft FormLayout.
     * Unbekannte oder fehlende Einträge fallen auf Standardreihenfolge und "Standard" zurück.
     *
     * @param string[] $keys Schlüssel der Fachpanels in Standardreihenfolge
     * @return array key => ['open' => 'std'|'open'|'closed'|'hidden', 'pos' => int, 'idx' => int], in der anzuwendenden Reihenfolge
     */
    private function formLayout(array $keys): array
    {
        $saved = json_decode((string) $this->ReadPropertyString('FormLayout'), true);
        $by = [];
        foreach (is_array($saved) ? $saved : [] as $row) {
            if (is_array($row) && isset($row['Key'])) {
                $by[(string) $row['Key']] = $row;
            }
        }
        $out = [];
        foreach ($keys as $i => $k) {
            $r = $by[$k] ?? [];
            // Kontrollkästchen; Vorrang bei Widerspruch: ausgeblendet vor zugeklappt vor aufgeklappt, keines = Standard
            $open = !empty($r['Hidden']) ? 'hidden' : (!empty($r['Closed']) ? 'closed' : (!empty($r['Opened']) ? 'open' : 'std'));
            $out[$k] = [
                'open' => $open,
                'pos'  => isset($r['Pos']) ? max(1, min(99, (int) $r['Pos'])) : $i + 1,
                'idx'  => $i
            ];
        }
        uasort($out, function ($a, $b) {
            return [$a['pos'], $a['idx']] <=> [$b['pos'], $b['idx']];
        });
        return $out;
    }

    /** Wählbarer Aufbau des Formulars: je Fachpanel Aufklapp-Zustand beim Öffnen und Position (SUITE.md 3a). */
    private function LayoutPanel(array $layout, array $free): array
    {
        $rows = [];
        $hidden = [];
        foreach ($layout as $key => $l) {
            $name = trim((string) preg_replace('/\s*\(.*$/u', '', $this->Translate((string) ($free[$key]['caption'] ?? $key))));
            $rows[] = ['Key' => $key, 'Panel' => $name, 'Opened' => $l['open'] === 'open', 'Closed' => $l['open'] === 'closed',
                'Hidden' => $l['open'] === 'hidden', 'Pos' => $l['pos']];
            if ($l['open'] === 'hidden') {
                $hidden[] = trim((string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $name));
            }
        }
        $hiddenLine = $hidden
            ? [['type' => 'Label', 'caption' => sprintf($this->Translate('🙈 Hidden: %s — to show again, clear the "Hidden" checkbox. Hidden sections keep working, only the display is missing.'), implode(', ', $hidden))]]
            : [];
        return [
            'type' => 'ExpansionPanel', 'name' => 'LayoutPanel', 'expanded' => false,
            'caption' => sprintf($this->Translate('🪟  Form: layout%s'), $hidden ? sprintf($this->Translate(' (%d hidden)'), count($hidden)) : ''),
            'items' => array_merge($hiddenLine, [
                ['type' => 'Label', 'caption' => 'Here you decide which sections are expanded when the form opens and in which order they appear. Symcon does not remember expanding and collapsing, so this setting applies every time the form opens. It takes effect after "Apply" the next time the form is opened.'],
                ['type' => 'Label', 'caption' => 'No checkbox means "default": as the module intends (here: collapsed). The position is a number; with equal numbers the default order applies. "Hidden" removes a section from the display without it losing anything: its settings are kept and keep working. To show it again, clear the "Hidden" checkbox or click "Reset to default". If several checkboxes are set, "Hidden" wins over "Collapsed", which wins over "Expanded". The sections at the top (purpose, news, documentation) and at the bottom (feedback, about this module) stay where they are and cannot be hidden.'],
                [
                    'type' => 'List', 'name' => 'FormLayout', 'caption' => 'Sections', 'rowCount' => count($rows), 'add' => false, 'delete' => false,
                    'loadValuesFromConfiguration' => false,
                    'values' => $rows,
                    'columns' => [
                        ['caption' => 'Section', 'name' => 'Panel', 'width' => 'auto', 'add' => ''],
                        ['caption' => 'Key', 'name' => 'Key', 'width' => '0px', 'visible' => false, 'add' => '', 'save' => true],
                        ['caption' => 'Expanded', 'name' => 'Opened', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Collapsed', 'name' => 'Closed', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => '🙈 Hidden', 'name' => 'Hidden', 'width' => '130px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Position', 'name' => 'Pos', 'width' => '100px', 'add' => 1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 99]]
                    ]
                ],
                ['type' => 'Button', 'caption' => '↩️ Reset to default', 'onClick' => 'echo SGWTILE_ResetFormLayout($id);']
            ])
        ];
    }

    /** Setzt Aufklapp-Zustand und Reihenfolge der Fachpanels im offenen Formular auf den Standard (gilt nach "Übernehmen"). */
    public function ResetFormLayout(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        $captions = [];
        foreach (is_array($form) ? ($form['elements'] ?? []) : [] as $element) {
            if (is_array($element) && isset(self::LAYOUT_PANELS[$element['name'] ?? ''])) {
                $captions[self::LAYOUT_PANELS[$element['name']]] = (string) ($element['caption'] ?? '');
            }
        }
        $rows = [];
        $pos = 1;
        foreach (array_keys(self::LAYOUT_PANELS_BY_KEY) as $key) {
            $rows[] = ['Key' => $key, 'Panel' => $this->Translate($captions[$key] ?? $key), 'Opened' => false, 'Closed' => false, 'Hidden' => false, 'Pos' => $pos++];
        }
        $this->UpdateFormField('FormLayout', 'values', json_encode($rows, JSON_UNESCAPED_UNICODE));
        return $this->Translate('ℹ️ Reset to default. Click "Apply" below to save.');
    }

    /**
     * Lizenz-/Unterstützungs-Hinweis (Formular-Konvention Punkt 5, SUITE.md) - Wortlaut
     * verbundweit identisch ("Variante A"), nur LICENSE_URL je Repo angepasst. Anders als
     * der Forum-Hinweis bewusst NICHT wegklickbar - eine Lizenz ist kein einmaliger Hinweis.
     */
    private function LicenseHint(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧡  About this module',
            'items' => [
                ['type' => 'Label', 'caption' => 'Born out of real enthusiasm for my own setup — and quite a few evenings of typing. Still: hobby software or not, this is intellectual property and real work went into it.'],
                ['type' => 'Label', 'caption' => 'License: PolyForm Noncommercial 1.0.0 — free for private and non-commercial use; commercial use requires a separate license from the rights holder.'],
                ['type' => 'Button', 'caption' => 'View license text', 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Commercial use or questions about the license? Just get in touch: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'You like the module and would like to leave something anyway? I am happy about a small donation — completely voluntary, nothing required in return.'],
                ['type' => 'Button', 'caption' => '☕  Donate via PayPal', 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    /**
     * Ausblenden von "Wozu dieses Modul?"/"Was ist Neu?"/Forum-Hinweis über alle
     * Geschwister-Instanzen dieses Moduls teilen (SUITE.md "Ausblenden über mehrere
     * Instanzen desselben Moduls teilen", 14.09.2026) - z. B. mehrere Kacheln für
     * verschiedene StromGedachtWidget-Quellen. Ruft bei jeder Geschwister-Instanz NUR
     * den reinen Übernahme-Schritt auf (AdoptDismissState), nicht erneut die volle
     * Ack-Methode - dadurch kein Ping-Pong möglich, ganz ohne Prozessmerker.
     */
    private function PropagateDismiss(string $what, string $value = ''): void
    {
        foreach (IPS_GetInstanceListByModuleID(self::GUID_TILE) as $sib) {
            if ($sib === $this->InstanceID) {
                continue;
            }
            try {
                SGWTILE_AdoptDismissState($sib, $what, $value);
            } catch (\Throwable $e) {
                // Eine Geschwister-Instanz mitten im Reload/Löschen darf das Ausblenden
                // der aufrufenden Instanz nicht mitreißen - @ hält Fatals nicht auf.
            }
        }
    }

    /** Reiner Übernahme-Schritt für eine Geschwister-Instanz - siehe PropagateDismiss(). */
    public function AdoptDismissState(string $what, string $value): void
    {
        switch ($what) {
            case 'PurposeIntro':
                $this->WriteAttributeBoolean('PurposeIntroGone', true);
                $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
                break;
            case 'ForumHint':
                $this->WriteAttributeBoolean('ForumHintGone', true);
                $this->UpdateFormField('ForumHintPanel', 'visible', false);
                break;
            case 'News':
                $this->WriteAttributeString('SeenNews', $value);
                $this->UpdateFormField('NewsPanel', 'visible', false);
                break;
        }
    }

    /** Für Geschwister-Instanzen, die beim erstmaligen Kontakt den Ausblenden-Stand übernehmen wollen - siehe AdoptDismissFromSibling(). */
    public function GetDismissState(): array
    {
        return [
            'purposeIntroGone' => $this->ReadAttributeBoolean('PurposeIntroGone'),
            'forumHintGone'    => $this->ReadAttributeBoolean('ForumHintGone'),
            'seenNews'         => $this->ReadAttributeString('SeenNews'),
        ];
    }

    /**
     * Gegenrichtung zu PropagateDismiss(): eine neu angelegte Instanz sieht beim ersten
     * ApplyChanges() bei einer beliebigen Geschwister-Instanz nach und übernimmt deren
     * Stand, statt die Hinweise erneut zu zeigen, obwohl der Nutzer sie an anderer Stelle
     * schon bestätigt hat. Zieht nur vor (false→true, ältere→neuere News-Version),
     * überschreibt nie einen schon weiter fortgeschrittenen eigenen Stand.
     */
    private function AdoptDismissFromSibling(): void
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone') && $this->ReadAttributeBoolean('ForumHintGone')
            && $this->ReadAttributeString('SeenNews') === self::MODULE_VERSION) {
            return;
        }
        foreach (IPS_GetInstanceListByModuleID(self::GUID_TILE) as $sib) {
            if ($sib === $this->InstanceID) {
                continue;
            }
            try {
                $state = SGWTILE_GetDismissState($sib);
            } catch (\Throwable $e) {
                continue;
            }
            if (!is_array($state)) {
                continue;
            }
            if (!$this->ReadAttributeBoolean('PurposeIntroGone') && !empty($state['purposeIntroGone'])) {
                $this->WriteAttributeBoolean('PurposeIntroGone', true);
            }
            if (!$this->ReadAttributeBoolean('ForumHintGone') && !empty($state['forumHintGone'])) {
                $this->WriteAttributeBoolean('ForumHintGone', true);
            }
            if ($this->ReadAttributeString('SeenNews') !== self::MODULE_VERSION && ($state['seenNews'] ?? '') === self::MODULE_VERSION) {
                $this->WriteAttributeString('SeenNews', self::MODULE_VERSION);
            }
            break;
        }
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

        // Automations-Verwaltung serverseitig sperren, wenn "Automationen anzeigen"
        // deaktiviert ist - ein rein clientseitig ausgeblendetes UI-Element wäre über
        // einen direkten requestAction()-Aufruf umgehbar (z. B. per Browser-Konsole).
        $ruleIdents = ['rule', 'ruleEditor', 'targetOpts', 'condOpts', 'ruleSave', 'ruleDelete'];
        if (in_array($Ident, $ruleIdents, true) && !$this->ReadPropertyBoolean('ShowAutomations')) {
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
        // Texte der Kachel: {{t:Englischer Schlüssel}} einmal je Laden in die Symcon-Sprache übersetzen
        // (nicht je Update - Stolperstein 24). Übersetzungen enthalten bewusst keine Anführungszeichen
        // oder Backslashes, da die Token auch in JavaScript-Zeichenketten stehen (Test prüft das).
        $module = preg_replace_callback('/\{\{t:(.*?)\}\}/u', function (array $m): string {
            return $this->Translate($m[1]);
        }, $module);
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
            'bg'        => $this->ColorOrEmpty((int) $this->ReadPropertyInteger('ColorBackground')),
            'box'       => $this->ColorOrEmpty((int) $this->ReadPropertyInteger('ColorBox')),
            'text'      => $this->ColorOrEmpty((int) $this->ReadPropertyInteger('ColorText')),
            'textmuted' => $this->ColorOrEmpty((int) $this->ReadPropertyInteger('ColorTextMuted')),
            'font'      => $this->FontStack((string) $this->ReadPropertyString('FontFamily')),
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
                'label' => $this->Translate(self::SG_LABELS[$state] ?? 'Unknown'),
                'text'  => isset(self::SG_TEXTS[$state]) ? $this->Translate(self::SG_TEXTS[$state]) : ''
            ];
        }

        $gsi = $this->ReadSourceValue($src, 'GSI');
        if ($gsi !== null) {
            $gsi = (float) $gsi;
            if ($gsi >= 66) {
                $level = 'green';
                $text = $this->Translate('High share of green electricity in the region');
            } elseif ($gsi >= 33) {
                $level = 'yellow';
                $text = $this->Translate('Average share of green electricity in the region');
            } else {
                $level = 'red';
                $text = $this->Translate('Low share of green electricity in the region');
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
            $text = $this->Translate(self::EC_TEXTS[$ecSignal] ?? 'Unknown signal');
            if ($share !== null) {
                $text = sprintf($this->Translate('%s (renewable share: %s %%)'), $text, $this->formatDecimal((float) $share, 1));
            }
            $columns[] = [
                'title' => 'Energy-Charts',
                'color' => $this->ColorForLevel(self::EC_LEVEL[$ecSignal] ?? null),
                'label' => $this->Translate(self::EC_LABELS[$ecSignal] ?? 'Unknown'),
                'text'  => $text
            ];
        }

        return $columns;
    }

    private function formatDecimal(float $value, int $decimals): string
    {
        // Schlüssel ist absichtlich kein Satz: Deutsch liefert "," zurück, jede andere Sprache den Schlüssel selbst
        $german = $this->Translate('decimal comma') === ',';
        return number_format($value, $decimals, $german ? ',' : '.', $german ? '.' : ',');
    }

    private function ColorForLevel(?string $level): string
    {
        switch ($level) {
            case 'supergreen': return $this->ColorHex((int) $this->ReadPropertyInteger('ColorSuperGreen'), '#00bfa5');
            case 'green':       return $this->ColorHex((int) $this->ReadPropertyInteger('ColorGreen'), '#00c853');
            case 'yellow':      return $this->ColorHex((int) $this->ReadPropertyInteger('ColorYellow'), '#ffd600');
            case 'orange':      return $this->ColorHex((int) $this->ReadPropertyInteger('ColorOrange'), '#ff6d00');
            case 'red':         return $this->ColorHex((int) $this->ReadPropertyInteger('ColorRed'), '#d50000');
            default:            return self::COLOR_NODATA;
        }
    }

    /** Setzt eine Eigenschaft (caption, visible ...) des benannten Elements, rekursiv über alle items (auch in ExpansionPanels). */
    private function setElementProperty(array &$elements, string $name, string $key, $value): bool
    {
        foreach ($elements as &$element) {
            if (!is_array($element)) {
                continue;
            }
            if (($element['name'] ?? '') === $name) {
                $element[$key] = $value;
                return true;
            }
            if (isset($element['items']) && is_array($element['items']) && $this->setElementProperty($element['items'], $name, $key, $value)) {
                return true;
            }
        }
        unset($element);
        return false;
    }

    /** true, wenn die Quelle allein automatisch erkannt wird (keine eigene Wahl, genau eine Widget-Instanz). */
    private function sourceIsAutomatic(): bool
    {
        return (int) $this->ReadPropertyInteger('SourceInstance') === 0
            && count(IPS_GetInstanceListByModuleID(self::SOURCE_MODULE)) === 1;
    }

    /**
     * Auffrischen der Statuszeile beim Ändern der Auswahl, noch vor dem Speichern (Formular-Regel
     * "Zeile folgt der Auswahl, nicht dem Speicherstand", SUITE.md).
     */
    public function OnChangeSource(int $SourceInstance): void
    {
        $line = $this->sourceStatusLine($SourceInstance);
        $this->UpdateFormField('SourceStatus', 'caption', $line);
        $this->UpdateFormField('SourceStatus', 'color', $this->sourceStatusColor($line));
    }

    /** 🔗-Zeilen (automatisch übernommen) grün (Formular-Regel SUITE.md), alles andere Standardfarbe (-1). */
    private function sourceStatusColor(string $line): int
    {
        return strpos($line, '🔗') === 0 ? 0x2E8B3D : -1;
    }

    /**
     * Statuszeile zur Datenquelle: 🔗 automatisch erkannt bzw. ✏️ eigene Wahl (Instanz, angezeigte
     * Werte samt Quelle), ⚠️ mehrere Instanzen ohne Auswahl bzw. verbunden, aber ohne Werte,
     * ℹ️ keine gefunden. $selected = noch ungespeicherte Auswahl aus dem offenen Formular.
     */
    private function sourceStatusLine(?int $selected = null): string
    {
        $configured = $selected ?? (int) $this->ReadPropertyInteger('SourceInstance');
        $list = array_map('intval', IPS_GetInstanceListByModuleID(self::SOURCE_MODULE));
        $prefix = '';

        if ($configured > 0 && IPS_InstanceExists($configured)) {
            $src = $configured;
            $how = $this->Translate('chosen manually');
            $mark = '✏️';
        } else {
            if ($configured > 0) {
                $prefix = sprintf($this->Translate('The chosen instance #%d no longer exists.'), $configured) . ' ';
            }
            if (count($list) === 1) {
                $src = $list[0];
                $how = $this->Translate('detected automatically');
                $mark = '🔗';
            } elseif (count($list) > 1) {
                $names = [];
                foreach ($list as $id) {
                    $names[] = '#' . $id . ' „' . IPS_GetName($id) . '“';
                }
                return '⚠️ ' . $prefix . sprintf($this->Translate('%d StromGedachtWidget instances found (%s), but no data source chosen - please choose "Data source" below. Until then the tile shows no data.'), count($list), implode(', ', $names));
            } else {
                return 'ℹ️ ' . $prefix . $this->Translate('No StromGedachtWidget instance found - the tile shows "No data source found" and the automations are not available until an instance is created or chosen below.');
            }
        }

        $values = [];
        foreach ($this->BuildColumns($src) as $col) {
            $values[] = $col['title'] . ': ' . $col['label'];
        }
        $head = $prefix . sprintf($this->Translate('Data source: StromGedachtWidget #%d "%s" (%s).'), $src, IPS_GetName($src), $how);
        if (count($values) === 0) {
            return '⚠️ ' . $head . ' ' . $this->Translate('Connected, but no values yet - no data source is enabled in the source or it has not been queried yet.');
        }
        return $mark . ' ' . $head . ' ' . sprintf($this->Translate('Currently shown: %s.'), implode(' · ', $values));
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
        $v = (float) $this->ReadPropertyFloat('FontScale');
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
