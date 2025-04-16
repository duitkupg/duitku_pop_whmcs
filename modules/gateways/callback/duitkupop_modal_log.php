<?php

require "../../../init.php";
$whmcs->load_function("gateway");
$whmcs->load_function("invoice");


if (
    empty($_POST['message']) || 
    empty($_POST['clientId']) ) {
        logActivity('fail logging client activity', 0);
        die();
}

logActivity($_POST['message'], $_POST['clientId']);
