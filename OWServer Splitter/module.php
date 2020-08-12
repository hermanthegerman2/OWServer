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
require_once __DIR__ . '/../libs/SemaphoreHelper.php';  // diverse Klassen
require_once __DIR__ . '/../libs/VariableHelper.php';  // diverse Klassen
require_once __DIR__ . '/../libs/VariableProfileHelper.php';  // diverse Klassen
require_once __DIR__ . '/../libs/WebhookHelper.php';  // diverse Klassen
require_once __DIR__ . '/../libs/OWNet.php';  // Ownet.php from owfs distribution

/**
 * LMSSplitter Klasse für die Kommunikation mit dem Logitech Media-Server (LMS).
 * Erweitert IPSModule.
 *
 * @todo          Favoriten als Tabelle oder Baum ?! für das WF
 *
 * @author        Michael Tröger <micha@nall-chan.net>
 * @copyright     2020 Michael Tröger
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 *
 * @version       3.51
 *
 * @example <b>Ohne</b>
 *
 **/
	class OWServerSplitter extends IPSModule {

        use OWSPLITProfile,
            VariableProfileHelper,
            VariableHelper,
            DebugHelper,
            BufferHelper,
            InstanceStatus,
            Semaphore,
            WebhookHelper {
            InstanceStatus::MessageSink as IOMessageSink; // MessageSink gibt es sowohl hier in der Klasse, als auch im Trait InstanceStatus. Hier wird für die Methode im Trait ein Alias benannt.
            InstanceStatus::RegisterParent as IORegisterParent;
            InstanceStatus::RequestAction as IORequestAction;
        }
        private $Socket = false;

        public function __destruct()
        {
            if ($this->Socket) {
                fclose($this->Socket);
            }
        }

        /**
         * Interne Funktion des SDK.
         */

		public function Create()
		{
			//Never delete this line!
			parent::Create();

			$this->RequireParent("{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}");
            $this->RegisterPropertyString('Host', '127.0.0.1');
            $this->RegisterPropertyInteger('Port', 4304);
            //$this->RegisterPropertyString('Table', json_encode($Style['Table']));
            //$this->RegisterPropertyString('Columns', json_encode($Style['Columns']));
            //$this->RegisterPropertyString('Rows', json_encode($Style['Rows']));
            $this->RegisterTimer('KeepAlive', 0, 'OWSPLIT_KeepAlive($_IPS["TARGET"]);');

            $this->ReplyOWSPLITData = [];
            $this->Buffer = '';
            $this->Host = '';
            $this->ParentID = 0;
            $this->ScannerID = 0;
		}

		public function Destroy()
		{
			//Never delete this line!
			parent::Destroy();
		}

		public function ApplyChanges()
		{
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            $this->RegisterMessage($this->InstanceID, FM_CONNECT);
            $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);
            $this->ReplyOWSPLITData = [];
            $this->Buffer = '';
            $this->Host = '';
            $this->ParentID = 0;
            $this->ScannerID = 0;
            parent::ApplyChanges();

            // Buffer leeren
            $this->ReplyOWSPLITData = [];
            $this->Buffer = '';

            // Eigene Profile
            $this->CreateProfile();
            // Eigene Variablen
            $this->RegisterVariableString('Version', 'Version', '', 0);
            $this->ScannerID = $this->RegisterVariableInteger('RescanState', 'Scanner', 'OWSPLIT.Scanner', 1);
            $this->RegisterMessage($this->ScannerID, VM_UPDATE);
            $this->EnableAction('RescanState');

            $this->RegisterVariableString('RescanInfo', $this->Translate('Rescan state'), '', 2);
            $this->RegisterVariableString('RescanProgress', $this->Translate('Rescan progress'), '', 3);
            $this->RegisterVariableInteger('Servers', 'Number of servers', '', 4);

            // Wenn Kernel nicht bereit, dann warten... KR_READY kommt ja gleich
            if (IPS_GetKernelRunlevel() != KR_READY) {
                return;
            }

            // Config prüfen
            $this->RegisterParent();

            // Wenn Parent aktiv, dann Anmeldung an der Hardware bzw. Datenabgleich starten
            if ($this->ParentID > 0) {
                IPS_ApplyChanges($this->ParentID);
            }
        }

        /**
         * Interne Funktion des SDK.
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

        /**
         * Interne Funktion des SDK.
         */
        public function GetConfigurationForParent()
        {
            $Config['Port'] = $this->ReadPropertyInteger('Port');
            return json_encode($Config);
        }

        //################# Action

        /**
         * Actionhandler der Statusvariablen. Interne SDK-Funktion.
         *
         * @param string                $Ident Der Ident der Statusvariable.
         * @param bool|float|int|string $Value Der angeforderte neue Wert.
         */
        public function RequestAction($Ident, $Value)
        {
            if ($this->IORequestAction($Ident, $Value)) {
                return true;
            }
            switch ($Ident) {
                case 'OWserverSelect':
                    $ProfilName = 'OWSPLIT.OWserverSelect' . $this->InstanceID;
                    $Profil = IPS_GetVariableProfile($ProfilName)['Associations'];
                    switch ($Value) {
                        case -2: //keiner
                        case -1: //alle
                            $this->SetValueInteger('OWserverSelect', $Value);

                            for ($i = 2; $i < count($Profil); $i++) {
                                IPS_SetVariableProfileAssociation($ProfilName, $Profil[$i]['Value'], $Profil[$i]['Name'], $Profil[$i]['Icon'], -1);
                            }

                            break;
                        default:
                            $Value = $Value + 2;
                            $Profil[$Value]['Color'] = ($Profil[$Value]['Color'] == -1) ? 0x00ffff : -1;
                            IPS_SetVariableProfileAssociation($ProfilName, $Value - 2, $Profil[$Value]['Name'], $Profil[$Value]['Icon'], $Profil[$Value]['Color']);
                            $this->SetValueInteger('OWserverSelect', -3);
                            break;
                    }

                    break;
                case 'RescanState':
                    if ($Value == 1) {
                        $ret = $this->AbortScan();
                    } elseif ($Value == 2) {
                        $ret = $this->Rescan();
                    } elseif ($Value == 3) {
                        $ret = $this->RescanPlaylists();
                    } elseif ($Value == 4) {
                        $ret = $this->WipeCache();
                    }
                    if (($Value != 0) && (($ret === null) || ($ret === false))) {
                        echo $this->Translate('Error on send scanner-command');
                        return false;
                    }
                    if ($this->GetValue('RescanState') != $Value) {
                        $this->SetValueInteger('RescanState', $Value);
                    }
                    break;
                default:
                    echo $this->Translate('Invalid Ident');
                    break;
            }
        }

        //################# PUBLIC

        /**
         * IPS-Instanz-Funktion 'OWSPLIT_KeepAlive'.
         * Sendet einen listen Abfrage an den LMS um die Kommunikation zu erhalten.
         *
         * @result bool true wenn OWSPLIT erreichbar, sonst false.
         */
        public function KeepAlive()
        {
            $Data = new OWSPLITData('presence', '');
            $ret = @$this->Send($Data);
            if ($ret === null) {
                trigger_error($this->Translate('Error on keepalive to OWSPLIT.'), E_USER_NOTICE);
                return false;
            }
            if ($ret->Data[0] == '6') {
                return true;
            }

            trigger_error($this->Translate('Error on keepalive to OWSPLIT.'), E_USER_NOTICE);
            return false;
        }

        /**
         * IPS-Instanz-Funktion 'OWSPLIT_SendSpecial'.
         * Sendet einen Anfrage an den LMS.
         *
         * @param string $Command Das zu sendende Kommando.
         * @param string $Value   Die zu sendenden Werte als JSON String.
         * @result array|bool Antwort des OWSPLIT als Array, false im Fehlerfall.
         */
        public function SendSpecial(string $Command, string $Value)
        {
            $Data = json_decode($Value, true);
            if ($Data === null) {
                trigger_error($this->Translate('Value ist not valid JSON.'), E_USER_NOTICE);
                return false;
            }
            $OWSPLITData = new OWSPLITData($Command, $Data);
            $ret = $this->SendDirect($OWSPLITData);
            return $ret->Data;
        }

        /**
         * IPS-Instanz-Funktion 'OWSPLIT_RestartServer'.
         *
         * @result bool
         */
        public function RestartServer()
        {
            return $this->Send(new OWSPLITData('restartserver')) != null;
        }

        /**
         * IPS-Instanz-Funktion 'OWSPLIT_RequestState'.
         * Fragt einen Wert des LMS ab. Es ist der Ident der Statusvariable zu übergeben.
         *
         * @param string $Ident Der Ident der abzufragenden Statusvariable.
         * @result bool True wenn erfolgreich.
         */
        public function RequestState(string $Ident)
        {
            if ($Ident == '') {
                trigger_error($this->Translate('Invalid Ident'));
                return false;
            }
            switch ($Ident) {
                case 'Servers':
                    $OWSPLITResponse = new OWSPLITData(['server', 'count'], '?');
                    break;
                case 'Version':
                    $OWSPLITResponse = new OWSPLITData('version', '?');
                    break;
                default:
                    trigger_error($this->Translate('Invalid Ident'));
                    return false;
            }
            $OWSPLITResponse = $this->Send($OWSPLITResponse);
            if ($OWSPLITResponse === null) {
                return false;
            }
            return $this->DecodeOWSPLITResponse($OWSPLITResponse);
        }

        //################# DATAPOINTS DEVICE

        /**
         * Interne Funktion des SDK. Nimmt Daten von Children entgegen und sendet Diese weiter.
         *
         * @param string $JSONString Ein Data-Objekt welches als JSONString kodiert ist.
         * @result OWSPLITData|bool
         */
        public function ForwardData($JSONString)
        {
            $Data = json_decode($JSONString);
            $OWSPLITData = new OWSPLITData();
            $OWSPLITData->CreateFromGenericObject($Data);
            $ret = $this->Send($OWSPLITData);
            if (!is_null($ret)) {
                return serialize($ret);
            }

            return false;
        }

        //################# DATAPOINTS PARENT

        /**
         * Empfängt Daten vom Parent.
         *
         * @param string $JSONString Das empfangene JSON-kodierte Objekt vom Parent.
         * @result bool True wenn Daten verarbeitet wurden, sonst false.
         */
        public function ReceiveData($JSONString)
        {
            $data = json_decode($JSONString);

            // DatenStream zusammenfügen
            $head = $this->Buffer;
            $Data = $head . utf8_decode($data->Buffer);
            $this->SendDebug('OWSPLIT_Event', $Data, 0);

            // Stream in einzelne Pakete schneiden
            $packet = explode(chr(0x0d), $Data);

            // Rest vom Stream wieder in den EmpfangsBuffer schieben
            $tail = trim(array_pop($packet));
            $this->Buffer = $tail;

            // Pakete verarbeiten
            foreach ($packet as $part) {
                $part = trim($part);
                $Data = new OWSPLITResponse($part);

                try {
                    $isResponse = $this->SendQueueUpdate($Data);
                } catch (Exception $exc) {
                    $buffer = $this->Buffer;
                    $this->Buffer = $part . chr(0x0d) . $buffer;
                    trigger_error($exc->getMessage(), E_USER_NOTICE);
                    continue;
                }
                if ($Data->Command[0] == 'client') { // Client änderungen auch hier verarbeiten!
                    $this->RefreshServerList();
                }

                if ($isResponse === false) { //War keine Antwort also ein Event
                    $this->SendDebug('OWSPLIT_Event', $Data, 0);
                    if ($Data->Device != OWSPLITResponse::isServer) {
                        if ($Data->Command[0] == 'playlist') {
                            $this->DecodeOWSPLITResponse($Data);
                        }
                        $this->SendDataToDevice($Data);
                    } else {
                        $this->DecodeOWSPLITResponse($Data);
                    }
                }
            }
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
            $IOId = $this->IORegisterParent();
            if ($IOId > 0) {
                $this->Host = gethostbyname(IPS_GetProperty($this->ParentID, 'Host'));
                $this->SetSummary(IPS_GetProperty($IOId, 'Host'));
                return;
            }
            $this->Host = '';
            $this->SetSummary(('none'));
        }

        /**
         * Wird ausgeführt wenn sich der Status vom Parent ändert.
         */
        protected function IOChangeState($State)
        {
            if ($State == IS_ACTIVE) {
                if ($this->HasActiveParent()) {
                    if ($this->CheckConnection() !== true) {
                        $this->SetStatus(IS_EBASE + 4);
                        $this->SetTimerInterval('KeepAlive', 0);
                        return;
                    }
                    $this->SetStatus(IS_ACTIVE);
                    $this->KeepAlive();
                    $this->LogMessage($this->Translate('Connected to OWSPLIT'), KL_NOTIFY);
                    $this->RequestState('Version');
                    $this->LogMessage($this->Translate('Version of OWSPLIT:') . $this->GetValue('Version'), KL_NOTIFY);
                    $this->RequestState('Servers');
                    $this->LogMessage($this->Translate('Connected Servers to OWSPLIT:') . $this->GetValue('Servers'), KL_NOTIFY);
                    $this->RefreshServerList();
                    $ret = $this->Send(new OWSPLITData('rescan', '?'));
                    if ($ret !== null) {
                        $this->DecodeOWSPLITResponse($ret);
                    }
                    $this->SetTimerInterval('KeepAlive', 3600 * 1000);
                    return;
                }
            }
            $this->SetStatus(IS_INACTIVE); // Setzen wir uns auf active, weil wir vorher eventuell im Fehlerzustand waren.
            $this->SetTimerInterval('KeepAlive', 0);
        }

        /**
         * Versendet ein OWSPLITData-Objekt und empfängt die Antwort.
         *
         * @param OWSPLITData $OWSPLITData Das Objekt welches versendet werden soll.
         *
         * @return OWSPLITData Enthält die Antwort auf das Versendete Objekt oder NULL im Fehlerfall.
         */
        protected function Send(OWSPLITData $OWSPLITData)
        {
            try {
                if (IPS_GetInstance($this->InstanceID)['InstanceStatus'] != IS_ACTIVE) {
                    throw new Exception($this->Translate('Instance inactive.'), E_USER_NOTICE);
                }

                if (!$this->HasActiveParent()) {
                    throw new Exception($this->Translate('Instance has no active parent.'), E_USER_NOTICE);
                }

                if ($OWSPLITData->needResponse) {
                    $this->SendDebug('Send', $OWSPLITData, 0);
                    //$this->SendQueuePush($OWSPLITData);
                    $this->SendDataToParent($OWSPLITData->ToJSONStringForOWSPLIT('{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}'));
                    $ReplyDataArray = $this->WaitForResponse($OWSPLITData);

                    if ($ReplyDataArray === false) {
                        throw new Exception($this->Translate('No answer from OWSPLIT'), E_USER_NOTICE);
                    }

                    $OWSPLITData->Data = $ReplyDataArray;
                    $this->SendDebug('Response', $OWSPLITData, 0);
                    return $OWSPLITData;
                } else { // ohne Response, also ohne warten raussenden,
                    $this->SendDebug('SendFaF', $OWSPLITData, 0);
                    return $this->SendDataToParent($OWSPLITData->ToJSONStringForOWSPLIT('{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}'));
                }
            } catch (Exception $exc) {
                trigger_error($exc->getMessage(), $exc->getCode());
                return null;
            }
        }

        /**
         * Konvertiert $Data zu einem String und versendet diesen direkt an den LMS.
         *
         * @param OWSPLITData $OWSPLITData Zu versendende Daten.
         *
         * @return OWSPLITData OWSPLITData mit der Antwort. NULL im Fehlerfall.
         */
        protected function SendDirect(OWSPLITData $OWSPLITData)
        {
            try {
                if (IPS_GetInstance($this->InstanceID)['InstanceStatus'] != IS_ACTIVE) {
                    throw new Exception($this->Translate('Instance inactive.'), E_USER_NOTICE);
                }

                if (!$this->HasActiveParent()) {
                    throw new Exception($this->Translate('Instance has no active parent.'), E_USER_NOTICE);
                }

                if ($this->Host === '') {
                    return null;
                }

                $this->SendDebug('Send Direct', $OWSPLITData, 0);

                if (!$this->Socket) {
                    $Host = $this->ReadPropertyString('Host');
                    $Port = $this->ReadPropertyInteger('Port');

                    $this->Socket = @new OWNet("tcp://" . $Host . ':' . $Port);
                    if (!$this->Socket) {
                        throw new Exception($this->Translate('No answer from OWSPLIT'), E_USER_NOTICE);
                    }
                    //we are connected, proceed
                    $ow_dir = $this->Socket ->dir($this->ow_path);
                    if ($ow_dir && isset($ow_dir['data_php'])) {
                        //walk through the retrieved tree
                        $dirs = explode(",", $ow_dir['data_php']);
                        if (is_array($dirs) && (count($dirs) > 0)) {
                            $i = 0;
                            foreach ($dirs as $dev) {
                                $data = array();
                                $caps = '';
                                /* read standard device details */
                                //get family id
                                $fam = $ow->read("$dev/family");
                                if (!$fam) continue; //not a device path
                                //get device id
                                $id = $ow->read("$dev/id");
                                //get alias (if any) and owfs detected device description as type
                                $alias = $ow->get("$dev/alias");
                                $type = $ow->get("$dev/type");
                                if (!$type) {
                                    $type = "1Wire Family " . $fam;
                                }
                                //assign names for ips categories
                                $name = $id;
                                if ($alias) {
                                    $name = $alias;
                                    $caps = 'Name';
                                }

                                //get varids
                                $addr = "$fam.$id";
                                //print "$id ($alias): Type $type Family $fam\n";
                                //retrieve device specific data
                                switch ($fam) {
                                    case '28': //DS18B20 temperature sensors
                                    case '10': //DS18S20 temperature sensors
                                    case '22': //DS1820 temperature sensors
                                        $temp = $ow->read("$dev/temperature", true);
                                        $temp = str_replace(",", ".", $temp);
                                        if (strlen($temp) > 0) {
                                            //store new temperature value
                                            //$this->_log('OWNet', "$type $id ($alias): $temp");
                                            $data['Name'] = $name;
                                            $data['Id'] = $addr;
                                            $data['Typ'] = $type;
                                            $data['Temp'] = sprintf("%4.2F", $temp);
                                            //print " Alias '$alias',Temp $temp\n";
                                            $caps .= ';Temp';
                                            $this->_log('OWNet Device', $data);
                                            $OWDeviceArray[$i] = $data;
                                            $i = ++$i;
                                        }
                                        break;
                                    default:
                                        $this->SendDebug('OWNet', "$id ($alias): Type $type Family $fam not implemented yet",0);
                                }
                            }
                            $this->SendDebug('OWNet Device Array', $OWDeviceArray, 0);
                            //$this->SetBuffer('OWDeviceArray', json_encode($OWDeviceArray));
                        }
                    }


                    /*
                     * stream_set_timeout($this->Socket, 5);
                     * fwrite($this->Socket, $LoginData);
                     * $answerlogin = stream_get_line($this->Socket, 1024 * 1024 * 2, chr(0x0d));
                     * $this->SendDebug('Response Direct', $answerlogin, 0);
                     * if ($answerlogin === false) {
                     *    throw new Exception($this->Translate('No answer from OWSPLIT'), E_USER_NOTICE);
                     * }
                     *
                     */
                }

                $Data = $OWSPLITData->ToRawStringForOWSPLIT();
                $this->SendDebug('Send Direct', $Data, 0);
                fwrite($this->Socket, $Data);
                $answer = stream_get_line($this->Socket, 1024 * 1024 * 2, chr(0x0d));
                $this->SendDebug('Response Direct', $answer, 0);
                if ($answer === false) {
                    throw new Exception($this->Translate('No answer from OWSPLIT'), E_USER_NOTICE);
                }

                $ReplyData = new OWSPLITResponse($answer);
                $OWSPLITData->Data = $ReplyData->Data;
                $this->SendDebug('Response Direct', $OWSPLITData, 0);
                return $OWSPLITData;
            } catch (Exception $ex) {
                $this->SendDebug('Response Direct', $ex->getMessage(), 0);
                trigger_error($ex->getMessage(), $ex->getCode());
            }
            return null;
        }

        //################# Privat

        /**
         * Ändert das Variablenprofil ServerSelect anhand der bekannten Server.
         *
         * @return bool TRUE bei Erfolg, sonst FALSE.
         */
        private function RefreshServerList()
        {
            $OWSPLITData = $this->SendDirect(new OWSPLITData(['server', 'count'], '?'));
            if ($OWSPLITData == null) {
                return false;
            }
            $servers = $OWSPLITData->Data[0];
            $this->SetValueInteger('Servers', $servers);
            $Assoziation = [];
            $Assoziation[] = [-2, 'Keiner', '', 0x00ff00];
            $Assoziation[] = [-1, 'Alle', '', 0xff0000];
            for ($i = 0; $i < $servers; $i++) {
                $OWSPLITServerData = $this->SendDirect(new $OWSPLITData(['server', 'name'], [$i, '?']));
                if ($$OWSPLITServerData == null) {
                    continue;
                }
                $ServerName = $OWSPLITServerData->Data[1];
                $Assoziation[] = [$i, $ServerName, '', -1];
            }

            return true;
        }

        private function DecodeOWSPLITResponse(OWSPLITData $OWSPLITData)
        {
            if ($OWSPLITData == null) {
                return false;
            }
            $this->SendDebug('Decode', $OWSPLITData, 0);
            switch ($OWSPLITData->Command[0]) {
                case 'listen':
                    return true;
                case 'scanner':
                    switch ($OWSPLITData->Data[0]) {
                        case 'notify':
                            $Data = new OWSPLITTaggingData($OWSPLITData->Data[1]);
//                        $this->SendDebug('scanner', $Data, 0);
                            switch ($Data->Name) {
                                case 'end':
                                case 'exit':
                                    $this->SetValueString('RescanInfo', '');
                                    $this->SetValueString('RescanProgress', '');
                                    return true;
                                case 'progress':
                                    $Info = explode('||', $Data->Value);
                                    $StepInfo = $Info[2];
                                    if (strpos($StepInfo, '|')) {
                                        $StepInfo = explode('|', $StepInfo)[1];
                                    }
                                    $this->SetValueString('RescanInfo', $StepInfo);
                                    $StepProgress = $Info[3] . ' von ' . $Info[4];
                                    $this->SetValueString('RescanProgress', $StepProgress);
                                    return true;
                            }
                            break;
                    }
                    break;
                case 'server':
                    if ($OWSPLITData->Command[1] == 'count') {
                        $this->SetValueInteger('Servers', (int) $OWSPLITData->Data[0]);
                        return true;
                    }
                    break;
                case 'version':
                    $this->SetValueString('Version', $OWSPLITData->Data[0]);
                    return true;
                case 'rescan':
                    if (!isset($OWSPLITData->Data[0])) {
                        if ($this->GetValue('RescanState') != 2) {
                            $this->SetValueInteger('RescanState', 2); // einfacher
                        }
                        return true;
                    } else {
                        if (($OWSPLITData->Data[0] == 'done') || ($OWSPLITData->Data[0] == '0')) {
                            if ($this->GetValue('RescanState') != 0) {
                                $this->SetValueInteger('RescanState', 0);   // fertig
                            }
                            return true;
                        } elseif ($OWSPLITData->Data[0] == '1') {
                            //start
                            if ($this->GetValue('RescanState') != 2) {
                                $this->SetValueInteger('RescanState', 2); // einfacher
                            }
                            return true;
                        }
                    }
                    break;

                default:
                    break;
            }
            return false;
        }

        /**
         * Sendet OWSPLITData an die Children.
         *
         * @param OWSPLITResponse $OWSPLITResponse Ein OWSPLITResponse-Objekt.
         */
        private function SendDataToDevice(OWSPLITResponse $OWSPLITResponse)
        {
            $Data = $OWSPLITResponse->ToJSONStringForDevice('{41FAEDA0-12D1-C0AF-0379-0DED801354B3}');
            $this->SendDebug('IPS_SendDataToChildren', $Data, 0);
            $this->SendDataToChildren($Data);
        }

        private function CheckConnection()
        {
            if ($this->Host === '') {
                return false;
            }

            $Host = $this->ReadPropertyString('Host');
            $Port = $this->ReadPropertyInteger('Port');
            $OW = @new OWNet("tcp://" . $Host . ':' . $Port);
            try {
                if (!$OW) {
                    $this->SendDebug('no socket', $errstr, 0);
                    throw new Exception($this->Translate('No answer from OWSPLIT'), E_USER_NOTICE);
                }

            } catch (Exception $ex) {
                echo $ex->getMessage();
                return false;
            }
            return true;
        }

        /**
         * Wartet auf eine Antwort einer Anfrage an den LMS.
         *
         * @param OWSPLITData $OWSPLITData Das Objekt welches an den OWSPLIT versendet wurde.
         * @result array|boolean Enthält ein Array mit den Daten der Antwort. False bei einem Timeout
         */
        private function WaitForResponse(OWSPLITData $OWSPLITData)
        {
            $SearchPatter = $OWSPLITData->GetSearchPatter();
            for ($i = 0; $i < 1000; $i++) {
                $Buffer = $this->ReplyOWSPLITData;
                if (!array_key_exists($SearchPatter, $Buffer)) {
                    return false;
                }
                if (array_key_exists('Data', $Buffer[$SearchPatter])) {
                    $this->SendQueueRemove($SearchPatter);
                    return $Buffer[$SearchPatter]['Data'];
                }
                IPS_Sleep(5);
            }
            $this->SendQueueRemove($SearchPatter);
            return false;
        }

        //################# SENDQUEUE

        /**
         * Fügt eine Anfrage in die SendQueue ein.
         *
         * @param OWSPLITData $OWSPLITData Das versendete OWSPLITData Objekt.
         */
        private function SendQueuePush(OWSPLITData $OWSPLITData)
        {
            if (!$this->lock('ReplyOWSPLITData')) {
                throw new Exception($this->Translate('ReplyOWSPLITData is locked'), E_USER_NOTICE);
            }
            $data = $this->ReplyOWSPLITData;
            $data[$OWSPLITData->GetSearchPatter()] = [];
            $this->ReplyOWSPLITData = $data;
            $this->unlock('ReplyOWSPLITData');
        }

        /**
         * Fügt eine Antwort in die SendQueue ein.
         *
         * @param LOWSPLITesponse $OWSPLITResponse Das empfangene OWSPLITData Objekt.
         *
         * @return bool True wenn Anfrage zur Antwort gefunden wurde, sonst false.
         */
        private function SendQueueUpdate(OWSPLITResponse $OWSPLITResponse)
        {
            if (!$this->lock('ReplyOWSPLITData')) {
                throw new Exception($this->Translate('ReplyOWSPLITData is locked'), E_USER_NOTICE);
            }
//        if (is_array($LMSResponse->Command))
            $key = $OWSPLITResponse->GetSearchPatter(); //Address . implode('', $LMSResponse->Command);
            //$this->SendDebug('SendQueueUpdate', $key, 0);
//        else
//            $key = $LMSResponse->Address . $LMSResponse->Command;
            $data = $this->ReplyOWSPLITData;
            if (array_key_exists($key, $data)) {
                $data[$key]['Data'] = $OWSPLITResponse->Data;
                $this->ReplyLMSData = $data;
                $this->unlock('ReplyOWSPLITData');
                return true;
            }
            $this->unlock('ReplyOWSPLITData');
            return false;
        }

        /**
         * Löscht einen Eintrag aus der SendQueue.
         *
         * @param int $Index Der Index des zu löschenden Eintrags.
         */
        private function SendQueueRemove(string $Index)
        {
            if (!$this->lock('ReplyOWSPLITData')) {
                throw new Exception($this->Translate('ReplyOWSPLITData is locked'), E_USER_NOTICE);
            }
            $data = $this->ReplyOWSPLITData;
            unset($data[$Index]);
            $this->ReplyOWSPLITData = $data;
            $this->unlock('ReplyOWSPLITData');
        }
	}