<?php

/*
    Alliance Tax System
    Copyright (C) 2011 Ryan Holmes

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    To recieve a copy of this license, see:
    <http://www.gnu.org/licenses/>.
*/

ob_start("ob_gzhandler");

define('TIMESTART', microtime(true));  // Start page benchmark
define('VERSION', '1.3');

if (isset($_GET['source'])) {
    // Also, apiDetails sample file can be found in root web. It's named 'apiDetails-sample.ini'
    show_source(__FILE__);
    die;
}

extract(parse_ini_file("config.ini", true));

$ref = array( // array of different ref values
    10 => 'Donation',
    37 => 'Corp Withdrawl');

$members    = array();
$paidTax   = array();
$journal    = array();
$apiMessage = '';     // a silly var that is used when updating the API

$apiDetails = parse_ini_file('protected/apiDetails.ini'); // path to protected file containing director API key
$walletURL = sprintf(
    "http://api.eve-online.com/corp/WalletJournal.xml.aspx?keyID=%d&vCode=%s&characterID=%d&accountKey=%d&rowCount=%d",
    $apiDetails['keyID'], $apiDetails['vCode'], $apiDetails['charID'], $accountKey, $rowCount);
$memberURL = sprintf(
    "http://api.eve-online.com/corp/MemberTracking.xml.aspx?keyID=%d&vCode=%s&characterID=%d",
    $apiDetails['keyID'], $apiDetails['vCode'], $apiDetails['charID']);
unset($apiDeatils);

function get_data($url) {
    $ch = curl_init();
    $timeout = 5;
    curl_setopt($ch,CURLOPT_URL,$url);
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
    curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,$timeout);
    $data = curl_exec($ch);
    curl_close($ch);
    return $data;
}

function refreshAPI($filename, $url) {
    $xml = get_data($url);
    file_put_contents("protected/".$filename, $xml);
}

if (!file_exists('protected/members.xml') || !file_exists('protected/wallet.xml')) {
    refreshAPI('members.xml', $memberURL);
    refreshAPI('wallet.xml', $walletURL);
}

try {
    $membersCache = new SimpleXMLElement(file_get_contents('protected/members.xml'));
    $walletCache = new SimpleXMLElement(file_get_contents('protected/wallet.xml'));
} catch (Exception $e) {
    die ('Error: '.  $e->getMessage()."\n<br />This usually means that the XML is malformed or unavailable. Check your API keys and make sure they are not returning unwanted XML.");
}

if (strtotime($membersCache->cachedUntil) < time()) { // if cache time has passed, refresh and reload
    refreshAPI('members.xml', $memberURL);
    $membersCache = new SimpleXMLElement(file_get_contents('members.xml'));
}

if (strtotime($walletCache->cachedUntil) < time()) { // if cache time has passed, refresh and reload
    refreshAPI('wallet.xml', $walletURL);
    $walletCache = new SimpleXMLElement(file_get_contents('wallet.xml'));
}


/****
 * IGB stuff
 * This is horribly insecure, but considering the only damage one could do is ignoring
 * certain journal entries, it will do until proper login is implemented
 ***/
$ingame = substr($_SERVER['HTTP_USER_AGENT'],-7) === 'EVE-IGB';
if ($ingame) {
    if ($_SERVER['HTTP_EVE_TRUSTED'] === 'Yes') {
        if (isset($permissions['character'][$_SERVER['HTTP_EVE_CHARNAME']])) {
            $access = $permissions['character'][$_SERVER['HTTP_EVE_CHARNAME']];
        }
    }
}
if (!isset($access)) {
    $access = $permissions['default']; }

if ($access < $permissions['view']) {
    die('I am sorry, but you do not have the proper permissions to view this page.'); }

// we can also select a month, useful if we need to reference a month or two back (tho don't push it, it's limited by a variety of factors such as API rowCount)
if (isset($_GET['month']) && (int)$_GET['month'] > 0 && (int)$_GET['month'] < 13) {
    $month = (int)$_GET['month']; }
else {
    $month = date('m'); } // current month

// Ignores
if(isset($_POST['save'])) {
    if ($access < $permissions['journal']) {
        die("I am sorry, but you do not have permissions to do that."); }

    if(!isset($_POST['ignore'])) {
        $ignoreRef = array(); }
    else {
        $ignoreRef = filter_var_array($_POST['ignore'], FILTER_SANITIZE_NUMBER_INT);}

    file_put_contents('ignoreRef.cfg', serialize($ignoreRef));
}
else if (file_exists('ignoreRef.cfg')){
    $ignoreRef = unserialize(file_get_contents('ignoreRef.cfg')); }
else {
    $ignoreRef = array();
}

// API Refresh
if (isset($_POST['refresh'])) {
    if ($access < $permissions['apiUpdate']) {
        die("I am sorry, but you do not have permissions to do that."); }

    $apiMessage .= "<div class='success'>";
    if (time() > strtotime($membersCache->cachedUntil)) {
        refreshAPI('members.xml', $memberURL);
        $membersCache = new SimpleXMLElement(file_get_contents('members.xml')); // reload cache
        $apiMessage.= "Members API updated."; }
    else {
        $apiMessage.= "Members API <strong>not</strong> updated; cached time not reached."; }
    $apiMessage .= " || ";
    if (time() > strtotime($walletCache->cachedUntil)) {
        refreshAPI('wallet.xml', $walletURL);
        $walletCache = new SimpleXMLElement(file_get_contents('wallet.xml')); // reload cache
        $apiMessage.= "Wallet API updated."; }
    else {
        $apiMessage.= "Wallet API <strong>not</strong> updated; cached time not reached."; }

    $apiMessage .= "</div>";
}

///******* MEMBER LIST STUFF
foreach ($membersCache->result->rowset->row AS $member){
    $members[(string)$member['name']] = (string)$member['logoffDateTime'];
}

///******* WALLET STUFF
for ($i = 0, $l = count($walletCache->result->rowset->row); $i < $l; $i++){
    $row = $walletCache->result->rowset->row[$i];
    $object2array = get_object_vars($row);
    extract($object2array['@attributes']);

    // This is here to get the correct character name.
    // Need this here to prevent corp refunds from showing up to those who give too much.
    $name = ($ownerID1 == $corpID ? $ownerName2 : $ownerName1);

    if (date('m', strtotime($date)) != $month || !array_key_exists($refTypeID, $ref)){
        // If this log entry is not of the wanted month, skip
        continue;
    }

    // if entry is being ignored
    if (in_array($refID, $ignoreRef)) {
        continue;
    }

    if (!isset($paidTax[$name])) {
        // If the member has not yet been added to the paid array, add them with 0 amount
        $paidTax[$name] = 0;
    }

    // Add amount to member total (if not ignored)
    $paidTax[$name] = $paidTax[$name] + $amount;

    // If it turns out the monetary amount falls to 0, remove member from paid array
    if ($paidTax[$name] === 0) {
        unset($paidTax[$name]); } // if it's 0, just might as well put them back on the non-paid list

    // Start building the journal array (much easier to do here while we're already dealing
    // with the data than to run another loop later)

    $journal[strtotime($date)] = $walletCache->result->rowset->row[$i];
}

ksort($paidTax); // sort alphabetically
krsort($journal); // sort by time

$percentage = round((array_sum($paidTax)/(count($members)*$goal))*100,2);

///******* DIRTY DIRTY FREELOADERS
$dirtyFreeLoaders = array_diff_key($members, $paidTax);
ksort($dirtyFreeLoaders);
?>
<html>
<head>
    <link type="text/css" href="style.css" title="Style" rel="stylesheet">
    <title>Alliance Tax System</title>
    <meta http-equiv='Content-Type' content='text/html; charset=utf-8' />

    <!-- Bootstrap -->
    <link rel="stylesheet" href="//netdna.bootstrapcdn.com/bootstrap/3.0.0/css/bootstrap.min.css">
    <link href='http://fonts.googleapis.com/css?family=Open+Sans+Condensed:300,700|Open+Sans' rel='stylesheet' type='text/css'>
    <link href="style.css" rel="stylesheet">
</head>
<body>

<div class='infoBar'>
    <?php
        if ($access >= $permissions['apiUpdate']) {
            echo "
    <form method='post' style='float: right;' >
    <button class='button' name='refresh' type='submit'".(time() < strtotime($membersCache->cachedUntil) && time() < strtotime($walletCache->cachedUntil) ? " disabled='disabled'" : null).">Refresh Now!</button></form>";
    } ?>
    <button class='button' style='float: left;'<?= (strpos($_SERVER['HTTP_USER_AGENT'], 'EVE-IGB') ? " onclick='CCPEVE.showInfo(2, $corpID)'" : " disabled='disabled'"); ?>><?= $corpTic; ?> Show Info</button>
    Member API last updated: <strong><?= $membersCache->currentTime; ?></strong>; cached until <strong><?= $membersCache->cachedUntil; ?></strong> ||
    Wallet API last updated: <strong><?= $walletCache->currentTime; ?></strong>; cached until <strong><?= $walletCache->cachedUntil; ?></strong>
</div>

<?= $apiMessage; ?>

<h1>Alliance Tax System</h1>

<div class='container'>
    <div class='col-lg-10 col-lg-offset-1'>
        <h3>Welcome!</h3>
        <p>Welcome to the <tt><?= $corpTic ?></tt> Alliance Tax System! Every month, <tt><?= $corpTic ?></tt>, along with the other
        <tt><?= $allianceTic ?></tt> corps, must pay the alliance <?= number_format($goal) ?> ISK per corp member. This will help
        fund an alliance reimbursement program for logistics, capitals, and other shiny ships. This simple application will help you
        and the corp leadership keep track of who has paid and who still needs to donate. For those who haven't donated yet, the
        last login date date is displayed to better determine whether they just haven't gotten around to it yet or if they dropped
        off the face of the Earth 3 months ago.</p>

        <h3>How Do I Donate?</h3>
        <p>Simply 'Show Info' on the <tt><?= $corpTic ?></tt> corp (in IGB, click button in top-left corner of the page), click the
        white box in the top left corner, and select 'Give Money'. Set the account to <strong><?= $corpDiv ?></strong>, the amount
        to <strong><?= number_format($goal) ?></strong>, and reason to <strong>Alliance Tax</strong>. Click 'OK'. Done! Give it a bit
        of time to chow up - the API information is cached and updated as needed. <strong>Donate only once a month.</strong> Please
        don't donate 12mil and think that your set for 3 months, that will just create more overhead for everyone (and you won't get
        to see your name on the list! you do want that, don't you?)</p>

        <h2>Month of <?= date('F', mktime(0, 0, 0, $month)) ?></h2>
        <div class='text-center'>Days Left: <?= date('t') - date('j') ?> | <?= $percentage ?>% complete</div>
        <div class='row'>
            <div class='col-md-6'>
                <h3>Paid: <?= count($paidTax); ?></h3>
                <table class='table table-striped table-condensed table-bordered no-footer'>
                <tr><th>Name</th><th>Amount (deficit/surplus)</th></tr>
                <?php
                foreach ($paidTax AS $name => $amount) {
                    if (($amount - $goal) === 0){
                        $offset = ""; }
                    else if(($amount - $goal) > 0) {
                        $offset = "<span style='color: green; font-weight: bold;'> (+".number_format($amount - $goal).")</span>"; }
                    else {
                        $offset = "<span style='color: red; font-weight: bold;'> (".number_format($amount - $goal).")</span>"; }

                    echo sprintf("<tr><td>%s</td><td>%s %s</td></tr>\n", $name, number_format($amount), $offset);
                }
                echo "<tr><td>Total</td><td>".number_format(array_sum($paidTax))."</td></tr>";
                ?>

                </table>
            </div>

            <div class='col-md-6'>
                <h3>Have NOT Paid: <?= count($dirtyFreeLoaders); ?></h3>
                <table class='table table-striped table-condensed table-bordered no-footer'>
                <tr><th>Name</td><th>Last login</td></tr>
                <?php
                $i = 0;
                foreach ($dirtyFreeLoaders AS $name => $lastLogin) {
                    echo sprintf("<tr><td>%s</td><td>%s</td></tr>\n", $name, substr($lastLogin, 0, -9));
                }
                ?>
                </table>
            </div>
        </div>

        <div class='row'>
            <h2>Journal</h2>
            <form method='post'>
            <table class='table table-striped table-condensed no-footer'>
                <tr><th>Timestamp</th><th>From</th><th>To</th><th>Type</th><th>Amount</th><th>Reason</th><th>Authorized By</th><th>Ignore</th></tr>
                <?php

                foreach ($journal AS $date => $attr){
                    $object2array = get_object_vars($attr);
                    extract($object2array['@attributes']);
                    $ignore = in_array($refID, $ignoreRef);

                    echo "<tr>".
                        sprintf("<td>%s</td>",date("Y-m-d H:i:s", strtotime($date))).
                        sprintf("<td>%s</td>", $ownerName1).
                        sprintf("<td>%s</td>", $ownerName2).
                        sprintf("<td>%s</td>", $ref[$refTypeID]).
                        sprintf("<td><span class='%s'>%s</span></td>", ($amount > 0 ? "positive" : "negative"), number_format($amount)).
                        sprintf("<td>%s</td>", $reason).
                        sprintf("<td>%s</td>", $argName1).
                        sprintf("<td><input type='checkbox' name='ignore[]' value='%s'%s%s /></td>", $refID, ($ignore === true ? " checked='checked'" : null), ($access < $permissions['journal'] ? " disabled='disabled'" : null)).
                        "</tr>";

                }
            echo "</table>";

            if ($access >= $permissions['journal']) {
                echo "<button style='margin-top: .8em; margin-right: 14em;' class='button' name='save' type='submit'>Save</button>"; }

            ?>
            </form>
        </div>
        <h3>Problems?</h3>
        <p>This is still very new, and it may have bugs. It only works if you've donated to the proper wallet division. If you
        donated to the master wallet and then moved it over, it's not going to work and you will most likely be left out of the
        'paid' list. For this reason, please make sure you donate to the proper wallet division to save everyone the headache.
        Currently, it's only set up to see if you've donated anything at all, not necessarily if it was 10 million or more/less.
        There's a few caveats, but it should work for the most part. Any bugs/questions about the application itself should be
        directed towards Sable Blitzmann. Any questions or concerns about your monthly Alliance Tax status ("I haz donated to teh
        wrong wallet, plz halp!", etc.) should be directed to anyone in a leadership position (<?= implode($leadership, ', ') ?>)
        -- they'll get you sorted out.</p>

        <h3>EVE Mail</h3>
        <p>Here is a nicely put-together string that can be used to easily mail everyone who has not yet paid to remind them to do so. Put it in the "To:" field.<br /><br />
        <?= "<blockquote>".implode(array_flip($dirtyFreeLoaders), ', ')."</blockquote>"; ?>
        </p>

        <div id='footer'>
        v<?= VERSION; ?> | Copyright &copy; 2011 Ryan Holmes aka Sable Blitzmann of M.DYN | EVE Online &copy; CCP | <?= sprintf('%01.002fms', (microtime(true) - TIMESTART) * 1000); ?> | <a href="?source">View Source</a> / <a href='https://github.com/holmes-ra/eveAllianceTax'>git</a><?php if ($ingame) { echo " | <button class='button' onclick=\"CCPEVE.requestTrust('http://".$_SERVER['HTTP_HOST']."/')\">Request Trust</button>"; } ?>
        </div>
    </div>
</div>
</body>
</html>