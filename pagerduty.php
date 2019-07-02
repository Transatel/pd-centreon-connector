<?php

// -----------------------------------------------------------------------
// CONSTS

define('NAGIOS_STICKY_YES', 2);
define('NAGIOS_STICKY_NO', 1);

define('NAGIOS_NOTIFY_YES', 1);
define('NAGIOS_NOTIFY_NO', 0);

define('NAGIOS_PERISTENT_YES', 1);
define('NAGIOS_PERISTENT_NO', 0);


// -----------------------------------------------------------------------
// CONFIG

define('NOTIF_HELPER_API_URL', 'http://localhost:8000/notification-helper/');

define('UNACK_ON_RESOLVE', true);

define('IS_STICKY', NAGIOS_STICKY_NO);
define('DO_NOTIFY', NAGIOS_NOTIFY_NO);
define('IS_PERSISTENT', NAGIOS_PERISTENT_YES);

define('DEFAULT_ACK_USERNAME', 'PagerDuty');


// -----------------------------------------------------------------------
// REQUEST PARSING

$bodyStr = strip_tags(file_get_contents('php://input'));

$isDebug = array_key_exists('debug', $_GET);

$body = json_decode($bodyStr, true);
if ($body === null) {
    echo "Invalid request, unable to parse JSON.";
    http_response_code(400);
    exit();
}

// https://v2.developer.pagerduty.com/docs/webhooks-v2-overview

$isSingleEvent = true;
$messageList = @$body['messages'];
if (isset($messageList))
	$isSingleEvent = false;

if ($isSingleEvent) {
    $eventType = @$body['event'];
    if (empty($eventType) || gettype($eventType) !== "string") {
        echo "Invalid request, single element with no or invalid key `event`.";
        http_response_code(400);
        exit();
    }

    $messageList = array( $body );
}


// -----------------------------------------------------------------------
// MAIN

$nowTimestamp = time();
$output = array(
    'status' => 'okay',
    'messages' => array(),
);

foreach ($messageList as $message) {
    $eventId = @$message['id'];
    if (empty($eventId)) {
        // echo "Invalid message, no or invalid key `id`.";
        continue;
    }
	$eventType = @$message['event'];
    if (empty($eventType)) {
        // echo "Invalid message, no or invalid key `event`.";
        continue;
    }

    $logEntries = @$message['log_entries'];
    $ackUser = null;
    $ackChannel = null;
    $ackMessage = 'Acknowledged by ';
    if (!empty($logEntries)) {
        $lastLogEntry = end($logEntries);

        $ackChannel = @$lastLogEntry['channel']['type'];

        $agentType = @$lastLogEntry['agent']['type'];
        if ($agentType === 'user_reference') {
            $ackUser = @$lastLogEntry['agent']['summary'];
        } else {
            $ackUser = @$lastLogEntry['channel']['user']['name'];
        }

        if (! empty($ackUser))
            $ackUser = normalizeUserName($ackUser);
    }

    if (empty($ackUser))
        $ackUser = DEFAULT_ACK_USERNAME;
    $ackMessage .= $ackUser;
    if (! empty($ackChannel))
        $ackMessage .= " (via PagerDuty - " . $ackChannel . ")";

    $alertKey = @$message['incident']['alerts'][0]['alert_key'];
    $alertParamsStrList = explode(';', $alertKey);
    $alertParams = array();
    foreach ($alertParamsStrList as $k_v) {
        list($k, $v) = explode('=', $k_v);
        $alertParams[$k] = $v;
    }

    $hostName = @$alertParams['host_name'];
    $serviceDesc = @$alertParams['service_desc'];
    if (empty($hostName)) {
        //echo "Invalid request, no hostname precised.";
        continue;
    }

    $centreonCmdIsSuccess = true;
    switch ($eventType) {
        case 'incident.acknowledge':
            if (! empty($serviceDesc)) {
				if (! isAckedService($hostName, $serviceDesc))
					$centreonCmdIsSuccess = ackService($nowTimestamp, $hostName, $serviceDesc, IS_STICKY, DO_NOTIFY, IS_PERSISTENT, $ackUser, $ackMessage);
			} else {
				if (! isAckedHost($hostName))
					$centreonCmdIsSuccess = ackHost($nowTimestamp, $hostName, IS_STICKY, DO_NOTIFY, IS_PERSISTENT, $ackUser, $ackMessage);
			}
            break;
        case 'incident.unacknowledge':
        case 'incident.resolve':
			if (UNACK_ON_RESOLVE) {
				if (! empty($serviceDesc)) {
					if (isAckedService($hostName, $serviceDesc))
						$centreonCmdIsSuccess = unackService($nowTimestamp, $hostName, $serviceDesc);
				} else {
					if (isAckedHost($hostName))
						$centreonCmdIsSuccess = unackHost($nowTimestamp, $hostName);
				}
			}
            break;
        default:
            //echo "Invalid request, unexpected value for key `event`.";
            continue;
    }

    if ($centreonCmdIsSuccess) {
        $outputStatus = 'okay';
        $outputMessage = null;
    } else {
        $outputStatus = 'fail';
        $outputMessage = "Unable to exec cmd";
    }
    $output['messages'][$eventId] = array(
        'status' => $outputStatus,
        'message' => $outputMessage
    );
}


// -----------------------------------------------------------------------
// OUTPUT

header('Content-Type: application/json');
echo json_encode($output);
exit();



// ========================================================================
// FUNCTIONS


// ----------------------------------
// GENERIC

function postGetRequest ($url, $headers=array(), $cnnxTimeout=1, $timeout=30) {
	global $isDebug;

	if ($isDebug !== null && $isDebug) {
        echo $url;
        exit();
    }

	$success = true;
	try {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $cnnxTimeout);
		// NB: curl particularity, CURLOPT_TIMEOUT should include cnnx timeout!
		curl_setopt($ch, CURLOPT_TIMEOUT, ($cnnxTimeout + $timeout));
		if (! empty($headers))
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		$resp = curl_exec($ch);
		$httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ($no = curl_errno($ch)) {
			// $this->lastCallError = curl_error($ch);
			return array(null, null);
		}
		curl_close($ch);
	} catch (\Exception $ex) {
		array(null, null);
	}

	return array($httpStatusCode, $resp);;
}

function normalizeUserName ($userName) {
    $userName = strtolower($userName);
    $userName = str_replace(' ', '.', $userName);
    return $userName;
}


// ----------------------------------
// NOTIF HELPER API

function isAckedHost ($hostName) {
	$url = NOTIF_HELPER_API_URL . 'is-acked-host/' . $hostName;

	list($httpStatusCode, $resp) = postGetRequest($url);

	if ($httpStatusCode === null)
		return null;
	if ($httpStatusCode < 200 && $httpStatusCode >= 400)
		return null;

	$resp = json_decode($resp, true);
	$isAcked = @$resp['acked'];

	if (! isset($isAcked))
		return null;

	return ( $isAcked === true );
}

function ackHost ($timestamp, $hostName, $isSticky, $doNotify, $isPersistent, $author, $comment) {
	$url = NOTIF_HELPER_API_URL . 'ack-host/' . $hostName;

	$urlParamList = array(
		'author' => $author,
		'comment' => $comment,
		'sticky' => $isSticky,
		'notify' => $doNotify,
		'persistent' => $isPersistent,
		'timestamp' => $timestamp,
		);
	$urlParams = http_build_query($urlParamList);

	list($httpStatusCode, $resp) = postGetRequest($url . '?' . $urlParams);

	if ($httpStatusCode === null)
		return false;
	if ($httpStatusCode < 200 && $httpStatusCode >= 400)
		return false;

	$resp = json_decode($resp, true);
	$isSuccess = @$resp['success'];
	return ( $isSuccess === true );
}

function unackHost ($timestamp, $hostName) {
	$url = NOTIF_HELPER_API_URL . 'unack-host/' . $hostName;

	$urlParamList = array(
		'timestamp' => $timestamp,
		);
	$urlParams = http_build_query($urlParamList);

	list($httpStatusCode, $resp) = postGetRequest($url . '?' . $urlParams);

	if ($httpStatusCode === null)
		return false;
	if ($httpStatusCode < 200 && $httpStatusCode >= 400)
		return false;

	$resp = json_decode($resp, true);
	$isSuccess = @$resp['success'];
	return ( $isSuccess === true );
}

function isAckedService ($hostName, $serviceDesc) {
	$url = NOTIF_HELPER_API_URL . 'is-acked-service/' . $hostName . '/' . $serviceDesc;

	list($httpStatusCode, $resp) = postGetRequest($url);

	if ($httpStatusCode === null)
		return null;
	if ($httpStatusCode < 200 && $httpStatusCode >= 400)
		return null;

	$resp = json_decode($resp, true);
	$isAcked = @$resp['acked'];

	if (! isset($isAcked))
		return null;

	return ( $isAcked === true );
}

function ackService ($timestamp, $hostName, $serviceDesc, $isSticky, $doNotify, $isPersistent, $author, $comment) {
	$url = NOTIF_HELPER_API_URL . 'ack-service/' . $hostName . '/' . $serviceDesc;

	$urlParamList = array(
		'author' => $author,
		'comment' => $comment,
		'sticky' => $isSticky,
		'notify' => $doNotify,
		'persistent' => $isPersistent,
		'timestamp' => $timestamp,
		);
	$urlParams = http_build_query($urlParamList);

	list($httpStatusCode, $resp) = postGetRequest($url . '?' . $urlParams);

	if ($httpStatusCode === null)
		return false;
	if ($httpStatusCode < 200 && $httpStatusCode >= 400)
		return false;

	$resp = json_decode($resp, true);
	$isSuccess = @$resp['success'];
	return ( $isSuccess === true );
}

function unackService ($timestamp, $hostName, $serviceDesc) {
	$url = NOTIF_HELPER_API_URL . 'unack-service/' . $hostName . '/' . $serviceDesc;

	$urlParamList = array(
		'timestamp' => $timestamp,
		);
	$urlParams = http_build_query($urlParamList);

	list($httpStatusCode, $resp) = postGetRequest($url . '?' . $urlParams);

	if ($httpStatusCode === null)
		return false;
	if ($httpStatusCode < 200 && $httpStatusCode >= 400)
		return false;

	$resp = json_decode($resp, true);
	$isSuccess = @$resp['success'];
	return ( $isSuccess === true );
}
