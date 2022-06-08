<?php
require_once __DIR__ . '/vendor/autoload.php';
$api = \Ubnt\UcrmPluginSdk\Service\UcrmApi::create();
$configManager = \Ubnt\UcrmPluginSdk\Service\PluginConfigManager::create();
$config = $configManager->loadConfig();
if (! isset($_POST["block-status"])) { 
	$cost = floatval($api->get('surcharges/'.$config['surcharge-id'])['priceMonth1']);
	$cost = number_format((float)$cost, 2, '.', '');
	$text='
<!DOCTYPE html>
<html style="font-size: 16px;">
  <head>
    <title>Home</title>
    <link rel="stylesheet" href="public/index.css" media="screen">
<link rel="stylesheet" href="Home.css" media="screen">

    <link id="u-theme-google-font" rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:100,100i,300,300i,400,400i,500,500i,700,700i,900,900i|Open+Sans:300,300i,400,400i,500,500i,600,600i,700,700i,800,800i">
    
  </head>
  <body data-home-page="Home.html" data-home-page-title="Home" class="u-body u-xl-mode"><header class="u-clearfix u-header u-header" id="sec-7835"><div class="u-align-left u-clearfix u-sheet u-sheet-1"></div></header>
    <section class="u-align-center u-clearfix u-section-1" id="sec-36ba">
      <div class="u-align-center u-clearfix u-sheet u-sheet-1">
        <h2 class="u-text u-text-default u-text-1">TNT Advertisement Blocking</h2>
        <p class="u-text u-text-2"> TNT Tech offers optional network-wide advertisement blocking services for all customers which can be toggled on/off using the buttons below. Enabling this option will block the majority of internet advertisements on your entire network at a cost of $'.$cost.' per month. You may continue to see some advertisements, such as those embedded in videos<br>
        </p>
                <hr><br><br>

                <form action="public.php" method="post">
                        <input id="yes" type="radio" name="block-status" value="yes">
                        <label for="yes"> Yes, I want to block ads for '.$cost.'/month</label><br>
                        <input id="no" type="radio" name="block-status" value="no">
                        <label for="no"> No, I do not want to block ads </label><br>
                </form>
      </div>
    </section>
  </body>
</html>
';
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
