<?php

require "../../../init.php";
$whmcs->load_function("gateway");
$whmcs->load_function("invoice");

$gatewayParams = getGatewayVariables('duitkupop');

if (empty($_REQUEST['resultCode']) || empty($_REQUEST['merchantOrderId']) || empty($_REQUEST['reference'])) {
	error_log('wrong query string please contact admin.');
	logActivity('duitku plugin error: wrong query string please contact admin.', 0);
	echo "duitku plugin error: wrong query string please contact admin.";
	exit;
}

$apikey = $gatewayParams['apikey'];
$pluginstatus = $gatewayParams['pluginstatus'];
$merchant_code = $gatewayParams['merchantcode'];
if ($pluginstatus == "sandbox") {
  $endpoint = 'https://api-sandbox.duitku.com';
} elseif ($pluginstatus == "production") {
  $endpoint = 'https://api-prod.duitku.com';
}
$order_id = stripslashes($_REQUEST['merchantOrderId']);
$amount = stripslashes($_REQUEST['amount']);
$status = stripslashes($_REQUEST['resultCode']);
$reference = stripslashes($_REQUEST['reference']);
$calSignature = stripslashes($_REQUEST['signature']);

$calStringSign = $merchant_code . $amount . $order_id . $apikey;
$calSign = md5($calStringSign);

if($calSignature !== $calSign){
	$orgipn = "";
	foreach ($_POST as $key => $value) {
		$orgipn.= ("" . $key . " => " . $value . "\r\n");
	}
	logTransaction($gatewayModuleName, $orgipn, "Duitku Signature Invalid");
	logActivity('duitku error: Callback Signature Invalid.', 0);
	error_log('Callback Signature Invalid.');
	echo "Duitku callback invalid";
	exit;
}

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
		logModuleCall("duitkupop", "Check Transaction", json_encode($params), $server_output, json_encode($respond), array());
	}
	catch (Exception $e) {
		error_log($e->getMessage());
		logModuleCall("duitkupop", "Check Transaction", json_encode($params), $e->getMessage(), json_encode($e), array());
		echo "Duitku callback invalid";
		exit;
	}
}else{
	error_log('Duitku payment need curl extension, please enable curl extension in your web server.');
	logActivity('Duitku payment need curl extension, please enable curl extension in your web server.', 0);
	echo "Duitku callback invalid";
	exit;
}
checkCbTransID($respond->reference);

if ($respond->statusCode == '00') {
	addInvoicePayment(
		$respond->merchantOrderId,
		$respond->reference,
		$respond->amount,
		$respond->fee,
		"duitkupop"
	);
	logActivity('duitku notification accepted: Payment success order ' . $respond->merchantOrderId . '.', 0);
    echo "Payment success notification accepted";
}else {
    $orgipn = "";
	foreach ($_POST as $key => $value) {
		$orgipn.= ("" . $key . " => " . $value . "\r\n");
	}
	logActivity('Duitku Handshake Invalid.', 0);
	logTransaction($gatewayModuleName, $orgipn, "Duitku Handshake Invalid");
	echo "Duitku Handshake Invalid";
	header("HTTP/1.0 200 OK");
}

die();
