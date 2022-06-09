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
	function indent($file, $spaces=24){
		for ($i = 0; $i < $spaces; $i++){
			fwrite($file, ' ');
		}
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
	$yesIps = array();
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
			if (!in_array($ip, $yesIps)){
				array_push($yesIps, $ip);
				if (($key = array_search($ip, $noIps)) !== false){
					unset($noIps[$key]);
				}
			}
		} elseif (!in_array($ip, $yesIps) and !in_array($ip, $noIps)) {
			array_push($noIps, $ip);
		}
	}

	// Gather Configuration Information
	$groupName = $config["group-name"];
	$ruleNum = $config["destination-rule-number"];
	$sourceRuleNum = $config["source-rule-number"];
	$userName = $config["user-name"];
	$inAddress = $config["inside-address"];
	$gatewayId = $config["gateway-id"];

	// Create ansible playbooks
	$playname = "ips.yml";
	$playbook = fopen($playname, 'w');

	$rmName = "rmAllIps.yml";
	$rmIpFile = fopen($rmName, 'w');

	$ruleName = "rules.yml";
	$ruleFile = fopen($ruleName, 'w');

	$rmrulename = "rmrules.yml";
	$rmrulefile = fopen($rmrulename, 'w');

	// Create vars.yml for Ansible Execution
	$fname = "data/vars.yml";
	$file = fopen($fname, 'w');
	fwrite($file, "---\n");
	fwrite($file, "group_name: ".$groupName."\n");
	fwrite($file, "backup_path: data/backups\n");
	fwrite($file, "inside_address: ".$inAddress."\n");
	fwrite($file, "plugin_user: ".$userName."\n");

	// Write Opt-In IPs
	fwrite($playbook, file_get_contents("ips.temp")."\n");
	fwrite($rmIpFile, file_get_contents("rmAllIps.temp"));
	foreach ($yesIps as $ip){
		indent($playbook,$spaces);
		indent($rmIpFile, $spaces);
		fwrite($playbook, "- set firewall group address-group {{ group_name }} address ".$ip."\n");
		fwrite($rmIpFile, "- delete firewall group address-group {{ group_name }} address ".$ip."\n");
	}
	if (count($yesIps) == 0){
		indent($playbook,$spaces);
		fwrite($playbook, "- set firewall\n");
	}

	// Write Opt-Out IPs
	fwrite($playbook, "\n".file_get_contents("rmips.temp"));
	foreach ($noIps as $ip){
		indent($playbook);
		fwrite($playbook, "- delete firewall group address-group {{ group_name }} address ".$ip."\n");
		indent($rmIpFile);
		fwrite($rmIpFile, "- delete firewall group address-group {{ group_name }} address ".$ip."\n");
	}
	if (count($noIps) == 0){
		indent($playbook);
		fwrite($playbook, "- set firewall\n");
	}

	// Write interface variables for NAT rule creation	
	$detail = $nmsapi->get('devices/'.$gatewayId.'/detail');
	$interfaces = array();
	#fwrite($file, "interfaces:\n");
	fwrite($ruleFile, file_get_contents("rules.temp")."\n");
	foreach($detail['interfaces'] as $interface){
		$name = $interface['identification']['name'];
		// fwrite($file, "  - { num: ".$ruleNum++.", val: ".$name." }\n");
		$temp = array(
			"- set service nat rule ".$sourceRuleNum." description 'Ad Block'",
			"- set service nat rule ".$sourceRuleNum." destination address {{ inside_address }}",
			"- set service nat rule ".$sourceRuleNum." destination port 53",
			"- set service nat rule ".$sourceRuleNum." log disable",
			"- set service nat rule ".$sourceRuleNum." protocol tcp_udp",
			"- set service nat rule ".$sourceRuleNum." source group address-group {{ group_name }}",
			"- set service nat rule ".$sourceRuleNum." outbound-interface ".$name,
			"- set service nat rule ".$sourceRuleNum." type masquerade",
			"- set service nat rule ".$ruleNum." description 'Ad Block'",
			"- set service nat rule ".$ruleNum." destination port 53",
			"- set service nat rule ".$ruleNum." inbound-interface ".$name,
			"- set service nat rule ".$ruleNum." inside-address address {{ inside_address }}",
			"- set service nat rule ".$ruleNum." inside-address port 53",
			"- set service nat rule ".$ruleNum." log disable",
			"- set service nat rule ".$ruleNum." protocol tcp_udp",
			"- set service nat rule ".$ruleNum." source group address-group {{ group_name }}",
			"- set service nat rule ".$ruleNum." type destination"
		);

		foreach(array_keys($temp) as $key){
			indent($ruleFile);
			fwrite($ruleFile, $temp[$key]."\n");
		}

		$interfaces[$ruleNum++] = $name;
		$interfaces[$sourceRuleNum++] = $name;
	}
	#fwrite($file, "source_interfaces:\n");
	fwrite($rmrulefile, file_get_contents("rmrules.temp"));
	foreach(array_keys($interfaces) as $num){
		#fwrite($file, "  - { num: ".$num.", val: ".$interfaces[$num]." }\n");
		indent($rmrulefile);
		fwrite($rmrulefile, "- delete service nat rule ".$num."\n");
	}
	fclose($file);
	log_mess(shell_exec("date"));
} catch(Exception $e){
	log_mess("Exception: " . $e->getMessage());
}
