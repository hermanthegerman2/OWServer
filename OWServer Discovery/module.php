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

/**
 * OWServerDiscovery Klasse implementiert.
 *
 * @author        Hermann Dötsch
 * @copyright     2020 Hermann Dötsch
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 * @version       1.0
 *
 * @example <b>Ohne</b>
 *
 * @property array $Devices
 */
	class OWServerDiscovery extends IPSModule {
        use DebugHelper;

        /**
         * Interne Funktion des SDK.
         */

		public function Create()
		{
			//Never delete this line!
			parent::Create();
		}

		public function ApplyChanges()
		{
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
		    //Never delete this line!
			parent::ApplyChanges();
		}

        /**
         * Interne Funktion des SDK.
         */
        public function GetConfigurationForm()
        {
            $Devices = $this->DiscoverDevices();
            $Form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
            $IPSDevices = $this->GetIPSInstances();

            $Values = [];

            foreach ($Devices as $IPAddress => $Device) {
                $AddValue = [
                    'IPAddress'  => $IPAddress,
                    'servername' => $Device['ENAME'],
                    'name'       => 'OWServer (' . $Device['ENAME'] . ')',
                    'version'    => $Device['VERS'],
                    'instanceID' => 0
                ];
                $InstanceID = array_search($IPAddress, $IPSDevices);
                if ($InstanceID === false) {
                    $InstanceID = array_search(strtolower($Device['Host']), $IPSDevices);
                    if ($InstanceID !== false) {
                        $AddValue['IPAddress'] = $Device['Host'];
                    }
                }
                if ($InstanceID !== false) {
                    unset($IPSDevices[$InstanceID]);
                    $AddValue['name'] = IPS_GetLocation($InstanceID);
                    $AddValue['instanceID'] = $InstanceID;
                }

                $AddValue['create'] = [
                    [
                        'moduleID'      => '{D07693A2-5469-47B9-5AFD-9658DF201D32}',
                        'configuration' => new stdClass()
                    ],
                    [
                        'moduleID'      => '{CFDFE5FC-C6AE-F6EB-D449-89A2A1F25794}',
                        'configuration' => [
                            'Webport' => (int) $Device['JSON']
                        ]
                    ],
                    [
                        'moduleID'      => '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}',
                        'configuration' => [
                            'Host' => $AddValue['IPAddress'],
                            'Port' => 4304
                        ]
                    ]
                ];
                $Values[] = $AddValue;
            }

            foreach ($IPSDevices as $InstanceID => $IPAddress) {
                $Values[] = [
                    'IPAddress'  => $IPAddress,
                    'version'    => '',
                    'servername' => '',
                    'name'       => IPS_GetLocation($InstanceID),
                    'instanceID' => $InstanceID
                ];
            }
            $Form['actions'][1]['values'] = $Values;
            $this->SendDebug('FORM', json_encode($Form), 0);
            $this->SendDebug('FORM', json_last_error_msg(), 0);
            return json_encode($Form);
        }

        private function GetIPSInstances(): array
        {
            $InstanceIDList = IPS_GetInstanceListByModuleID('{D07693A2-5469-47B9-5AFD-9658DF201D32}');
            $Devices = [];
            foreach ($InstanceIDList as $InstanceID) {
                $Splitter = IPS_GetInstance($InstanceID)['ConnectionID'];
                if ($Splitter > 0) {
                    $IO = IPS_GetInstance($Splitter)['ConnectionID'];
                    if ($IO > 0) {
                        $Devices[$InstanceID] = strtolower(IPS_GetProperty($IO, 'Host'));
                    }
                }
            }
            $this->SendDebug('IPS Devices', $Devices, 0);
            return $Devices;
        }

        private function DiscoverDevices(): array
        {
            $this->LogMessage($this->Translate('Background discovery of OWServers'), KL_NOTIFY);
            $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if (!$socket) {
                return [];
            }
            socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
            socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
            socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 100000]);
            socket_bind($socket, '0.0.0.0', 0);
            $message = "";
            $this->SendDebug('Search', $message, 1);
            if (@socket_sendto($socket, $message, strlen($message), 0, '255.255.255.255', 4304) === false) {
                return [];
            }
            usleep(100000);
            $i = 50;
            $buf = '';
            $IPAddress = '';
            $Port = 0;
            $DeviceData = [];
            while ($i) {
                $ret = @socket_recvfrom($socket, $buf, 2048, 0, $IPAddress, $Port);
                if ($ret === false) {
                    break;
                }
                if ($ret === 0) {
                    $i--;
                    continue;
                }
                $this->SendDebug('Receive', $buf, 0);
                $Search = ['UUID', 'ENAME', 'VERS', 'JSON'];
                foreach ($Search as $Key) {
                    $start = strpos($buf, $Key);
                    if ($start !== false) {
                        $DeviceData[$IPAddress][$Key] = substr($buf, $start + strlen($Key) + 1, ord($buf[$start + strlen($Key)]));
                    }
                }
                $DeviceData[$IPAddress]['Host'] = gethostbyaddr($IPAddress);
            }
            socket_close($socket);
            $this->SendDebug('Found', $DeviceData, 0);
            return $DeviceData;
        }

	}