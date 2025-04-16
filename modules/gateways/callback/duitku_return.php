<?php

require "../../../init.php";
$whmcs->load_function("gateway");
$whmcs->load_function("invoice");

$gatewayParams = getGatewayVariables('duitkupop');

if (empty($_REQUEST['resultCode']) || empty($_REQUEST['merchantOrderId']) || empty($_REQUEST['reference'])) {
	error_log('wrong query string please contact admin.');
	exit;
}

$apikey = $gatewayParams['apikey'];
$endpoint = $gatewayParams['endpoint'];
$merchant_code = $gatewayParams['merchantcode'];
$order_id = stripslashes($_REQUEST['merchantOrderId']);
$status = stripslashes($_REQUEST['resultCode']);
$reference = stripslashes($_REQUEST['reference']);
$urlStat = $endpoint.'/api/merchant/transactionStatus';
$signature = md5($merchant_code . $order_id . $apikey);
$params = array(
	'merchantCode' => $merchant_code,
	'merchantOrderId' => $order_id,
	'signature' => $signature
);

if (extension_loaded('curl')) {
	try{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json'
		));
		curl_setopt($ch, CURLOPT_URL, $urlStat);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
		// Receive server response ...
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$server_output = curl_exec($ch);
		curl_close ($ch);
		$respond = json_decode($server_output);
	}
	catch (Exception $e) {
		echo $e->getMessage();
	}
}else{
	throw new Exception("Duitku payment need curl extension, please enable curl extension in your web server");
}

if ($respond->statusCode == '00') {
	addInvoicePayment(
		$respond->merchantOrderId,
		$respond->reference,
		$respond->amount,
		$respond->fee,
		"duitkupop"
	);
	$url = "/viewinvoice.php?id=" . $respond->merchantOrderId . "&paymentsuccess=true";
	echo '<script>
	var base_url = window.location.href;
	var idx = base_url.lastIndexOf("/modules/");
	var base_url = base_url.substr(0,idx);
	window.location = base_url+"'.$url.'";
	</script>';
}else if ($respond->statusCode == '01') {
	$url = "/viewinvoice.php?id=" . $respond->merchantOrderId;
}else {
	$url = "/viewinvoice.php?id=" . $respond->merchantOrderId . "&paymentfailed=true";
}

echo '<script>
var base_url = window.location.href;
var idx = base_url.lastIndexOf("/modules/");
var base_url = base_url.substr(0,idx);
window.location = base_url+"'.$url.'";
</script>';
die();
