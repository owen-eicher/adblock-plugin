<?php
$log_file = "data/plugin.log";
function log_mess($message){
	global $log_file;
	$file = fopen($log_file, "a");
	fwrite($file, $message . "\n");
	fclose($file);
}

// Clear log on each run
$temp = fopen($log_file, "w");
fclose($temp);
log_mess("Log File cleared");

require_once __DIR__ . '/vendor/autoload.php';
try {
	$api = \Ubnt\UcrmPluginSdk\Service\UcrmApi::create();
	log_mess("UCRM API Instance Created");
	$config = json_decode(file_get_contents("data/config.json"), true);
	$token = $config["nms-api-token"];
	$nmsapi = \Ubnt\UcrmPluginSdk\Service\UnmsApi::create($token);
	log_mess("NMS API Instance Created");

	// Assert that the response file exists
	$fname = "data/responses.json";
	if (!file_exists($fname)) {
		log_mess("Creating responses file ...");
		$file = fopen($fname, "w") or log_mess("Cannot create file");
		$json = array();
		$clients = $api->get("clients");
		foreach($clients as $client){
			$json[$client["id"]] = false;
		}
		fwrite($file, json_encode($json));
		fclose($file);	
		log_mess("Created file successfully");
	} 
	log_mess("Loading Response File");
	$json = file_get_contents($fname);
	$data = json_decode($json, true);
	$sites = $nmsapi->get('sites');
	log_mess("Gathered Sites");
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
	log_mess("Gathered siteIds from sites");
	$ips = array();
	$noIps = array();
	$gateway = false;
	$devices = $nmsapi->get('devices');
	foreach($devices as $device){
		$id = $device["identification"]["site"]["id"];
		$ip = $device["ipAddress"];
		$pos = strpos($ip, '/');
		if ($pos !== false) {
			$ip = substr($ip, 0, $pos);
		}	
		if (in_array($id, $siteids)) {
			array_push($ips, $ip);
		} else {
			array_push($noIps, $ip);
		}
		if (! $gateway and strcmp($device["identification"]["role"], "gateway") === 0) {
			$gateway = $device["identification"]["id"];
		}
	}
	log_mess("Gathered ips to transfer");

	// Gather Configuration Information
	$groupName = $config["group-name"];
	$ruleNum = $config["destination-rule-number"];
	$sRuleNum = $config["source-rule-number"];
	$userName = $config["user-name"];
	$inAddress = $config["inside-address"];

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
var_dump(file_get_contents("data/plugin.log"));
