<?php

if (!defined("WHMCS")) {
  die("This file cannot be accessed directly");
}

function duitkupop_MetaData()
{
  return array(
    'DisplayName' => 'Duitku Payment Gateway Module',
    'APIVersion' => '1.0',
    'DisableLocalCredtCardInput' => true,
    'TokenisedStorage' => true,
  );
}

function duitkupop_config()
{
  return array(
    'FriendlyName' => array(
      'Type' => 'System',
      'Value' => 'Duitku Payment',
    ),
    "pluginstatus" => array(
      "FriendlyName" => "Plugin Status",
      "Type" => "dropdown",
      "Options" => array(
        'sandbox' => 'Sandbox',
        'production' => 'Production',
      ),
      "Description" => "Select the plugin usage status",
      "Default" => "sandbox",
    ),
    "uimode" => array(
      "FriendlyName" => "UI Mode",
      "Type" => "dropdown",
      "Options" => array(
        'popup' => 'Popup',
        'redirect' => 'Redirect',
      ),
      "Description" => "Select payment ui mode",
      "Default" => "popup",
    ),
    'merchantcode' => array(
      'FriendlyName' => 'Duitku Merchant Code',
      'Type' => 'text',
      'Size' => '50',
      'Default' => '',
      'Description' => '<br>Input your Merchant code. Get merchant code at duitku.com',
    ),
    'apikey' => array(
      'FriendlyName' => 'Duitku API Key',
      'Type' => 'text',
      'Size' => '100',
      'Default' => '',
      'Description' => '<br>Input your API key. Get api key at duitku.com',
    ),
  );
}

function redirect_payment($url, $statusCode = 303)
{
  header('Location: ' . $url, true, $statusCode);
  die();
}

function duitkupop_link($params)
{
  $pluginVersion = '1.0';
  $merchantcode = $params['merchantcode'];
  $apikey = $params['apikey'];
  $pluginstatus = $params['pluginstatus'];
  $uimode = $params['uimode'];

  if ($pluginstatus == "sandbox") {
    $urllib = 'https://app-sandbox.duitku.com/lib/js/duitku.js';
    $endpoint = 'https://api-sandbox.duitku.com';
  } elseif ($pluginstatus == "production") {
    $urllib = 'https://app-prod.duitku.com/lib/js/duitku.js';
    $endpoint = 'https://api-prod.duitku.com';
  }

  $orderid = $params['invoiceid'];
  $description = $params['description'];
  $amount = $params['amount'];
  $currencycode = $params['currency'];

  $firstname = $params['clientdetails']['firstname'];
  $lastname = $params['clientdetails']['lastname'];
  $email = $params['clientdetails']['email'];
  $address1 = $params['clientdetails']['address1'];
  $address2 = $params['clientdetails']['address2'];
  $city = $params['clientdetails']['city'];
  $state = $params['clientdetails']['state'];
  $postcode = $params['clientdetails']['postcode'];
  $country = $params['clientdetails']['country'];
  $phone = $params['clientdetails']['phonenumber'];

  $companyName = $params['companyname'];
  $systemUrl = $params['systemurl'];
  $moduleDisplayName = $params['name'];
  $moduleName = $params['paymentmethod'];
  $whmcsVersion = $params['whmcsVersion'];
	$description = $params['description'];
	
	$ProducItem = array(
		'name' => $description,
		'price' => (int)ceil($amount),
		'quantity' => 1
	);
	
	$item_details = array($ProducItem);

  //$signature = md5($merchantcode.(string)$orderid.(int)$amount.$apikey);

  $params = array(
    "merchantOrderId" => (string)$orderid,
    "merchantUserInfo" => $email,
    "paymentAmount" => (int)ceil($amount),
    "productDetails" => $description,
    "merchantUserInfo"=> $email,
    "customerVaName"=> $firstname . " " . $lastname,
    "additionalParam" => "",
    "itemDetails" => $item_details,
    "email" => $email,
    "phoneNumber" => $phone,
    "returnUrl" => $systemUrl . "/modules/gateways/callback/duitkupop_return.php",
    "callbackUrl" => $systemUrl . "/modules/gateways/callback/duitkupop_callback.php",
  );

  $customerdetail = array(
    "firstName" => $firstname,
    "lastName" => $lastname,
    "email" => $email,
    "phoneNumber" => $phone,
  );

  $billingaddress = array(
    "firstName" => $firstname,
    "lastName" => $lastname,
    "address" => $address1 . ' ' . $address2,
    "city" => $city,
    "postalCode" => $postcode,
    "phone" => $phone,
    "countryCode" => "ID",
  );

  $customerdetail["billingAddress"] = $billingaddress;
  // $customerdetail["shippingAddress"] = $billingaddress;

  $params['customerDetail'] = $customerdetail;
  $url = $endpoint . '/api/merchant/createInvoice';
  $tstamp = round(microtime(true) * 1000);
  $mcode = $merchantcode;
  $header_signature = hash('sha256', $mcode . $tstamp . $apikey);

  if (extension_loaded('curl')) {
    try {
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'x-duitku-signature: ' . $header_signature,
        'x-duitku-timestamp: ' . $tstamp,
        'x-duitku-merchantCode: ' . $mcode
      ));
      curl_setopt($ch, CURLOPT_URL, $url);
      curl_setopt($ch, CURLOPT_POST, 1);
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
      // Receive server response ...
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      $server_output = curl_exec($ch);
      $server_error = curl_error($ch);
      if (!empty($server_error)) {
        echo $server_error;
      }
      curl_close($ch);
      $respond = json_decode($server_output);
      logModuleCall("duitkupop", "Create Invoice", json_encode($params), $server_output, json_encode($respond), array());

      if ($respond->statusCode == '00') {
        if ($uimode == 'popup') {
          $reference = $respond->reference;
        } else {
          redirect_payment($respond->paymentUrl);
        }
      } else {
        $messages = json_encode($server_output);
      }
    } catch (Exception $e) {
      echo $e->getMessage();
    }
  } else {
    throw new Exception("Duitku payment need curl extension, please enable curl extension in your web server");
  }

  $html = '';
  $html = '<form onsubmit="return false"></form>';
  $html2 = '<form onsubmit="return false"></form>';
  $html2 .= '<a id="later" class="btn btn-info" href="" class="button">Continue shopping</a>
  <script type="text/javascript">
        var baseUrl = window.location.origin+window.location.pathname;
        var message = ' . strval($messages) . ';

        document.getElementById("later").href = baseUrl;
        
        var loc = window.location.href;
        var checkLoc = loc.lastIndexOf("viewinvoice");
        if (checkLoc >= 0) {
          document.getElementById("later").style.cssText = "display:none";
          var invoice_container = document.querySelector("[class*=\"container-fluid invoice-container\"]");
          var generatePanel = document.createElement("div");
          generatePanel.className = "panel panel-info";
          var generatePanelHeading = document.createElement("div");
          generatePanelHeading.className = "panel-heading";
          var generatePanelTitle = document.createElement("h3");
          generatePanelTitle.className = "panel-title";
          var generateTitleText = document.createElement("strong");
          generateTitleText.innerText = "Awaiting Payment";
          var generatePanelBody = document.createElement("div");
          generatePanelBody.className = "panel-body text-center";
          generatePanelBody.innerText = "Please complete your payment as instructed before. Check your email for instruction. Thank You!";
          generatePanelTitle.appendChild(generateTitleText);
          generatePanelHeading.appendChild(generatePanelTitle);
          generatePanel.appendChild(generatePanelHeading);
          generatePanel.appendChild(generatePanelBody);
          invoice_container.appendChild(generatePanel);
        }else{
          document.querySelector("[class*=\"alert alert-info text-center\"]").innerText = message === "Minimum Payment 10000" ? "Duitku payment message : Minimum Payment Rp.10000, Your payment amount is Rp.' . $amount . '" : message;
          document.querySelector(\'[alt*="Loading"]\').style.display = "none";
        }
  </script>';
  // $html .= '<pre>' . var_export($respond, true) . '</pre>';
  // $html .= '<pre>' . var_export($params, true) . '</pre>';
  // $html .= '<pre>' . var_export($url, true) . '</pre>';
  $html .= '<label id="label-status" style="display:none"></label>';
  $html .= '<button id="checkout-button">Loading...</button>
  <script src="' . $urllib . '"></script>
  <script type="text/javascript">
  var loc = window.location.href;
  var checkLoc = loc.lastIndexOf("viewinvoice");
  var checkoutButton = document.getElementById("checkout-button");
  var labelStatus = document.getElementById("label-status");
  //var breakLine = document.getElementById("break-line");

  var libraryDuitkuCheckoutExecute = false;
  var libraryDuitkuCheckout = function(event) {
    if (libraryDuitkuCheckoutExecute) {
      return false;
    }
    libraryDuitkuCheckoutExecute = true;

    try{
      setTimeout(function(){
        document.querySelector("[class*=\"alert alert-info text-center\"]").innerText = "Please Complete Your Payment";
        document.querySelector(\'[alt*="Loading"]\').style.display = "none";
      }, 1000);

    } catch(e){
      console.log(e);
    }

    var REFERENCE_NUMBER = "' . $reference . '";

    var countExecute = 0;
    var checkoutExecuted = false;
    var intervalFunction = 0;

    function executeCheckout(){
      intervalFunction = setInterval(function(){
        try {
          console.log("Duitku payment running.",++countExecute);
          checkout.process(REFERENCE_NUMBER, {
            successEvent: function(result){
              checkoutButton.className = "btn btn-success";
              labelStatus.innerHTML = "Payment Success...";
              labelStatus.style.cssText = "display:block";
              setTimeout(() => {
                labelStatus.innerHTML = "";
                labelStatus.style.cssText = "display:none";
              }, 5000);
              //breakLine.style.cssText = "display:block";
              //checkoutButton.innerHTML = "Payment Success...";
              window.location = "' . $params["returnUrl"] . '" + "?merchantOrderId=" + result.merchantOrderId + "&resultCode=" + result.resultCode + "&reference=" + result.reference;
            },
            pendingEvent: function(result){
              //checkoutButton.className = "btn btn-warning";
              labelStatus.innerHTML = "Payment Pending...";
              labelStatus.style.cssText = "display:block";
              setTimeout(() => {
                labelStatus.innerHTML = "";
                labelStatus.style.cssText = "display:none";
              }, 5000);
              //breakLine.style.cssText = "display:block";
              //checkoutButton.innerHTML = "Payment Pending...";
              window.location = "' . $params["returnUrl"] . '" + "?merchantOrderId=" + result.merchantOrderId + "&resultCode=" + result.resultCode + "&reference=" + result.reference;
            },
            errorEvent: function(result){
              //checkoutButton.className = "btn btn-danger";
              checkoutButton.innerHTML = "Re-Checkout";
              document.querySelector("[class*=\"alert alert-info text-center\"]").innerText = "We noticed a problem with your order. Please do re-checkout. If you think this is an error, feel free to contact our expert customer support team.";
            },
            closeEvent: function(result){
              //checkoutButton.className = "btn btn-default";
              //checkoutButton.innerHTML = "Payment Close";
              labelStatus.innerHTML = "Payment Close";
              labelStatus.style.cssText = "display:block";
              setTimeout(() => {
                labelStatus.innerHTML = "";
                labelStatus.style.cssText = "display:none";
              }, 5000);
              //breakLine.style.cssText = "display:block";
            }
          });
          checkoutExecuted = true;
        } catch (e) {
          if (countExecute >= 20) {
            location.reload();
            checkoutButton.className = "btn btn-info";
            checkoutButton.innerHTML = "Reloading...";
            return;
          }
        } finally {
          clearInterval(intervalFunction);
        }
      }, 1000);
    };

    var clickCount = 0;
    checkoutButton.className = "btn btn-success";
    checkoutButton.innerHTML = "Proceed to Payment";
    labelStatus.innerHTML = "";
    labelStatus.style.cssText = "display:none";
    //breakLine.style.cssText = "display:none";

    checkoutButton.onclick = function(){
      if (clickCount >= 2) {
        location.reload();
        checkoutButton.className = "btn btn-info";
        checkoutButton.innerHTML = "Reloading...";
        labelStatus.innerHTML = "";
        labelStatus.style.cssText = "display:none";
        //breakLine.style.cssText = "display:none";
        return;
      }
      checkoutButton.className = "btn btn-success";
      checkoutButton.innerHTML = "Proceed to Payment";
      labelStatus.innerHTML = "";
      labelStatus.style.cssText = "display:none";
      //breakLine.style.cssText = "display:none";
      executeCheckout();
      clickCount++;
    };

    executeCheckout();
  };

  function getParameterByName(name, url) {
    if (!url) url = window.location.href;
    name = name.replace(/[\[\]]/g, "\\$&");
    var regex = new RegExp("[?&]" + name + "(=([^&#]*)|&|#|$)"),
    results = regex.exec(url);
    if (!results) return null;
    if (!results[2]) return "";
    return decodeURIComponent(results[2].replace(/\+/g, " "));
  }

  if (checkLoc < 0) {
    document.addEventListener("DOMContentLoaded", libraryDuitkuCheckout);
    setTimeout(function(){ console.log("Running Duitku Payment"); libraryDuitkuCheckout(null); }, 30000);
  }else{
    document.addEventListener("DOMContentLoaded", libraryDuitkuCheckout);
    //checkoutButton.style.cssText = "display:none";
    if (getParameterByName("paymentsuccess") !== "true") {
      var invoice_container = document.querySelector("[class*=\"container-fluid invoice-container\"]");
      var generatePanel = document.createElement("div");
      generatePanel.className = "panel panel-info";
      var generatePanelHeading = document.createElement("div");
      generatePanelHeading.className = "panel-heading";
      var generatePanelTitle = document.createElement("h3");
      generatePanelTitle.className = "panel-title";
      var generateTitleText = document.createElement("strong");
      generateTitleText.innerText = "Awaiting Payment";
      var generatePanelBody = document.createElement("div");
      generatePanelBody.className = "panel-body text-center";
      generatePanelBody.innerText = "Please complete your payment as instructed before. Check your email for instruction. Thank You!";
      generatePanelTitle.appendChild(generateTitleText);
      generatePanelHeading.appendChild(generatePanelTitle);
      generatePanel.appendChild(generatePanelHeading);
      generatePanel.appendChild(generatePanelBody);
      invoice_container.appendChild(generatePanel);
    }
  }

  </script>
  ';
  if (empty($messages)) {
    return $html;
  } else {
    return $html2;
  }
}
