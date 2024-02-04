<?php

declare(strict_types=1);

namespace Zigbee2MQTT;

trait Zigbee2MQTTHelper
{
    private $configs;

    public function loadConfigurations()
    {
        $this->configs = require __DIR__ . '/Variables.php';
    }

    public function RequestAction($Ident, $Value)
    {
        $variableID = $this->GetIDForIdent($Ident);
        $this->SendDebug('RequestAction', 'Ident: ' . $Ident, 0);
        $variableType = IPS_GetVariable($variableID)['VariableType'];
        $this->SendDebug('RequestAction', 'VariableType: ' . $variableType, 0);
        $this->SendDebug('RequestAction', 'VariableID: ' . $variableID, 0);

        $payloadValue = $Value; // Values unbehandelt übertragen
        $this->SendDebug('RequestAction', "Setting property $Ident to $Value", 0);

        // Payload erstellen
        $payload = json_encode([$Ident => $payloadValue], JSON_UNESCAPED_SLASHES);
        $this->SendDebug(__FUNCTION__, "Payload: $payload", 0);

        // Payload an Z2M weiterleiten
        $this->Z2MSet($payload);
    }

    public function getDeviceInfo()
    {
        $this->symconExtensionCommand('getDevice', $this->ReadPropertyString('MQTTTopic'));
    }

    public function getGroupInfo()
    {
        $this->symconExtensionCommand('getGroup', $this->ReadPropertyString('MQTTTopic'));
    }
    public function ReceiveData($JSONString)
    {
        if (!empty($this->ReadPropertyString('MQTTTopic'))) {
            $Buffer = json_decode($JSONString, true);

            if (IPS_GetKernelDate() > 1670886000) {
                $Buffer['Payload'] = utf8_decode($Buffer['Payload']);
            }

            $this->SendDebug('MQTT Topic', $Buffer['Topic'], 0);
            $this->SendDebug('MQTT Payload', $Buffer['Payload'], 0);

            // Verfügbarkeitsprüfung
            if (array_key_exists('Topic', $Buffer) && fnmatch('*/availability', $Buffer['Topic'])) {
                $this->RegisterVariableBoolean('^status', $this->Translate('Status'), 'Z2M.DeviceStatus');
                $this->SetValue('status', $Buffer['Payload'] == 'online');
            }

            // DeviceInfo und GroupInfo Prüfung
            $this->processDeviceInfoAndGroupInfo($Buffer);

            // Verarbeitung der anderen Payload-Daten
            $this->processPayloadData($Buffer);
        }
    }

    public function setColorExt($color, string $mode, array $params = [], string $Z2MMode = 'color')
    {
        switch ($mode) {
            case 'cie':
                $this->SendDebug(__FUNCTION__, $color, 0);
                $this->SendDebug(__FUNCTION__, $mode, 0);
                $this->SendDebug(__FUNCTION__, json_encode($params, JSON_UNESCAPED_SLASHES), 0);
                $this->SendDebug(__FUNCTION__, $Z2MMode, 0);
                if (preg_match('/^#[a-f0-9]{6}$/i', strval($color))) {
                    $color = ltrim($color, '#');
                    $color = hexdec($color);
                }
                $RGB = $this->HexToRGB($color);
                $cie = $this->RGBToCIE($RGB[0], $RGB[1], $RGB[2]);
                if ($Z2MMode = 'color') {
                    $Payload['color'] = $cie;
                } elseif ($Z2MMode == 'color_rgb') {
                    $Payload['color_rgb'] = $cie;
                } else {
                    return;
                }

                foreach ($params as $key => $value) {
                    $Payload[$key] = $value;
                }

                $PayloadJSON = json_encode($Payload, JSON_UNESCAPED_SLASHES);
                $this->SendDebug(__FUNCTION__, $PayloadJSON, 0);
                $this->Z2MSet($PayloadJSON);
                break;
            default:
                $this->SendDebug('setColor', 'Invalid Mode ' . $mode, 0);
                break;
        }
    }
    public function Z2MSet(mixed $payload)
    {
        $Data['DataID'] = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';
        $Data['PacketType'] = 3;
        $Data['QualityOfService'] = 0;
        $Data['Retain'] = false;
        $Data['Topic'] = $this->ReadPropertyString('MQTTBaseTopic') . '/' . $this->ReadPropertyString('MQTTTopic') . '/set';
        $Data['Payload'] = $payload;
        $DataJSON = json_encode($Data, JSON_UNESCAPED_SLASHES);
        $this->SendDebug(__FUNCTION__ . ' Topic', $Data['Topic'], 0);
        $this->SendDebug(__FUNCTION__ . ' Payload', $Data['Payload'], 0);
        $this->SendDataToParent($DataJSON);
    }

    protected function SetValue($Ident, $Value)
    {
        if (@$this->GetIDForIdent($Ident)) {
            $this->SendDebug('Info :: SetValue for ' . $Ident, 'Value: ' . $Value, 0);
            parent::SetValue($Ident, $Value);
        } else {
            $this->SendDebug('Error :: No Expose for Value', 'Ident: ' . $Ident, 0);
        }
    }
    private function processDeviceInfoAndGroupInfo($Buffer)
    {
        $Payload = json_decode($Buffer['Payload'], true);
        if (fnmatch('symcon/' . $this->ReadPropertyString('MQTTBaseTopic') . '/' . $this->ReadPropertyString('MQTTTopic') . '/deviceInfo', $Buffer['Topic'])) {
            if (isset($Payload['_definition']) && is_array($Payload['_definition']) && isset($Payload['_definition']['exposes']) && is_array($Payload['_definition']['exposes'])) {
                $this->mapExposesToVariables($Payload['_definition']['exposes']);
            }
        } elseif (fnmatch('symcon/' . $this->ReadPropertyString('MQTTBaseTopic') . '/' . $this->ReadPropertyString('MQTTTopic') . '/groupInfo', $Buffer['Topic'])) {
            if (is_array($Payload)) {
                $this->mapExposesToVariables($Payload);
            }
        }
    }

    private function processPayloadData($Buffer)
    {
        $this->loadConfigurations();

        $Payload = json_decode($Buffer['Payload'], true);

        if (!is_array($Payload)) {
            $this->SendDebug(__FUNCTION__, 'Payload ist kein gültiges Array', 0);
            return;
        }

        // Verarbeiten der 'exposes' im Payload
        if (isset($Payload['exposes']) && is_array($Payload['exposes'])) {
            foreach ($Payload['exposes'] as $expose) {
                switch ($expose['type']) {
                    case 'numeric':
                        $this->handleNumericExpose($expose);
                        break;
                    case 'binary':
                        $this->handleBinaryExpose($expose);
                        break;
                    case 'enum':
                        $this->handleEnumExpose($expose);
                        break;
                    case 'text':
                        $this->handleTextExpose($expose);
                        break;
                    // Weitere Typen können hier hinzugefügt werden
                }
            }

            $missingExposes = $this->mapExposesToVariables($Payload['exposes']);
            if (!empty($missingExposes)) {
                $this->SendDebug(__FUNCTION__, "Fehlende 'exposes' im Payload: " . implode(', ', $missingExposes), 0);
            }
        } else {
            $this->SendDebug(__FUNCTION__, "'exposes' nicht im Payload gefunden oder ungültiges Format", 0);
        }

        // Verarbeiten der 'options' im Payload
        if (isset($Payload['options']) && is_array($Payload['options'])) {
            $missingOptions = $this->mapOptionsToVariables($Payload['options']);
            if (!empty($missingOptions)) {
                $this->SendDebug(__FUNCTION__, "Fehlende 'options' im Payload: " . implode(', ', $missingOptions), 0);
            }
        } else {
            $this->SendDebug(__FUNCTION__, "'options' nicht im Payload gefunden oder ungültiges Format", 0);
        }

        // Weitere Payload-Verarbeitung
        // ...
    }

    // Implementieren Sie die spezifischen Funktionen für jeden Expose-Typ

    private function handleBinaryExpose($expose)
    { /* ... */
    }
    private function handleEnumExpose($expose)
    { /* ... */
    }
    private function handleTextExpose($expose)
    { /* ... */
    }

    // Ihre Map-Funktionen

    private function mapOptionsToVariables($options)
    { /* ... */
    }

    // Formatierungsfunktion Exposes->Symcon_Variable
    private function formatVariableName($key)
    {
        $formatted = str_replace(['_', ' '], '', ucwords($key, '_ '));
        return 'Z2M_' . $formatted;
    }
    // Spezifische Funktionen
    private function handleVoltage($key, $Payload)
    {
        if (!array_key_exists($key, $Payload)) {
            $this->SendDebug(__FUNCTION__, "Key {$key} not found in Payload", 0);
            return;
        }
        $value = $Payload[$key];
        // Prüfen, ob der Spannungswert größer als 400 ist
        if ($value > 400) {
            // Wert durch 1000 teilen, falls über 400
            $this->SetValue($key, $value / 1000);
        } else {
            // Wert direkt setzen, falls 400 oder weniger
            $this->SetValue($key, $value);
        }
    }

    private function handleOnOff($key, $Payload)
    {
        if (!array_key_exists($key, $Payload)) {
            $this->SendDebug(__FUNCTION__, "Key {$key} not found in Payload", 0);
            return;
        }

        $value = $Payload[$key];
        $variableName = $this->formatVariableName($key);

        switch ($value) {
            case 'ON':
                $this->SetValue($key, true);
                break;
            case 'OFF':
                $this->SetValue($key, false);
                break;
            default:
                $this->SendDebug($key, 'Undefined State: ' . $value, 0);
                break;
        }
    }
    private function handleNumericExpose($expose)
    {
        if (!isset($expose['property']) || !isset($expose['value'])) {
            $this->SendDebug(__FUNCTION__, 'Erforderliche Attribute im Expose fehlen', 0);
            return;
        }

        $key = $expose['property'];
        $value = $expose['value'];

        // Spezielle Behandlung für 'update_available'
        if ($key === 'update_available' && @$this->GetIDForIdent('update') == 0) {
            $this->RegisterVariableBoolean('update', $this->Translate('Update'), '');
        }
        // Spezielle Behandlung für 'last_seen'
        elseif ($key === 'last_seen' && @$this->GetIDForIdent('last_seen') == 0) {
            $this->RegisterVariableInteger($key, $this->Translate('Last Seen'), '~UnixTimestamp');
        }

        $this->SetValue($key, $value);
    }

    private function handleBooleanValue($key, $Payload)
    {
        if (!array_key_exists($key, $Payload)) {
            $this->SendDebug(__FUNCTION__, "Key {$key} not found in Payload", 0);
            return;
        }

        $value = $Payload[$key];
        $variableName = $this->formatVariableName($key);
        $state = $this->OnOff($value);
        // Setzen des zurückgegebenen Zustands ('ON'/'OFF') in die Variable
        $this->SetValue($key, $state);
    }

    private function handleColorRGBValue($key, $Payload)
    {
        $this->SendDebug('handleColorRGBValue Key', $key, 0);
        $this->SendDebug('handleColorRGBValue Payload', print_r($Payload, true), 0);

        if ($key === 'color' && array_key_exists('color', $Payload)) {
            $x = $Payload['color']['x'];
            $y = $Payload['color']['y'];
            $brightnessKey = 'brightness';
            $valueKey = 'Z2M_Color';
        } elseif ($key === 'color_rgb' && array_key_exists('color_rgb', $Payload)) {
            $x = $Payload['color_rgb']['x'];
            $y = $Payload['color_rgb']['y'];
            $brightnessKey = 'brightness_rgb';
            $valueKey = 'Z2M_ColorRGB';
        } else {
            $this->SendDebug(__FUNCTION__, "Key {$key} not found in Payload", 0);
            return; // Kein passender Schlüssel gefunden
        }

        $this->SendDebug(__FUNCTION__ . ' Color X', $x, 0);
        $this->SendDebug(__FUNCTION__ . ' Color Y', $y, 0);

        $brightnessValue = array_key_exists($brightnessKey, $Payload) ? $Payload[$brightnessKey] : null;
        if ($brightnessValue !== null) {
            $RGBColor = ltrim($this->CIEToRGB($x, $y, $brightnessValue), '#');
        } else {
            $RGBColor = ltrim($this->CIEToRGB($x, $y), '#');
        }

        $this->SendDebug(__FUNCTION__ . ' Color RGB HEX', $RGBColor, 0);
        $this->SetValue($valueKey, hexdec($RGBColor));
    }

    private function handleColorTemperature($key, $Payload)
    {
        if (!array_key_exists($key, $Payload)) {
            $this->SendDebug(__FUNCTION__, "Key {$key} not found in Payload", 0);
            return;
        }

        $value = $Payload[$key];
        $variableName = $this->formatVariableName($key);

        $this->SetValue($key, $value);
        $this->SendDebug('VariableName', $variableName, 0);

        // Berechnung der Farbtemperatur in Kelvin, falls der Wert größer als 0 ist
        if ($value > 0) {
            $kelvin = 1000000 / $value; // Umrechnung in Kelvin
            $kelvinVariableName = ($key . '_kelvin');
            $this->SetValue($key, $kelvin);
        }
    }

    private function handleLockUnlock($key, $Payload)
    {
        if (!array_key_exists($key, $Payload)) {
            $this->SendDebug(__FUNCTION__, "Key {$key} not found in Payload", 0);
            return;
        }

        $value = $Payload[$key];
        $this->SendDebug(__FUNCTION__ . ' Value', $value, 0);
        $variableName = $this->formatVariableName($key);
        $this->SendDebug(__FUNCTION__ . ' VariableName', $variableName, 0);

        switch ($value) {
            case 'LOCK':
                $this->SetValue($key, true);
                break;
            case 'UNLOCK':
                $this->SetValue($key, false);
                break;
            default:
                $this->SendDebug($key, 'Undefined State: ' . $value, 0);
                break;
        }
    }

    private function handleState($key, $Payload)
    {
        if (!array_key_exists($key, $Payload)) {
            $this->SendDebug(__FUNCTION__, "Key {$key} not found in Payload", 0);
            return;
        }

        $value = $Payload[$key];
        $variableName = $this->formatVariableName($key);

        switch ($value) {
            case 'ON':
                $this->SetValue($key, true);
                break;
            case 'OFF':
                $this->SetValue($key, false);
                break;
            case 'STOP':
            case 'AUTO':
                $this->SetValue($key, true);
                break;
            case 'MANUAL':
                $this->SetValue($key, false);
                break;
            case 'LOCK':
                $this->SetValue($key, true);
                break;
            case 'UNLOCK':
                if (is_string($key)) {
                    $this->SetValue($key, false);
                } else {
                    $this->SendDebug('Fehler', 'Variable Name ist kein String', 0);
                }
                break;
            default:
                // Direktes Setzen des Zustands, da es sich nicht um eine einfache ON/OFF-Logik handelt
                $this->SetValue($key, $value);
                break;
        }
    }

    private function OnOff(bool $Value)
    {
        switch ($Value) {
            case true:
                $state = 'ON';
                break;
            case false:
                $state = 'OFF';
                break;
        }
        return $state;
    }
    private function ValveState(bool $Value)
    {
        switch ($Value) {
            case true:
                $state = 'OPEN';
                break;
            case false:
                $state = 'CLOSED';
                break;
        }
        return $state;
    }
    private function LockUnlock(bool $Value)
    {
        if ($Value === true) {
            return 'LOCK';
        } else {
            return 'UNLOCK';
        }
    }

    private function OpenClose(bool $Value)
    {
        switch ($Value) {
            case true:
                $state = 'OPEN';
                break;
            case false:
                $state = 'CLOSE';
                break;
        }
        return $state;
    }
    private function AutoManual(bool $Value)
    {
        switch ($Value) {
            case true:
                $state = 'AUTO';
                break;
            case false:
                $state = 'MANUAL';
                break;
        }
        return $state;
    }
    private function setColor($color, $mode, string $Z2MMode = 'color')
    {
        // Überprüfen, ob $mode ein String ist
        if (!is_string($mode)) {
            $this->SendDebug('setColor', 'Mode must be a string, got: ' . gettype($mode), 0);
            return;
        }

        // Stellen Sie sicher, dass $color ein Integer ist, wenn der Modus 'cie' ist
        if ($mode === 'cie' && !is_int($color)) {
            $this->SendDebug('setColor', 'Expected integer for color in CIE mode, got: ' . gettype($color), 0);
            return;
        }

        switch ($mode) {
            case 'cie':
                $RGB = $this->HexToRGB($color);
                $cie = $this->RGBToCIE($RGB[0], $RGB[1], $RGB[2]);

                if ($Z2MMode === 'color') {
                    $Payload['color'] = $cie;
                } elseif ($Z2MMode === 'color_rgb') {
                    $Payload['color_rgb'] = $cie;
                } else {
                    $this->SendDebug('setColor', 'Invalid Z2MMode: ' . $Z2MMode, 0);
                    return;
                }

                $PayloadJSON = json_encode($Payload, JSON_UNESCAPED_SLASHES);
                $this->Z2MSet($PayloadJSON);
                break;

            default:
                $this->SendDebug('setColor', 'Invalid Mode: ' . $mode, 0);
                break;
        }
    }

    private function mapExposesToVariables(array $exposes)
    {
        foreach ($exposes as $item) {
            $this->SendDebug('Processing Expose/Feature', json_encode($item), 0);
            if (isset($item['features'])) {
                // Behandlung von 'expose' mit 'features'
                foreach ($item['features'] as $feature) {
                    $this->processItem($feature, $feature['type']);
                }
            } else {
                // Behandlung von 'feature'
                $this->processItem($item, $item['type']);
            }
        }
    }

    private function processItem($item, $type, $parentExpose = null)
    {
        if ($type === 'numeric') {
            $this->SendDebug('Processing Item', "Type: $type, Property: " . json_encode($item), 0);
            $this->processNumericTypeExpose($item, $parentExpose);
        } elseif ($type === 'binary') {
            $this->SendDebug('Processing Item', "Type: $type, Property: " . json_encode($item), 0);
            $this->processBooleanTypeFeature($item, $parentExpose);
        } elseif ($type === 'boolean') {
            $this->SendDebug('Processing Item', "Type: $type, Property: " . json_encode($item), 0);
            $this->processBooleanTypeFeature($item, $parentExpose);
        } elseif ($type === 'enum') {
            $this->SendDebug('Processing Item', "Type: $type, Property: " . json_encode($item), 0);
            $this->processStringTypeExpose($item, $parentExpose);
        }
    }

    private function processStringTypeExpose($feature, $parentExpose = null)
    {
        // Versuche, 'propertyName' aus dem aktuellen 'item' zu bekommen, sonst aus dem übergeordneten 'parentExpose'
        $propertyName = $feature['property'] ?? $parentExpose['property'] ?? null;
        $label = $feature['property'] ?? $parentExpose['property'] ?? $propertyName;
        $unit = $feature['unit'] ?? null;
        $value_min = $feature['value_min'] ?? null;
        $value_max = $feature['value_max'] ?? null;
        $ident = $propertyName;
        $formattedLabel = ucwords(str_replace('_', ' ', $propertyName));
        $this->SendDebug(__FUNCTION__ . ':: formattedLabel', $formattedLabel, 0);
        $uniqueProfileName = $this->generateProfileName($feature);
        $this->SendDebug(__FUNCTION__, 'Start processing enum/string type expose', 0);
        // Überprüfen, ob propertyName gesetzt ist
        if (!$propertyName) {
            // Fehlerbehandlung, falls 'propertyName' nicht gesetzt ist
            return; // Frühes Beenden, falls 'property' nicht gesetzt ist
        }
        $ProfileName = $this->generateProfileName($feature);
        // Registriere die Variable als String
        $this->RegisterVariableString($ident, $this->Translate($formattedLabel), '');
        // $this->RegisterVariableProfile($ProfileName);
        IPS_SetVariableCustomProfile($this->GetIDForIdent($ident), $uniqueProfileName);
        // Überprüfen, ob 'EnableAction' aktiviert werden soll
        if (isset($feature['access']) && in_array($feature['access'], [2, 3, 6, 7])) {
            $this->EnableAction($ident);
        }
        $this->SendDebug(__FUNCTION__, 'End of processing enum/string type expose', 0);
    }

    private function processBooleanTypeFeature($feature, $parentExpose = null)
    {
        // Versuche, 'propertyName' aus dem aktuellen 'feature' zu bekommen, sonst aus dem übergeordneten 'parentExpose'
        $propertyName = $feature['property'] ?? $parentExpose['property'] ?? null;
        // Versuche, das Label aus dem Feature zu bekommen, sonst aus dem übergeordneten Expose
        $label = $feature['label'] ?? $parentExpose['label'] ?? $propertyName;
        $ident = $propertyName;
        $formattedLabel = ucwords(str_replace('_', ' ', $propertyName));
        // $this->updateLocaleFile($formattedLabel);

        // Überprüfen, ob propertyName gesetzt ist
        if (!$propertyName) {
            $this->SendDebug(__FUNCTION__, 'Property not set in feature or parentExpose', 0);
            return; // Frühes Beenden, falls 'property' nicht gesetzt ist
        }

        // Erhalte den Profilnamen durch Aufruf von generateProfileName
        $profileName = $this->generateProfileName($feature);
        // Registriere die Variable als Boolean
        $this->RegisterVariableBoolean($ident, $this->Translate($formattedLabel), $profileName);

        // Überprüfen, ob 'EnableAction' aktiviert werden soll
        if (isset($feature['access']) && in_array($feature['access'], [2, 3, 6, 7])) {
            $this->EnableAction($ident);
        }
    }

    private function processNumericTypeExpose($feature, $parentExpose)
    {
        // Ermittle die relevanten Werte
        $propertyName = $feature['property'] ?? $parentExpose['property'] ?? null;
        $label = $feature['property'] ?? $parentExpose['property'] ?? $propertyName;
        $unit = $feature['unit'] ?? null;
        $value_min = $feature['value_min'] ?? null;
        $value_max = $feature['value_max'] ?? null;
        $variableName = $propertyName;
        $formattedLabel = ucwords(str_replace('_', ' ', $propertyName));

        $this->SendDebug(__FUNCTION__, 'Start processing numeric type expose', 0);

        // Überprüfe, ob 'property' gesetzt ist
        if (!isset($feature['property'])) {
            $this->SendDebug(__FUNCTION__, 'Property not set in expose', 0);
            return; // Frühes Beenden, falls 'property' nicht gesetzt ist
        }

        // Bestimme das Profil für die Variable basierend auf den Feature-Daten
        $ProfileName = $this->generateProfileName($feature);

        if (!$ProfileName) {
            $this->SendDebug(__FUNCTION__, "Unsupported feature type: {$feature['type']}", 0);
            return; // Frühes Beenden, falls der Feature-Typ nicht unterstützt wird
        }

        $this->SendDebug(__FUNCTION__ . ':: VariableName', $variableName, 0);
        $this->SendDebug(__FUNCTION__ . ':: FormattedLabel', $formattedLabel, 0);
        $this->SendDebug(__FUNCTION__ . ':: PropertyName', $propertyName, 0);
        $this->SendDebug(__FUNCTION__ . ':: Label', $label, 0);

        // Registriere die Variable als Float mit dem generierten Profil
        $this->RegisterVariableFloat($variableName, $this->Translate($formattedLabel), $ProfileName);
        $this->SetValue($variableName, 0); // Setze den Anfangswert auf 0

        // Überprüfe, ob 'EnableAction' aktiviert werden soll
        if (isset($feature['access']) && in_array($feature['access'], [2, 3, 6, 7])) {
            $this->EnableAction($variableName);
        }

        $this->SendDebug(__FUNCTION__, 'End of processing numeric type expose', 0);
    }

    private function updateLocaleFile($formattedLabel, $translation = null)
    {
        if ($translation === null) {
            $translation = $formattedLabel;
        }
        $localeFile = 'Device/locale.php';

        // Lade die vorhandenen Übersetzungen
        $translations = file_exists($localeFile) ? include($localeFile) : [];

        // Überprüfe, ob das Label bereits existiert
        if (!array_key_exists($formattedLabel, $translations)) {
            // Füge das neue Label hinzu
            $translations[$formattedLabel] = $translation;

            // Bereite den neuen Inhalt der Datei vor
            $content = "<?php\nreturn [\n";
            foreach ($translations as $key => $value) {
                $content .= "    '" . addslashes($key) . "' => '" . addslashes($value) . "',\n";
            }
            $content .= "];\n";

            // Schreibe die aktualisierten Übersetzungen zurück in die Datei
            file_put_contents($localeFile, $content);
        }
    }

    private function generateProfileName($feature)
    {
        $this->SendDebug(__FUNCTION__ . ':: feature', json_encode($feature), 0);
        $type = $feature['type'] ?? '';
        $min = $feature['value_min'] ?? null;
        $max = $feature['value_max'] ?? null;
        $name = $feature['name'] ?? '';
        $stepSize = $feature['value_step'] ?? 1;
        $suffix = $feature['unit'] ?? '';

        // Nur wenn der Typ 'numeric' ist, erstellen des Profilnamen und die Assoziationen
        if ($type === 'numeric') {
            // Generischer Profilname basierend auf dem Typ
            $genericProfileName = 'Z2M.' . $name;

            // Fügt Min/Max-Werte (falls vorhanden) zum Profilnamen hinzu
            if ($min !== null && $max !== null) {
                $genericProfileName .= $min . '_' . $max;
            }

            // Überprüfen, ob das Profil bereits existiert, bevor es erstell wird
            if (!IPS_VariableProfileExists($genericProfileName)) {
                IPS_CreateVariableProfile($genericProfileName, 2);
                $this->RegisterProfileFloat($genericProfileName, '', '', ' ' . $suffix, $min, $max, $stepSize, 2);
            }

            return $genericProfileName;
        }

        // Wenn der Typ 'enum' ist, erstellen eines eindeutigen Profilnamen und zuordnen der Assoziationen
        if ($type === 'enum') {
            $this->SendDebug(__FUNCTION__ . ':: feature', json_encode($feature), 0);
            $values = $feature['values'];
            sort($values);
            $tmpProfileName = implode('', $values);
            $this->SendDebug(__FUNCTION__ . ':: tmpProfileName', $tmpProfileName, 0);
            $uniqueProfileName = 'Z2M.' . $name . '_' . dechex(crc32($tmpProfileName));
            $this->SendDebug(__FUNCTION__ . ':: uniqueProfileName', $uniqueProfileName, 0);

            // Überprüfen, ob das Profil bereits existiert
            if (!IPS_VariableProfileExists($uniqueProfileName)) {
                IPS_CreateVariableProfile($uniqueProfileName, 3);
            }

            // Werte im Profil erstellen und übersetzen
            foreach ($values as $value) {
                $formattedValue = ucwords(str_replace('_', ' ', $value));
                $translatedValue = $this->Translate($formattedValue);
                IPS_SetVariableProfileAssociation($uniqueProfileName, $value, $translatedValue, '', 0);
            }

            return $uniqueProfileName;
        }

        // Behandlung von 'binary' Typ (Boolean)
        if ($type === 'binary') {
            $valueOn = isset($feature['value_on']) ? (is_bool($feature['value_on']) ? ($feature['value_on'] ? 'true' : 'false') : $feature['value_on']) : null;
            $valueOff = isset($feature['value_off']) ? (is_bool($feature['value_off']) ? ($feature['value_off'] ? 'true' : 'false') : $feature['value_off']) : null;

            // Überprüfen, ob valueOn und valueOff spezielle Werte "ON" und "OFF" sind
            if (strtolower($valueOn) === 'on' && strtolower($valueOff) === 'off') {
                $profileName = 'Z2M.' . $name;
            } else {
                $property = $feature['property'] ?? $name;
                $profileName = 'Z2M.' . $property;
            }

            // Prüfen, ob benutzerdefinierte Werte gesetzt sind und nicht den Standardwerten 'true' und 'false' entsprechen
            if ($valueOn !== null && $valueOff !== null &&
                strtolower($valueOn) !== 'true' && strtolower($valueOff) !== 'false') {

                // Benutzerdefinierte Werte für das Profil
                if (!IPS_VariableProfileExists($profileName)) {
                    IPS_CreateVariableProfile($profileName, 0); // 0 steht für Boolean
                    // Formatieren der Werte für das Profil und anschließende Übersetzung
                    $formattedValueOn = $this->Translate($this->formatProfileValue($valueOn));
                    $formattedValueOff = $this->Translate($this->formatProfileValue($valueOff));
                    IPS_SetVariableProfileAssociation($profileName, true, $formattedValueOn, '', -1);
                    IPS_SetVariableProfileAssociation($profileName, false, $formattedValueOff, '', -1);
                }
            } else {
                // Verwenden des Standardprofils ~Switch
                $profileName = '~Switch';
            }

            return $profileName;
        }
        return ''; // Wenn der Typ nicht 'numeric' oder 'enum' ist, wird ein leerer Profilname zurückgegeben
    }
    private function formatProfileValue($value)
    {
        return ucwords(strtolower($value));
    }
}