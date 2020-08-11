<?php
	class OWServerSplitter extends IPSModule {

		public function Create()
		{
			//Never delete this line!
			parent::Create();

			$this->RequireParent("{8062CF2B-600E-41D6-AD4B-1BA66C32D6ED}");
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
		}

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

	}