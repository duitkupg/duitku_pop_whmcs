<?php

require "../../../init.php";
$whmcs->load_function("gateway");
$whmcs->load_function("invoice");

$gatewayParams = getGatewayVariables('duitkupop');

if (!$gatewayParams['type']) {
	logTransaction('duitkupop', json_encode($_POST, JSON_PRETTY_PRINT), "Callback failed, Module duitkupop not active.");
	header("HTTP/1.0 200 OK");
	exit;
}

if (empty($_POST['resultCode']) || empty($_POST['merchantOrderId']) || empty($_POST['reference'])) {
	logTransaction('duitkupop', json_encode($_POST, JSON_PRETTY_PRINT), "Callback failed, param resultCode, merchantOrderId, or reference is empty.");
	header("HTTP/1.0 200 OK");
	exit;
}

//prepare parameter for inquiry status
$apikey = $gatewayParams['apikey'];
$environment = $gatewayParams['environment'];
$merchantCode = $gatewayParams['merchantcode'];
if ($environment == "sandbox") {
  $endpoint = 'https://api-sandbox.duitku.com';
} elseif ($environment == "production") {
  $endpoint = 'https://api-prod.duitku.com';
}
$orderId = stripslashes($_POST['merchantOrderId']);
$amount = stripslashes($_POST['amount']);
$status = stripslashes($_POST['resultCode']);
$reference = stripslashes($_POST['reference']);
$callbackSignature = stripslashes($_POST['signature']);

//validate incoming signature
$validationSignature = md5($merchantCode . $amount . $orderId . $apikey);
if($callbackSignature !== $validationSignature){
	logTransaction("duitkupop", $callbackSignature . " not equal with " .  $validationSignature, "Duitku Callback Signature Invalid");
	header("HTTP/1.0 200 OK");
	exit;
}

//check current currency 
$additionalParam = stripslashes($_POST['additionalParam']);
$currencyCurrent = mysql_fetch_assoc(select_query('tblcurrencies', 'code, rate', array("code"=>$additionalParam)));
if ($currencyCurrent['code'] != 'IDR'){
	$currencyDefault = mysql_fetch_assoc(select_query('tblcurrencies', 'code, rate', array("id"=>'1')));
	
	//Check Default Currency
	if ($currencyDefault['code'] != 'IDR'){
		logTransaction("duitkupop", json_encode($currencyDefault, JSON_PRETTY_PRINT), "Callback failed, Default currency is not IDR.");
		header("HTTP/1.0 200 OK");
		exit;
	}
	
	$amount = $amount * $currencyCurrent['rate'];
}

//check with duitku status
$urlStat = $endpoint.'/api/merchant/transactionStatus';
$signature = md5($merchantCode . $orderId . $apikey);
$params = array(
	'merchantCode' => $merchantCode,
	'merchantOrderId' => $orderId,
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
		logTransaction("duitkupop", json_encode($server_output, JSON_PRETTY_PRINT), "Check Transaction for order id " . $orderId);
		logModuleCall("duitkupop", "Check Transaction for order id " . $orderId, json_encode($params, JSON_PRETTY_PRINT), json_encode($server_output, JSON_PRETTY_PRINT), json_encode($respond, JSON_PRETTY_PRINT), array());
	}
	catch (Exception $e) {
		logTransaction("duitkupop", $e->getMessage(), "Check Transaction for order id " . $orderId);
		logModuleCall("duitkupop", "Check Transaction for order id " . $orderId, json_encode($params, JSON_PRETTY_PRINT), $e->getMessage(), json_encode($e, JSON_PRETTY_PRINT), array());
		header("HTTP/1.0 200 OK");
		exit;
	}
}else{
	logTransaction("duitkupop", "Duitku payment need curl extension, please enable curl extension in your web server.", "Duitku Callback Signature Invalid");
	logModuleCall("duitkupop", "Callback Transaction for " . strtoupper($reference), json_encode($_POST, JSON_PRETTY_PRINT), "WHMCS error with curl.", "");
	header("HTTP/1.0 200 OK");
	exit;
}

$invoiceId = checkCbInvoiceID($orderId, 'duitkupop');
checkCbTransID($respond->reference);

if ($respond->statusCode == '00') {
	addInvoicePayment(
		$respond->merchantOrderId,
		$respond->reference,
		$respond->amount,
		$respond->fee,
		"duitkupop"
	);
	logTransaction('duitkupop', json_encode($_POST, JSON_PRETTY_PRINT), "Callback finish, Payment success validated.");
	logModuleCall('duitkupop', "Callback Transaction for " . strtoupper($reference), json_encode($_POST, JSON_PRETTY_PRINT), "Payment success notification accepted", "");
}else {
	logTransaction('duitkupop', json_encode($_POST, JSON_PRETTY_PRINT), "Duitku Handshake Invalid");
	logModuleCall('duitkupop', "Callback Transaction for " . strtoupper($reference), json_encode($_POST, JSON_PRETTY_PRINT), "Duitku Handshake Invalid", "");
}

header("HTTP/1.0 200 OK");
die();
