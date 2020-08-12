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

			$this->RequireParent("{8062CF2B-600E-41D6-AD4B-1BA66C32D6ED}");
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
                    if ($this->CheckLogin() !== true) {
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
                    $ret = $this->Send(new LMSData('rescan', '?'));
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
                    $this->SendQueuePush($OWSPLITData);
                    $this->SendDataToParent($OWSPLITData->ToJSONStringForOWSPLIT('{C8792760-65CF-4C53-B5C7-A30FCC84FEFE}'));
                    $ReplyDataArray = $this->WaitForResponse($OWSPLITData);

                    if ($ReplyDataArray === false) {
                        throw new Exception($this->Translate('No answer from OWSPLIT'), E_USER_NOTICE);
                    }

                    $OWSPLITData->Data = $ReplyDataArray;
                    $this->SendDebug('Response', $OWSPLITData, 0);
                    return $OWSPLITData;
                } else { // ohne Response, also ohne warten raussenden,
                    $this->SendDebug('SendFaF', $OWSPLITData, 0);
                    return $this->SendDataToParent($OWSPLITData->ToJSONStringForOWSPLIT('{C8792760-65CF-4C53-B5C7-A30FCC84FEFE}'));
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
                    $Port = $this->ReadPropertyInteger('Port');
                    //$User = $this->ReadPropertyString('User');
                    //$Pass = $this->ReadPropertyString('Password');

                    //$LoginData = (new LMSData('login', [$User, $Pass]))->ToRawStringForLMS();
                    //$this->SendDebug('Send Direct', $LoginData, 0);
                    $this->Socket = @stream_socket_client('tcp://' . $this->Host . ':' . $Port, $errno, $errstr, 1);
                    if (!$this->Socket) {
                        throw new Exception($this->Translate('No answer from OWSPLIT'), E_USER_NOTICE);
                    }
                    stream_set_timeout($this->Socket, 5);
                    //fwrite($this->Socket, $LoginData);
                    $answerlogin = stream_get_line($this->Socket, 1024 * 1024 * 2, chr(0x0d));
                    $this->SendDebug('Response Direct', $answerlogin, 0);
                    if ($answerlogin === false) {
                        throw new Exception($this->Translate('No answer from OWSPLIT'), E_USER_NOTICE);
                    }
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

        private function CheckLogin()
        {
            if ($this->Host === '') {
                return false;
            }

            $Port = $this->ReadPropertyInteger('Port');

            try {
                $fp = @stream_socket_client('tcp://' . $this->Host . ':' . $Port, $errno, $errstr, 2);
                if (!$fp) {
                    $this->SendDebug('no socket', $errstr, 0);

                    throw new Exception($this->Translate('No answer from OWSPLIT') . ' ' . $errstr, E_USER_NOTICE);
                } else {
                    stream_set_timeout($fp, 5);
                    $this->SendDebug('Check login', 'check OWSPLIT', 0);
                    //fwrite($fp, $LoginData);
                    $answerlogin = stream_get_line($fp, 1024 * 1024 * 2, chr(0x0d));
                    $this->SendDebug('Receive login', 'OWSPLIT', 0);
                    $this->SendDebug('Connection check', $CheckData, 0);
                    fwrite($fp, $CheckData);
                    $answer = stream_get_line($fp, 1024 * 1024 * 2, chr(0x0d));
                    fclose($fp);
                    $this->SendDebug('Receive check', $answer, 0);
                }
                if ($answerlogin === false) {
                    throw new Exception($this->Translate('No answer from OWSPLIT'), E_USER_NOTICE);
                }
                if ($answer === false) {
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


/*
        public function ForwardData($JSONString)
		{
			$data = json_decode($JSONString);
			IPS_LogMessage("Splitter FRWD", utf8_decode($data->Buffer . " - " + $data->ClientIP + " - " . $data->ClientPort));

			$this->SendDataToParent(json_encode(Array("DataID" => "{C8792760-65CF-4C53-B5C7-A30FCC84FEFE}", "Buffer" => $data->Buffer, $data->ClientIP, $data->ClientPort)));

			return "String data for device instance!";
		}

		public function ReceiveData($JSONString)
		{
			$data = json_decode($JSONString);
			IPS_LogMessage("Splitter RECV", utf8_decode($data->Buffer . " - " + $data->ClientIP + " - " . $data->ClientPort));

			$this->SendDataToChildren(json_encode(Array("DataID" => "{41FAEDA0-12D1-C0AF-0379-0DED801354B3}", "Buffer" => $data->Buffer, $data->ClientIP, $data->ClientPort)));
		}
*/

	}