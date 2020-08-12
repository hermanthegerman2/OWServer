<?php

declare(strict_types=1);

/*
 * @addtogroup OWServer
 * @{
 *
 * @package       OWServer
 * @file          module.php
 * @author        Hermann Dötsch
 * @copyright     2020 Hermann Dötsch
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 * @version       1.0
 *
 */
require_once __DIR__ . '/../libs/DebugHelper.php';  // diverse Klassen
require_once __DIR__ . '/../libs/OWServerClass.php';  // diverse Klassen
require_once __DIR__ . '/../libs/BufferHelper.php';  // diverse Klassen
require_once __DIR__ . '/../libs/ParentIOHelper.php';  // diverse Klassen

/**
 * OWServerKonfigurator Klasse für ein OWServer Konfigurator.
 * Erweitert IPSModule.
 *
 * @author        Hermann Dötsch
 * @copyright     2020 Hermann Dötsch
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 * @version       1.0
 *
 * @example <b>Ohne</b>
 */
	class OWServerKonfigurator extends IPSModule {
        use DebugHelper,
            BufferHelper,
            InstanceStatus {
            InstanceStatus::MessageSink as IOMessageSink;
            InstanceStatus::RegisterParent as IORegisterParent;
            InstanceStatus::RequestAction as IORequestAction;
        }

        /**
         * Interne Funktion des SDK.
         */

		public function Create()
		{
			//Never delete this line!
			parent::Create();
            $this->ConnectParent('{CFDFE5FC-C6AE-F6EB-D449-89A2A1F25794}');
            $this->SetReceiveDataFilter('.*"nothingtoreceive":.*');
            $this->ParentID = 0;
		}

		public function ApplyChanges()
		{

            $this->ParentID = 0;
            $this->SetReceiveDataFilter('.*"nothingtoreceive":.*');
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            $this->RegisterMessage($this->InstanceID, FM_CONNECT);
            $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);
            //Never delete this line!
            parent::ApplyChanges();
            if (IPS_GetKernelRunlevel() != KR_READY) {
                return;
            }

            $this->RegisterParent();
            if ($this->HasActiveParent()) {
                $this->IOChangeState(IS_ACTIVE);
            }
        }

        /**
         * Interne Funktion des SDK.
         *
         *
         * @param type $TimeStamp
         * @param type $SenderID
         * @param type $Message
         * @param type $Data
         */
        public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
        {
            $this->IOMessageSink($TimeStamp, $SenderID, $Message, $Data);

            switch ($Message) {
                case IPS_KERNELSTARTED:
                    $this->KernelReady();
                    break;
            }
        }

        public function RequestAction($Ident, $Value)
        {
            if ($this->IORequestAction($Ident, $Value)) {
                return true;
            }
            return false;
        }

        /**
         * Interne Funktion des SDK.
         */
        public function GetConfigurationForm()
        {
            $Form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

            if (!$this->HasActiveParent()) {
                $Form['actions'][] = [
                    'type'  => 'PopupAlert',
                    'popup' => [
                        'items' => [[
                            'type'    => 'Label',
                            'caption' => 'Instance has no active parent.'
                        ]]
                    ]
                ];
                $this->SendDebug('FORM', json_encode($Form), 0);
                $this->SendDebug('FORM', json_last_error_msg(), 0);

                return json_encode($Form);
            }
            $Splitter = IPS_GetInstance($this->InstanceID)['ConnectionID'];
            $IO = IPS_GetInstance($Splitter)['ConnectionID'];
            $ParentCreate = [
                [
                    'moduleID'      => '{CFDFE5FC-C6AE-F6EB-D449-89A2A1F25794}',
                    'configuration' => [
                        'Port'     => IPS_GetProperty($Splitter, 'Port'),
                    ]
                ],
                [
                    'moduleID'      => '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}',
                    'configuration' => [
                        'Host' => IPS_GetProperty($IO, 'Host'),
                        'Port' => (int) IPS_GetProperty($IO, 'Port')
                    ]
                ]
            ];

            $FoundDS18B20 = $FoundDS2413 = $FoundDS2450 = $this->GetDeviceInfo();
            //$FoundServers = array_filter($FoundDS18B20, [$this, 'FilterDS18B20']);
            $this->SendDebug('Found DS18B20', $FoundDS18B20, 0);
            $this->SendDebug('Found DS2413', $FoundDS2413, 0);
            $this->SendDebug('Found DS2450', $FoundDS2450, 0);
            $InstanceIDListDS18B20 = $this->GetInstanceList('{2E207819-9E78-61B7-D7DA-E89D749B8A30}', 'Address');
            $this->SendDebug('OWServer DS18B20', $InstanceIDListDS18B20, 0);
            $InstanceIDListDS2413 = $this->GetInstanceList('{6C06CC7D-13CD-69CD-F932-96AE76FC6C6C}', 'Address');
            $this->SendDebug('OWServer DS2413', $InstanceIDListDS2413, 0);
            $InstanceIDListDS2450 = $this->GetInstanceList('{B2963A2B-C5F8-03C3-CC97-6A81A0225EC0}', 'Address');
            $this->SendDebug('OWServer DS2450', $InstanceIDListDS2450, 0);

            // DS18B20
            $DS18B20Values = [];
            foreach ($FoundDS18B20 as $Address => $Device) {
                $InstanceID = array_search($Address, $InstanceIDListDS18B20);
                if ($InstanceID !== false) {
                    $AddValue = [
                        'instanceID' => $InstanceID,
                        'name'       => IPS_GetName($InstanceID),
                        'address'    => $Address,
                        'location'   => IPS_GetLocation($InstanceID)
                    ];
                    unset($InstanceIDListDS18B20[$InstanceID]);
                } else {
                    $AddValue = [
                        'instanceID' => 0,
                        'name'       => $this->Translate('DS18B20') . ' ' . $Device['name'],
                        'address'    => $Address,
                        'location'   => ''
                    ];
                }
                $Create = [
                    'moduleID'      => '{2E207819-9E78-61B7-D7DA-E89D749B8A30}',
                    'configuration' => ['Address' => $Address]
                ];

                $AddValue['create'] = array_merge([$Create], $ParentCreate);
                $DS18B20Values[] = $AddValue;
            }
            foreach ($InstanceIDListDS18B20 as $InstanceID => $Address) {
                $Values[] = [
                    'instanceID' => $InstanceID,
                    'name'       => IPS_GetName($InstanceID),
                    'model'      => 'unknown',
                    'address'    => $Address,
                    'location'   => stristr(IPS_GetLocation($InstanceID), IPS_GetName($InstanceID), true)
                ];
            }

            // DS2413
            $DS2413Values = [];
            foreach ($FoundDS2413 as $Address => $Device) {
                $InstanceID = array_search($Address, $InstanceIDListDS2413);
                if ($InstanceID !== false) {
                    $AddValue = [
                        'instanceID' => $InstanceID,
                        'name'       => IPS_GetName($InstanceID),
                        'address'    => $Address,
                        'location'   => IPS_GetLocation($InstanceID)
                    ];
                    unset($InstanceIDListDS2413[$InstanceID]);
                } else {
                    $AddValue = [
                        'instanceID' => 0,
                        'name'       => $this->Translate('DS2413') . ' ' . $Device['name'],
                        'address'    => $Address,
                        'location'   => ''
                    ];
                }
                $Create = [
                    'moduleID'      => '{6C06CC7D-13CD-69CD-F932-96AE76FC6C6C}',
                    'configuration' => ['Address' => $Address]
                ];

                $AddValue['create'] = array_merge([$Create], $ParentCreate);
                $DS2413Values[] = $AddValue;
            }
            foreach ($InstanceIDListDS2413 as $InstanceID => $Address) {
                $DS2413Values[] = [
                    'instanceID' => $InstanceID,
                    'name'       => IPS_GetName($InstanceID),
                    'address'    => $Address,
                    'location'   => IPS_GetLocation($InstanceID)
                ];
            }

            // DS2450
            $DS2450Values = [];
            foreach ($FoundDS2450 as $Address => $Device) {
                $InstanceID = array_search($Address, $InstanceIDListDS2450);
                if ($InstanceID !== false) {
                    $AddValue = [
                        'instanceID' => $InstanceID,
                        'name'       => IPS_GetName($InstanceID),
                        'address'    => $Address,
                        'location'   => IPS_GetLocation($InstanceID)
                    ];
                    unset($InstanceIDListDS2450[$InstanceID]);
                } else {
                    $AddValue = [
                        'instanceID' => 0,
                        'name'       => $this->Translate('DS2450') . ' ' . $Device['name'],
                        'address'    => $Address,
                        'location'   => ''
                    ];
                }
                $Create = [
                    'moduleID'      => '{B2963A2B-C5F8-03C3-CC97-6A81A0225EC0}',
                    'configuration' => ['Address' => $Address]
                ];

                $AddValue['create'] = array_merge([$Create], $ParentCreate);
                $DS2450Values[] = $AddValue;
            }
            foreach ($InstanceIDListDS2450 as $InstanceID => $Address) {
                $DS2450Values[] = [
                    'instanceID' => $InstanceID,
                    'name'       => IPS_GetName($InstanceID),
                    'address'    => $Address,
                    'location'   => IPS_GetLocation($InstanceID)
                ];
            }

            $Form['actions'][0]['items'][0]['values'] = $DS18B20Values;
            $Form['actions'][0]['items'][0]['rowCount'] = count($DS18B20Values) + 1;
            $Form['actions'][1]['items'][0]['values'] = $DS2413Values;
            $Form['actions'][1]['items'][0]['rowCount'] = count($DS2413Values) + 1;
            $Form['actions'][2]['items'][0]['values'] = $DS2450Values;
            $Form['actions'][2]['items'][0]['rowCount'] = count($DS2450Values) + 1;
            $this->SendDebug('FORM', json_encode($Form), 0);
            $this->SendDebug('FORM', json_last_error_msg(), 0);
            return json_encode($Form);
        }

        /**
         * Wird ausgeführt wenn der Kernel hochgefahren wurde.
         */
        protected function KernelReady()
        {
            $this->ApplyChanges();
        }

        protected function RegisterParent()
        {
            $SplitterId = $this->IORegisterParent();
            if ($SplitterId > 0) {
                $IOId = @IPS_GetInstance($SplitterId)['ConnectionID'];
                if ($IOId > 0) {
                    $this->SetSummary(IPS_GetProperty($IOId, 'Host'));

                    return;
                }
            }
            $this->SetSummary(('none'));
        }

        /**
         * Wird ausgeführt wenn sich der Status vom Parent ändert.
         */
        protected function IOChangeState($State)
        {
            if ($State == IS_ACTIVE) {
                // Buffer aller Player laden
            } else {
                // Buffer aller Player leeren
            }
        }

        /**
         * IPS-Instanz-Funktion 'OWKONF_GetDeviceInfo'.
         * Lädt die bekannten Devices vom OWSPLIT.
         *
         * @result array|bool Assoziiertes Array,  false und Fehlermeldung.
         */
        private function GetDeviceInfo()
        {
            $count = $this->Send(new OWSPLITData(['server', 'count'], '?'));
            if (($count === false) || ($count === null)) {
                return [];
            }
            $servers = [];
            for ($i = 0; $i < $count->Data[0]; $i++) {
                $serverid = $this->Send(new OWSPLITData(['server', 'id'], [$i, '?']));
                if ($serverid === false) {
                    continue;
                }
                $id = strtolower(rawurldecode($serverid->Data[1]));

                $serverid = $this->Send(new OWSPLITData(['server', 'ip'], [$i, '?']));
                if ($serverid === false) {
                    continue;
                }
                $serverid[$id]['ip'] = rawurldecode(explode(':', $serverip->Data[1])[0]);
                $servername = $this->Send(new OWSPLITData(['server', 'name'], [$i, '?']));
                if ($servername === false) {
                    continue;
                }
                $players[$id]['name'] = rawurldecode($playername->Data[1]);
                $servermodel = $this->Send(new OWSPLITData(['server', 'model'], [$i, '?']));
                if ($servermodel === false) {
                    continue;
                }
                $servers[$id]['model'] = rawurldecode($servermodel->Data[1]);
            }
            return $servers;
        }

        private function GetInstanceList(string $GUID, string $ConfigParam)
        {
            $InstanceIDList = array_flip(array_values(array_filter(IPS_GetInstanceListByModuleID($GUID), [$this, 'FilterInstances'])));
            if ($ConfigParam != '') {
                array_walk($InstanceIDList, [$this, 'GetConfigParam'], $ConfigParam);
            }
            return $InstanceIDList;
        }

        private function FilterInstances(int $InstanceID)
        {
            return IPS_GetInstance($InstanceID)['ConnectionID'] == $this->ParentID;
        }

        private function GetConfigParam(&$item1, $InstanceID, $ConfigParam)
        {
            $item1 = IPS_GetProperty($InstanceID, $ConfigParam);
        }

        /**
         * Konvertiert $Data zu einem JSONString und versendet diese an den Splitter.
         *
         * @param OWSPLITData $OWSPLITData Zu versendende Daten.
         *
         * @return OWSPLITData Objekt mit der Antwort. NULL im Fehlerfall.
         */
        private function Send(OWSPLITData $OWSPLITData)
        {
            try {
                $JSONData = $OWSPLITData->ToJSONString('{018EF6B5-AB94-40C6-AA53-46943E824ACF}');
                $answer = @$this->SendDataToParent($JSONData);
                if ($answer === false) {
                    return null;
                }
                $result = @unserialize($answer);
                if ($result === null) {
                    return null;
                }
                $OWSPLITData->Data = $result->Data;
                return $OWSPLITData;
            } catch (Exception $exc) {
                trigger_error($exc->getMessage(), E_USER_NOTICE);
                return null;
            }
        }
	}