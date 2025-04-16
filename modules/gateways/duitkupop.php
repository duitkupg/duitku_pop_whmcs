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
    "environment" => array(
      "FriendlyName" => "Environment ",
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
    "duitkuLang" => array(
      "FriendlyName" => "Default Language",
      "Type" => "dropdown",
      "Options" => array(
        'id' => 'Bahasa Indonesia',
        'en' => 'English',
      ),
      "Description" => "Select default language on the payment page",
      "Default" => "id",
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

function duitkupop_link($params)
{
	$systemUrl = $params['systemurl'];
	$langPayNow = $params['langpaynow'];
  $merchantCode = $params['merchantcode'];
  $secretKey = $params['apikey'];
  $duitkuLang = $params['duitkuLang'];
  $uimode = $params['uimode'];
  $environment = $params['environment'];
  $urllib = "";
  $paymentName = $params['paymentmethod'];
  $clientid = $params['cart']->client->id;
  $invoiceid = $params['invoiceid'];
  $currency = $params['currency'];
  $description = $params['description'];
  $signature = hash("sha256", $merchantCode . $clientid . $invoiceid . $description . $secretKey);
  if ($environment == "sandbox") {
    $urllib = 'https://app-sandbox.duitku.com/lib/js/duitku.js';
  } elseif ($environment == "production") {
    $urllib = 'https://app-prod.duitku.com/lib/js/duitku.js';
  }

  //check currency to add duitku estimation exchange
  $duitkuQueryCurrency = '';
  $duitkuParamCurrency = '';
  if($currency == 'USD'){
    $duitkuQueryCurrency = '&currency=USD';
    $duitkuParamCurrency = 'currency: "USD",';
  }else if($currency == 'EUR'){
    $duitkuQueryCurrency = '&currency=EUR';
    $duitkuParamCurrency = 'currency: "EUR",';
  }
  
	$img       	= $systemUrl . "/modules/gateways/duitkupop/logo.jpg"; 
  $htmlOutput = "";//declaration
  $htmlOutput .= '<script src="' . $urllib . '"></script>';//library
  $htmlOutput .= '<script>
                    function handleDuitkuPopLog(clientId, message){
                      fetch("' . $systemUrl . '/modules/gateways/callback/duitkupop_modal_log.php", {
                        method: "POST",
                        headers: {
                          "Content-Type": "application/x-www-form-urlencoded"
                        },
                        body: "clientId=" + clientId + "&message=" + message
                      })
                      .catch(error => console.error("Error:", error));
                    }
                  </script>';//log handling pop modal
  $htmlOutput .= '<img style="width: 152px;" src="' . $img . '" alt="Duitku POP"><br/>';//logo
  $htmlOutput .= '<button class="btn btn-info" onclick="handleDuitkuPopPay()">' . $langPayNow . '</button>';//button to pay
  $htmlOutput .= '<script>
                    function handleDuitkuPopPay() {
                      let dataPayment = {
                        paymentName: "' . $paymentName . '",
                        clientid: "' . $clientid . '",
                        invoiceid: "' . $invoiceid . '",
                        currency: "' . $currency . '",
                        description: "' . $description . '",
                        signature: "' . $signature . '"
                      }

                      let uimode = "' . $uimode . '"
                      handleDuitkuPopLog(' . $clientid .', "User Initiate Payment.")

                      $.ajax({

                        url : "' . $systemUrl."/modules/gateways/callback/duitkupop_request.php" . '",
                        type : "POST",
                        contentType: "application/json", 
                        data: JSON.stringify(dataPayment),
                        success : function (result) {
                            let {duitkuData} = result;
                            if(uimode == "redirect"){
                              handleDuitkuPopLog(' . $clientid .', "Redirect to payment page.")
                              window.location = duitkuData.paymentUrl + "&lang=' . $duitkuLang . $duitkuQueryCurrency .'";
                            }else{
                              checkout.process(duitkuData.reference, {
                                  defaultLanguage: "' . $duitkuLang . '", 
                                  ' . $duitkuParamCurrency . '
                                  successEvent: function(result){
                                      handleDuitkuPopLog(' . $clientid .', "Modal Pop closed with payment success.")
                                      window.location = "' . $params['systemurl'] . "/viewinvoice.php?id=" . $invoiceid . "&paymentsuccess=true" .'";
                                  },
                                  pendingEvent: function(result){
                                      handleDuitkuPopLog(' . $clientid .', "Modal Pop closed, waiting for payment to be done.")
                                      window.location = "' . $params['systemurl'] . "/viewinvoice.php?id=" . $invoiceid . '";
                                  },
                                  errorEvent: function(result){
                                      handleDuitkuPopLog(' . $clientid .', "Modal Pop closed. Payment failed or canceled.")
                                      window.location = "' . $params['systemurl'] . "/viewinvoice.php?id=" . $invoiceid . "&paymentfailed=true" .'";
                                  },
                                  closeEvent: function(result){
                                      handleDuitkuPopLog(' . $clientid .', "Modal Pop closed.")
                                  }
                              }); 
                            }
                        },
                        error : function (xhr, status, error) {
                            handleDuitkuPopLog(' . $clientid .', "Error: " + xhr.responseJSON.error);
                            window.location = "' . $params['systemurl'] . "/viewinvoice.php?id=" . $invoiceid . "&paymentfailed=true" .'";
                        }

                      });
                    }
                  </script>';

  return $htmlOutput;
}