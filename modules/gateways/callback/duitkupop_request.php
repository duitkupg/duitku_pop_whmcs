<?php

require "../../../init.php";
$whmcs->load_function("gateway");
$whmcs->load_function("invoice");

// Get the raw POST data from the request
$rawData = file_get_contents("php://input");

// Decode the JSON data
$data = json_decode($rawData, true);

// Set the header to indicate we're response JSON
header('Content-Type: application/json');
// Prepare a response array
$response = array();

// Check if the data is valid
if (empty($data) || empty($data['paymentName']) || empty($data['clientid']) || empty($data['invoiceid']) || empty($data['currency']) || empty($data['description']) || empty($data['signature']))
{
    logTransaction("Duitkupop", json_encode($data, JSON_PRETTY_PRINT), "Failed before requesting to Duitku, Empty fetch data.");
	logActivity("wrong post data, missing request data.", $data['clientid']);
    $response["error"] = "Failed before requesting to Duitku, Empty fetch data.";
    http_response_code(400);
    echo json_encode($response);
    exit();
}

//get config data
$paymentName = $data['paymentName'];
$config = getGatewayVariables($paymentName);

//get invoice data
$invoiceid = $data['invoiceid'];
$command = 'GetInvoice';
$postData = array(
    'invoiceid' => $invoiceid,
);
$invoice = localAPI($command, $postData);

//get client data
$clientId = $data['clientid'];
$command = 'GetClientsDetails';
$postData = array(
    'clientid' => $clientId,
);
$clientDetails = localAPI($command, $postData);

//validate signature
$signature = hash("sha256", $config['merchantcode'] . $clientId . $invoiceid . $data['description'] . $config['apikey']);
if($signature !== $data['signature']){
    logTransaction("Duitkupop", $signature . " not equal with " .  $data['signature'], "Failed before requesting to Duitku, invalid whmcs signature.");
	logActivity("Failed before requesting to Duitku, invalid whmcs signature", $clientId);
    $response["error"] = "Failed before requesting to Duitku, invalid whmcs signature.";
    http_response_code(403);
    echo json_encode($response);
    exit();
}

//cek configuration
if (empty($config['merchantcode']) || empty($config['apikey']) || empty($config['environment'])) {
    logTransaction($paymentName, json_encode($config, JSON_PRETTY_PRINT), "Invalid Configuration for " . $paymentName . ".");
	logActivity("Invalid Configuration for " . $paymentName . ".", $clientId);
    $response["error"] = "Pluggin Invalid Configuration for " . $paymentName . ".";
    http_response_code(403);
    echo json_encode($response);
    die();
}


//check if currency not IDR
$currency = $data['currency'];
$rate = 1;
if ($currency != 'IDR') {
    $currencyCurrent = mysql_fetch_assoc(select_query('tblcurrencies', 'code, rate', array("code"=>$currency)));
    $currencyDefault = mysql_fetch_assoc(select_query('tblcurrencies', 'code, rate', array("id"=>'1')));

    //Check Default Currency
    if ($currencyDefault['code'] != 'IDR'){ 
        logTransaction($paymentName, $currency, "Default currency is not IDR");
        logActivity("Default currency is not IDR, please set default currencies to IDR to recieve payment from Duitku.", $clientId);
        $response["error"] = "Default currency is not IDR, please set default currencies to IDR to recieve payment from Duitku.";
        http_response_code(403);
        echo json_encode($response);
        die();
    }

    $rate = $currencyCurrent['rate'];
    logActivity("Checkout with rate " . $rate, $clientId);
}

//set endpoint duitku
$endpoint = '';
if ($config['environment'] == "sandbox") {
    $endpoint = 'https://api-sandbox.duitku.com';
} elseif ($config['environment'] == "production") {
    $endpoint = 'https://api-prod.duitku.com';
}
$url = $endpoint . '/api/merchant/createInvoice';

//set headers duitku
$merchantCode = $config['merchantcode'];
$apikey = $config['apikey'];
$timestamp = round(microtime(true) * 1000); //in milisecond
$headerSignature = hash('sha256', $merchantCode . $timestamp . $apikey);
$headers = array(
    'Content-Type: application/json',
    'x-duitku-signature: ' . $headerSignature,
    'x-duitku-timestamp: ' . $timestamp,
    'x-duitku-merchantCode: ' . $merchantCode
);

//set parameter for duitku
$paymentAmount = (int)ceil($invoice['total']);
if ($currency != 'IDR') {
    $paymentAmount = (int)ceil($invoice['total'] / $rate); //set value idr if not using idr
}
$merchantOrderId = $invoice['invoiceid'];
$productDetails = $data['description'];
$additionalParam = $data['currency'];
$callbackUrl = $config['systemurl'] . "/modules/gateways/callback/duitkupop_callback.php";
$returnUrl = $config['systemurl'] . "/modules/gateways/callback/duitkupop_return.php?clientid=" . $clientId;

//customer data
$email = $clientDetails['email'];
$merchantUserInfo = $clientDetails['fullname'];
$customerVaName = $clientDetails['fullname'];
$phoneNumber = $clientDetails['phonenumber'];
$address = array(
    'firstName' => $clientDetails['firstname'],
    'lastName' => $clientDetails['lastname'],
    'address' => $clientDetails['address1'] . " " . $clientDetails['address2'],
    'city' => $clientDetails['city'],
    'postalCode' => $clientDetails['postcode'],
    'phone' => $phoneNumber,
    'countryCode' => $clientDetails['countrycode']
);
$customerDetail = array(
    'firstName' => $clientDetails['firstname'], 
    'lastName' =>$clientDetails['lastname'], 
    'email' => $email, 
    'phoneNumber' => $phoneNumber,
    'billingAddress' => $address,
    'shippingAddress' => $address
 );

//items
$itemDetails = array();
if ($currency != 'IDR') {
    foreach ($invoice['items']['item'] as $item) {
        $itemDetails[] = array(
            'name' => $item['description'],
            'price' => (int)ceil($item['amount'] / $rate),//set value idr if not using idr
            'quantity' => 1
        );
    }
}else{
    foreach ($invoice['items']['item'] as $item) {
        $itemDetails[] = array(
            'name' => $item['description'],
            'price' => (int)ceil($item['amount']),
            'quantity' => 1
        );
    }
}

//params
$payload = array(
    'paymentAmount' => $paymentAmount,
    'merchantOrderId' => $merchantOrderId . "",
    'productDetails' => $productDetails,
    'additionalParam' => $additionalParam,
    'merchantUserInfo' => $merchantUserInfo,
    'customerVaName' => $customerVaName,
    'email' => $email,
    'phoneNumber' => $phoneNumber,
    'itemDetails' => $itemDetails,
    'customerDetail' => $customerDetail,
    'callbackUrl' => $callbackUrl,
    'returnUrl' => $returnUrl
);

$calculateTotalItems = 0;
foreach ($payload['itemDetails'] as $item) {
    $calculateTotalItems = $calculateTotalItems + $item['price'];
}

//check for amount differences
if($payload['paymentAmount'] != $calculateTotalItems){
    logTransaction($paymentName, $payload['paymentAmount'] . " not equal with " .  $calculateTotalItems, "Failed exchange to IDR.");
    logActivity("Failed exchange to IDR, seems you have complicated calculation transaction.", $clientId);
    $response["error"] = "Failed exchange to IDR.";
    http_response_code(400);
    echo json_encode($response);
    die();
}

// logTransaction($paymentName, $url . '<br/>' . json_encode($headers, JSON_PRETTY_PRINT) . '<br/>' . json_encode($payload, JSON_PRETTY_PRINT), "debug request duitku.");

if (extension_loaded('curl')) {
    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        // Receive server response ...
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $server_output = curl_exec($ch);
        $server_error = curl_error($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (!empty($server_error)) {
            echo $server_error;
        }
        curl_close($ch);
        $respond = json_decode($server_output);
        logModuleCall($paymentName, "Create Invoice for order id " . (string)$payload['merchantOrderId'], json_encode(array(
            'url' => $url,
            'headers' => $headers,
            'payload' => $payload
        ), JSON_PRETTY_PRINT), json_encode($respond, JSON_PRETTY_PRINT), "");

        if ($respond->statusCode == '00') {
            $response['duitkuData'] = $respond;
        } else {
            $messages = json_encode($server_output);
            logActivity("Duitku Error: " . $messages . ".", $clientId);
            $response["error"] = $messages;
            http_response_code($httpcode);
            echo json_encode($response);
            die();
        }
    } catch (Exception $e) {
        logTransaction($paymentName, $e->getMessage(), "Unknown Error");
        logActivity($e->getMessage(), $clientId);
        $response["error"] = $e->getMessage();
        http_response_code(500);
        echo json_encode($response);
        die();
    }
} else {
    logTransaction($paymentName, "", "Duitku payment need curl extension, please enable curl extension in your web server");
    logActivity("Duitku payment need curl extension, please enable curl extension in your web server", $clientId);
    $response["error"] = "Duitku payment need curl extension, please enable curl extension in your web server";
    http_response_code(500);
    echo json_encode($response);
    die();
}


// Return the response as JSON
http_response_code(200);
echo json_encode($response);
die();