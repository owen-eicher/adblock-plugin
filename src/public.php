<?php
if (! isset($_POST["block-status"])) { 
	$text = file_get_contents("public/public.html");
	echo $text;
} 
else {
	echo "<body>\nForm submitted. [Display Message here]<br>";
	require_once __DIR__ . '/vendor/autoload.php';
	$api = \Ubnt\UcrmPluginSdk\Service\UcrmApi::create();
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
