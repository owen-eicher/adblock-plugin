<?php
try {
	require_once __DIR__ . '/vendor/autoload.php';
	$configManager = \Ubnt\UcrmPluginSdk\Service\PluginConfigManager::create();
	$config = $configManager->loadConfig();
	$log = \Ubnt\UcrmPluginSdk\Service\PluginLogManager::create();
	$log->clearLog();
	function log_mess($message){
		global $log;
		$log->appendLog($message);
	}

	log_mess(shell_exec("date"));

	$api = \Ubnt\UcrmPluginSdk\Service\UcrmApi::create();
	$token = $config["nms-api-token"];
	$nmsapi = \Ubnt\UcrmPluginSdk\Service\UnmsApi::create($token);

	// Assert that the response file exists
	$fname = "data/responses.json";
	if (!file_exists($fname)) {
		$file = fopen($fname, "w") or log_mess("Cannot create file");
		$json = array();
		$clients = $api->get("clients");
		foreach($clients as $client){
			$json[$client["id"]] = false;
		}
		fwrite($file, json_encode($json));
		fclose($file);	
	} 

	// Update Adblock Surcharge
	$json = file_get_contents($fname);
	$data = json_decode($json, true);
	$surchargeId = intval($config['surcharge-id']);
	foreach(array_keys($data) as $clientId){
		$services = $api->get('clients/services',['clientId' => $clientId]);
		$instanceId = -1;
		// Determine if surcharge has been added
		foreach($services as $service){
			$surcharges = $api->get('clients/services/'.$service['id'].'/service-surcharges');
			foreach($surcharges as $charge){
				if($charge['surchargeId'] === $surchargeId){
					$instanceId = $charge['id'];
					break;
				}
			}
			if ($instanceId != -1){
				break;
			}
		}
		if($data[$clientId]){
			// Add surcharge if it doesn't already exist
			if ($instanceId == -1 and isset($services[0])){
				$api->post('clients/services/'.$services[0]['id'].'/service-surcharges', ['surchargeId'=>$surchargeId]);	
			}
		} elseif ($instanceId != -1){
			$api->delete('clients/services/service-surcharges/'.$instanceId);
		}
	}


	// Gather Client Site Ids from Client Ids
	$sites = $nmsapi->get('sites');
	$siteids = array();
	foreach($sites as $site){
		$ucrm = $site["ucrm"];
		if (!is_null($ucrm)){
			$id = intval($ucrm["client"]["id"]);
			if ($data[$id]){
				$siteId = $site["id"];
				array_push($siteids, $siteId);
			}
		}
	}

	// Gather IP Addresses Associated With Client Sites
	$ips = array();
	$noIps = array();
	$devices = $nmsapi->get('devices');
	foreach($devices as $device){
		$id = $device["identification"]["site"]["id"];
		$ip = $device["ipAddress"];
		if (strlen($ip) == 0) {
			continue;
		}
		$pos = strpos($ip, '/');
		if ($pos !== false) {
			$ip = substr($ip, 0, $pos);
		}	
		if (in_array($id, $siteids)) {
			if (!in_array($ip, $ips)){
				array_push($ips, $ip);
				if (($key = array_search($ip, $noIps)) !== false){
					unset($noIps[$key]);
				}
			}
		} elseif (!in_array($ip, $ips) and !in_array($ip, $noIps)) {
			array_push($noIps, $ip);
		}
	}

	// Gather Configuration Information
	$groupName = $config["group-name"];
	$ruleNum = $config["destination-rule-number"];
	$sRuleNum = $config["source-rule-number"];
	$userName = $config["user-name"];
	$inAddress = $config["inside-address"];
	$gateway = $config["gateway-id"];

	// Create vars.yml for Ansible Execution
	$fname = "data/vars.yml";
	$file = fopen($fname, 'w');
	fwrite($file, "---\n");
	fwrite($file, "group_name: ".$groupName."\n");
	fwrite($file, "backup_path: data/backups\n");
	fwrite($file, "inside_address: ".$inAddress."\n");
	fwrite($file, "plugin_user: ".$userName."\n");

	// Write Opt-In IPs
	fwrite($file, "ips:\n");
	foreach ($ips as $ip){
		fwrite($file, "  - ".$ip."\n");
	}
	// Write Opt-Out IPs
	fwrite($file, "no_ips:\n");
	foreach ($noIps as $ip){
		fwrite($file, "  - ".$ip."\n");
	}

	// Write interface variables for NAT rule creation	
	$detail = $nmsapi->get('devices/'.$gateway.'/detail');
	$interfaces = array();
	fwrite($file, "interfaces:\n");
	foreach($detail['interfaces'] as $interface){
		$name = $interface['identification']['name'];
		fwrite($file, "  - { num: ".$ruleNum++.", val: ".$name." }\n");
		$interfaces[$sRuleNum++] = $name;
	}
	fwrite($file, "source_interfaces:\n");
	foreach(array_keys($interfaces) as $num){
		fwrite($file, "  - { num: ".$num.", val: ".$interfaces[$num]." }\n");
	}
	fclose($file);
} catch(Exception $e){
	log_mess("Exception: " . $e->getMessage());
}
