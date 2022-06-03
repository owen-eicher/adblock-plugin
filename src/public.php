<?php
require_once __DIR__ . '/vendor/autoload.php';
$api = \Ubnt\UcrmPluginSdk\Service\UcrmApi::create();
$configManager = \Ubnt\UcrmPluginSdk\Service\PluginConfigManager::create();
$config = $configManager->loadConfig();
if (! isset($_POST["block-status"])) { 
	$cost = floatval($api->get('surcharges/'.$config['surcharge-id'])['priceMonth1']);
	$cost = round($cost, 2);
	$text='
<body>
<p style="color: blue;">
TNT Tech offers network-wide advertisement blocking services for all customers. This service is completely optional, and you may change your selection at any time. It may take up to 30 minutes for changes to take effect.
</p>

<form action="public.php" method="post">
        <input id="yes" type="radio" name="block-status" value="yes">
        <label for="yes">Yes, I want to block ads</label><br>
        <input id="no" type="radio" name="block-status" value="no">
        <label for="no">No, I do not want to block ads</label><br>
        <input type="submit" value="submit">
</form>
</body>';
	echo $text;

} 
else {
	echo "<body>\nForm submitted. [Display Message here]<br>";
	$security = \Ubnt\UcrmPluginSdk\Service\UcrmSecurity::create();
	$user = $security->getUser();
	$id = $user->clientId;
	$fname = "data/responses.json";
	if (file_exists($fname)){
		$text = file_get_contents($fname);
		$data = json_decode($text, true);
		if (strcmp($_POST["block-status"], "yes") == 0) {
			$data[$id] = true;
		} else {
			$data[$id] = false;
		}
		$file = fopen($fname, "w");
		fwrite($file, json_encode($data));
		fclose($file);
	}
	echo "\n</body>";
}?>
