<?php

declare(strict_types=1);
require_once __DIR__ . '/../libs/MQTTHelper.php';

class Z2MConfigurator extends IPSModule
{
    use \Z2M_Symcon\libs\MQTTHelper;

    public function Create()
    {
        //Never delete this line!
        parent::Create();
        $this->ConnectParent('{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}');
        $this->RegisterPropertyString('MQTTBaseTopic', 'zigbee2mqtt');
        $this->RegisterPropertyBoolean('UseCategories', true);

        $this->SetBuffer('Devices', '{}');
        $this->SetBuffer('Groups', '{}');
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        //Setze Filter für ReceiveData
        $topic = 'symcon/' . $this->ReadPropertyString('MQTTBaseTopic');
        $this->SetReceiveDataFilter('.*' . $topic . '.*');
        if (($this->HasActiveParent()) && (IPS_GetKernelRunlevel() == KR_READY)) {
            $this->getDevices();
            $this->getGroups();
        }

        $this->SetStatus(102);
    }

    public function GetConfigurationForm()
    {
        if (($this->HasActiveParent()) && (IPS_GetKernelRunlevel() == KR_READY)) {
            $this->getDevices();
            $this->getGroups();
        }
        $Form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $useCategories = $this->ReadPropertyBoolean('UseCategories');

            // Durchsuchen des Formular-Arrays und Anpassen der Spaltenkonfiguration
        foreach ($Form['actions'] as &$action) {
            if (isset($action['type']) && $action['type'] == 'ExpansionPanel' && isset($action['items'])) {
                foreach ($action['items'] as &$item) {
                    if (isset($item['type']) && $item['type'] == 'Configurator' && isset($item['columns'])) {
                        foreach ($item['columns'] as $key => &$column) {
                            if ($column['name'] == 'categories') {
                                $column['visible'] = $useCategories;
                            }
                        }
                    }
                }
            }
        }
        unset($action, $item, $column); // Entfernen der Referenzen

        //Devices
        $Devices = json_decode($this->GetBuffer('Devices'), true);
        $this->SendDebug('Buffer Devices', json_encode($Devices), 0);
        $ValuesDevices = [];
        $useCategories = $this->ReadPropertyBoolean('UseCategories');
        foreach ($Devices as $device) {
            $Value = [];
            $friendlyNameParts = explode('/', $device['friendly_name']);
            $instanceName = end($friendlyNameParts);

            // Extrahiere alle Teile des friendly_name als Kategorien
            $categories = array_slice($friendlyNameParts, 0, -1);

            $instanceID = $this->getDeviceInstanceID($device['friendly_name']);

            if ($useCategories) {
                // Verwende $instanceName, wenn Kategorien aktiv sind
                $Value['name'] = $instanceName;
                $Value['create'] = [
                    'moduleID'      => '{E5BB36C6-A70B-EB23-3716-9151A09AC8A2}',
                    'configuration' => [
                        'MQTTTopic'    => $device['friendly_name'],
                    ],
                    'location' => $categories // Kategorien hinzufügen
                ];
            } else {
                // Verwende $device['friendly_name'], wenn Kategorien nicht aktiv sind
                $Value['name'] = $device['friendly_name'];
                $Value['create'] = [
                    'moduleID'      => '{E5BB36C6-A70B-EB23-3716-9151A09AC8A2}',
                    'configuration' => [
                        'MQTTTopic'    => $device['friendly_name'],
                    ]
                    // Keine 'location' Schlüssel
                ];
            }

            $Value['ieee_address'] = $device['ieeeAddr'];
            $Value['networkAddress'] = $device['networkAddress'];
            $Value['type'] = $device['type'];
            $Value['vendor'] = $device['vendor'];
            $Value['modelID'] = (array_key_exists('modelID', $device) == true ? $device['modelID'] : $this->Translate('Unknown'));
            $Value['description'] = $device['description'];
            $Value['power_source'] = (array_key_exists('powerSource', $device) == true ? $this->Translate($device['powerSource']) : $this->Translate('Unknown'));
            $Value['categories'] = $categories;
            $Value['instanceID'] = $instanceID;

            $this->sendDebug('Device Categories', implode(', ', $categories), 0);
            array_push($ValuesDevices, $Value);
        }
        $this->SendDebug('Final Device Array', json_encode($ValuesDevices), 0);
        $Form['actions'][0]['items'][0]['values'] = $ValuesDevices;

        // Groups-Logik
        $Groups = json_decode($this->GetBuffer('Groups'), true);
        $ValuesGroups = [];
        $this->SendDebug('Buffer Groups', json_encode($Groups), 0);

        foreach ($Groups as $group) {
            $Value = [];
            $friendlyNameParts = explode('/', $group['friendly_name']);
            $instanceName = end($friendlyNameParts);

            // Extrahiere alle Teile des friendly_name als Kategorien
            $categories = array_slice($friendlyNameParts, 0, -1);

            $instanceID = $this->getDeviceInstanceID($device['friendly_name']);

    if ($useCategories) {
        // Verwende $instanceName und füge Kategorien hinzu, wenn Kategorien aktiv sind
        $categories = array_slice($friendlyNameParts, 0, -1);
        $this->SendDebug('Group Categories', implode(', ', $categories), 0);
        $Value['name'] = $instanceName;
        $Value['create'] = [
            'moduleID'      => '{11BF3773-E940-469B-9DD7-FB9ACD7199A2}',
            'configuration' => [
                'MQTTTopic'    => $group['friendly_name'],
                    ],
                'location' => $categories // Kategorien hinzufügen
                ];
            } else {
                // Verwende $group['friendly_name'], wenn Kategorien nicht aktiv sind
                $Value['name'] = $group['friendly_name'];
                $Value['create'] = [
                    'moduleID'      => '{11BF3773-E940-469B-9DD7-FB9ACD7199A2}',
                    'configuration' => [
                        'MQTTTopic'    => $group['friendly_name'],
                    ]
                    // Keine 'location' Schlüssel
                ];
            }

            $Value['ID'] = $group['ID'];
            $Value['instanceID'] = $instanceID;
            $Value['categories'] = $categories;


            array_push($ValuesGroups, $Value);
        }
        $this->SendDebug('Final Groups Array', json_encode($ValuesGroups), 0);
        $Form['actions'][1]['items'][0]['values'] = $ValuesGroups;

        // Rückkonvertierung in JSON
        return json_encode($Form);
    }

    public function ReceiveData($JSONString)
    {
        $this->SendDebug('JSON', $JSONString, 0);
        $Buffer = json_decode($JSONString, true);

        if (array_key_exists('Topic', $Buffer)) {
            if (IPS_GetKernelDate() > 1670886000) {
                $Buffer['Payload'] = utf8_decode($Buffer['Payload']);
            }
            if (fnmatch('symcon/' . $this->ReadPropertyString('MQTTBaseTopic') . '/devices', $Buffer['Topic'])) {
                $Payload = json_decode($Buffer['Payload'], true);
                $this->SetBuffer('Devices', json_encode($Payload));
            }
            if (fnmatch('symcon/' . $this->ReadPropertyString('MQTTBaseTopic') . '/groups', $Buffer['Topic'])) {
                $Payload = json_decode($Buffer['Payload'], true);
                $this->SetBuffer('Groups', json_encode($Payload));
            }
        }
    }

    private function getDevices()
    {
        $this->symconExtensionCommand('getDevices', '');
    }

    private function getGroups()
    {
        $this->symconExtensionCommand('getGroups', '');
    }

    private function getDeviceInstanceID($FriendlyName)
    {
        $InstanceIDs = IPS_GetInstanceListByModuleID('{E5BB36C6-A70B-EB23-3716-9151A09AC8A2}');
        foreach ($InstanceIDs as $id) {
            if (IPS_GetProperty($id, 'MQTTTopic') == $FriendlyName) {
                return $id;
            }
        }
        return 0;
    }

    private function getGroupInstanceID($FriendlyName)
    {
        $InstanceIDs = IPS_GetInstanceListByModuleID('{11BF3773-E940-469B-9DD7-FB9ACD7199A2}');
        foreach ($InstanceIDs as $id) {
            if (IPS_GetProperty($id, 'MQTTTopic') == $FriendlyName) {
                return $id;
            }
        }
        return 0;
    }
}
