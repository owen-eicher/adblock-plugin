<body>
<?php
if (! isset($_POST["block-status"])) { 
	$text = file_get_contents("public/public.html");
	echo $text;
} 
else {
	echo "Form submitted successfully<br>";
	require_once __DIR__ . '/vendor/autoload.php';
	$api = \Ubnt\UcrmPluginSdk\Service\UcrmApi::create();
	$security = \Ubnt\UcrmPluginSdk\Service\UcrmSecurity::create();
	$user = $security->getUser();
	$id = $user->clientId;
	echo "Client ID: " . $id;
	$fname = "data/responses.json";
	if (file_exists($fname)){
		echo "<br>Found file. Printing Contents ...<br>";
		$text = file_get_contents($fname);
		$data = json_decode($text, true);
		if (strcmp($_POST["block-status"], "yes") == 0) {
			$is_set = false;
			foreach(array_keys($data) as $key){
				if ($data[$key] == $id){
					$is_set = true;
					break;
				}
			}
			if (! $is_set){
				array_push($data, $id);
			}
		} else {
			$keys = array_keys($data);
			foreach ($keys as $key){
				if ($data[$key] == $id){
					unset($data[$key]);
					break;
				}
			}
		}
		$file = fopen($fname, "w") or die("Cannot write to this file");
		fwrite($file, json_encode($data));
		fclose($file);
		echo htmlspecialchars(file_get_contents($fname));
	}
	else {
		echo "<br> Response file has not been created yet";
	}
}?>
</body>
