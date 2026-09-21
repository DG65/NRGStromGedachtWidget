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
    private const LICENSE_URL = 'https://github.com/DG65/NRGStromGedachtWidget/blob/ems-integration/LICENSE';
    private const PAYPAL_URL = 'https://paypal.me/DietmarGureth';

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
    private const MODULE_VERSION = '1.8.2';
    private const NEWS_ITEMS = [
        'Wird die Datenquelle automatisch erkannt (genau eine StromGedachtWidget-Instanz, keine eigene Wahl), ist das Auswahlfeld "Datenquelle" jetzt ausgeblendet und nur die Zeile "🔗 Datenquelle: ..." steht da. Wählst du selbst eine Instanz, bleibt das Feld sichtbar. Die Zeile folgt außerdem sofort deiner Auswahl, noch vor dem Übernehmen.',
        'Das Formular zeigt jetzt, welche Datenquelle erkannt wurde: Instanz, Name, wie sie ermittelt wurde (automatisch/manuell) und welche Werte aktuell angezeigt werden. Bei mehreren Instanzen ohne Auswahl bzw. ohne gefundene Instanz gibt es einen klaren Hinweis statt eines statischen Satzes.',
        'Neues Panel "🧡 Über dieses Modul" (Lizenz/Spenden) ganz unten im Formular sowie ein neuer, dismissibler Forum-Hinweis "💬 Feedback im Symcon-Forum" (vorher gab es hier gar keinen).',
        'Hast du mehrere Kacheln (z. B. für verschiedene StromGedachtWidget-Quellen)? "Wozu dieses Modul?"/"Was ist Neu?"/der Forum-Hinweis müssen jetzt nicht mehr an jeder Kachel einzeln weggeklickt werden — ein Klick an einer genügt für alle.',
        '👋 Neues Panel "Wozu dieses Modul?" ganz oben im Formular — kurze Erklärung für den Einstieg, einmalig ausblendbar.',
        '🔧 Interne Robustheit: eingelesene Formularwerte (Farben, Schriftart, Skalierung) werden vor der Weiterverarbeitung konsequent typgeprüft (verhindert seltene Abstürze während eines Modul-Neuladens).',
        'Sicherheitsfix: Die Automationen-Verwaltung (Regel anlegen/bearbeiten/löschen, Zielvariablen-Liste) wird jetzt auch serverseitig gesperrt, wenn "Automationen anzeigen" deaktiviert ist — vorher war das nur clientseitig ausgeblendet.',
        'Neuer Button "🔄 Übernehmen erzwingen" ruft IPS_ApplyChanges() direkt auf, ohne dass du vorher etwas im Formular ändern musst.',
        'Lizenzwechsel: PolyForm Noncommercial 1.0.0 statt MIT — private/nicht-kommerzielle Nutzung bleibt frei, gewerbliche Nutzung ist ab jetzt lizenzpflichtig.',
        'Teil des NRG-Stack (DG65-Modulverbund) — siehe SUITE.md, welche Modulstände zusammenpassen.'
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

        // Vor der Quellenauflösung - das Ausblenden-Teilen (mehrere Kachel-Instanzen)
        // gilt unabhängig davon, ob diese Instanz schon eine Quelle gefunden hat.
        $this->AdoptDismissFromSibling();

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

        // Live berechnete Statuszeile zur automatisch erkannten/gewählten Datenquelle
        // (Verbund-Regel "Verbund-Verbindungen im Formular sichtbar machen", SUITE.md) -
        // ein statischer Satz "wird automatisch erkannt" sagt nicht, ob es geklappt hat.
        $this->setElementProperty($form['elements'], 'SourceStatus', 'caption', $this->sourceStatusLine());
        // Wert kommt automatisch: Eingabefeld ausblenden statt nur erklären (SUITE.md "Wert kommt
        // automatisch: Eingabefeld ersetzen"). Nur bei genau einer Instanz und ohne eigene Wahl -
        // sonst (eigene Wahl, mehrere oder keine Instanz) bleibt das Auswahlfeld sichtbar. Nie den
        // automatischen Wert ins Feld schreiben (würde beim Übernehmen als eigene Angabe gespeichert).
        if ($this->sourceIsAutomatic()) {
            $this->setElementProperty($form['elements'], 'SourceInstance', 'visible', false);
        }

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
            'caption' => '👋  Wozu dieses Modul?',
            'items' => [
                ['type' => 'Label', 'caption' => 'Diese Kachel zeigt die Ampel-/Signalwerte einer StromGedachtWidget-Instanz direkt im WebFront an — praktisch, wenn du unterwegs oder auf einem Tablet im Blick behalten willst, wann Strom im Netz gerade besonders grün oder besonders knapp ist.'],
                ['type' => 'Label', 'caption' => 'Zusätzlich kannst du hier, ohne die Verwaltungskonsole zu öffnen, eigene Wenn→Dann-Regeln anlegen, bearbeiten und aktivieren (z. B. Warmwasser bei Supergrün einschalten) — dieselben Regeln, die auch im klassischen Instanzformular des Widgets verfügbar sind.'],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'SGWTILE_AckPurposeIntro($id);'],
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
            'caption' => '💬  Feedback im Symcon-Forum',
            'items' => [
                ['type' => 'Label', 'caption' => '⭐ Gefällt dir diese Kachel? Über eine Bewertung im Module Store oder eine Rückmeldung im Community-Thread freue ich mich!'],
                ['type' => 'Button', 'caption' => 'Zum Forums-Thread', 'onClick' => "echo '" . self::FORUM_THREAD_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'SGWTILE_AckForumHint($id);'],
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
     * Lizenz-/Unterstützungs-Hinweis (Formular-Konvention Punkt 5, SUITE.md) - Wortlaut
     * verbundweit identisch ("Variante A"), nur LICENSE_URL je Repo angepasst. Anders als
     * der Forum-Hinweis bewusst NICHT wegklickbar - eine Lizenz ist kein einmaliger Hinweis.
     */
    private function LicenseHint(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧡  Über dieses Modul',
            'items' => [
                ['type' => 'Label', 'caption' => 'Entstanden aus echter Begeisterung für die eigene Anlage — und ein paar durchgetippten Abenden. Trotzdem: Software-Hobby hin oder her, das hier ist geistiges Eigentum und echte Arbeit steckt drin.'],
                ['type' => 'Label', 'caption' => 'Lizenz: PolyForm Noncommercial 1.0.0 — privat und nicht-kommerziell frei nutzbar, für den gewerblichen Einsatz braucht es eine gesonderte Lizenz vom Rechteinhaber.'],
                ['type' => 'Button', 'caption' => 'Lizenztext ansehen', 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Gewerbliche Nutzung oder Fragen zur Lizenz? Einfach melden: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Gefällt dir das Modul und du möchtest trotzdem etwas dalassen? Über eine kleine Spende freue ich mich — völlig freiwillig, keine Gegenleistung nötig.'],
                ['type' => 'Button', 'caption' => '☕  Spenden via PayPal', 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
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
        $this->UpdateFormField('SourceStatus', 'caption', $this->sourceStatusLine($SourceInstance));
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
            $how = 'manuell gewählt';
            $mark = '✏️';
        } else {
            if ($configured > 0) {
                $prefix = 'Die gewählte Instanz #' . $configured . ' existiert nicht mehr. ';
            }
            if (count($list) === 1) {
                $src = $list[0];
                $how = 'automatisch erkannt';
                $mark = '🔗';
            } elseif (count($list) > 1) {
                $names = [];
                foreach ($list as $id) {
                    $names[] = '#' . $id . ' „' . IPS_GetName($id) . '“';
                }
                return '⚠️ ' . $prefix . count($list) . ' StromGedachtWidget-Instanzen gefunden (' . implode(', ', $names)
                    . '), aber keine Datenquelle gewählt - bitte unten „Datenquelle“ auswählen. Bis dahin zeigt die Kachel keine Daten.';
            } else {
                return 'ℹ️ ' . $prefix . 'Keine StromGedachtWidget-Instanz gefunden - die Kachel zeigt „Keine Datenquelle gefunden“ und die '
                    . 'Automationen sind nicht verfügbar, bis eine Instanz angelegt oder unten gewählt ist.';
            }
        }

        $values = [];
        foreach ($this->BuildColumns($src) as $col) {
            $values[] = $col['title'] . ': ' . $col['label'];
        }
        $head = $prefix . 'Datenquelle: StromGedachtWidget #' . $src . ' „' . IPS_GetName($src) . '“ (' . $how . ').';
        if (count($values) === 0) {
            return '⚠️ ' . $head . ' Verbunden, liefert aber noch keine Werte - in der Quelle ist keine Datenquelle aktiviert oder es wurde noch nicht abgerufen.';
        }
        return $mark . ' ' . $head . ' Aktuell angezeigt: ' . implode(' · ', $values) . '.';
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
