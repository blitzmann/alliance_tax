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

if (isset($_GET['source'])) {
	// Also, apiDetails sample file can be found in root web. It's named 'apiDetails-sample.ini'
    show_source(__FILE__);
	die;
}

$accountKey = 1002; // the wallet division to pull from. 1000 - 1006 (1000 = master, 1001 = 2nd division, etc)
$rowCount   = 1000; /* how many rows of data to pull. It has to be enough to cover the entire month for this to be accurate.
					   If you have 1000 members in corp, and they each donate exactly once per month, this should cover it.
					   Max is 2560 (if you need more than that, your SOL. It would require a database backend to archive 
					   data and some considerable re-working to this code.) */
$goal       = 10000000; // the amount of tax per month per member

$ref = array( // array of different ref values
	10 => 'Donation',
	37 => 'Corp Withdrawl');

$members    = array();
$payedTax   = array();
$journal    = array();
$apiMessage = '';     // a silly var that is used when updating the API

$apiDetails = parse_ini_file("apiDetails.ini"); // path to protected file (outside of web root) containing director API key
$walletURL = "http://api.eve-online.com/corp/WalletJournal.xml.aspx?useriD=$apiDetails[userID]&apiKey=$apiDetails[apiKey]&characterID=$apiDetails[charID]&accountKey=$accountKey&rowCount=$rowCount";
$memberURL = "http://api.eve-online.com/corp/MemberTracking.xml.aspx?useriD=$apiDetails[userID]&apiKey=$apiDetails[apiKey]&characterID=$apiDetails[charID]";
unset($apiDeatils);

function get_data($url)
{
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
	file_put_contents($filename, $xml);
}

if (!file_exists('members.xml') || !file_exists('wallet.xml')) {
	refreshAPI('members.xml', $memberURL); 
	refreshAPI('wallet.xml', $walletURL);
}

// we can also select a month, useful if we need to reference a month or two back (tho don't push it, it's limited by a variety of factors such as API rowCount)
if (isset($_GET['month']) && (int)$_GET['month'] > 0 && (int)$_GET['month'] < 13) {
	$month = (int)$_GET['month']; }
else {
	$month = date('m'); } // current month
	
$membersCache = new SimpleXMLElement(file_get_contents('members.xml'));
$walletCache = new SimpleXMLElement(file_get_contents('wallet.xml'));

if (isset($_POST['refresh'])) {
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
	$date = strtotime((string)$walletCache->result->rowset->row[$i]['date']);
	$name = (string)$walletCache->result->rowset->row[$i]['ownerName1'];
	$amount = (int)$walletCache->result->rowset->row[$i]['amount'];
	
	if (date('m', $date) != $month){
		// If this log entry is not of the wanted month, skip
		continue; 
	}
	if (!isset($payedTax[$name])) {
		// If the member has not yet been added to the payed array, add them with 0 amount
		$payedTax[$name] = 0; }
	
	// Add amount to member total
	$payedTax[$name] = $payedTax[$name] + $amount;
	
	// If it turns out the monetary amount falls to 0, remove member from payed array
	if ($payedTax[(string)$walletCache->result->rowset->row[$i]['ownerName1']] === 0) {
		unset($payedTax[$name]); } // if it's 0, just might as well put them back on the non-payed list.
	
	// Start building the journal array (much easier to do here while we're already dealing 
	// with the data than to run another loop later)
	unset($walletCache->result->rowset->row[$i]['date']); // unset the date, we'll use custom $date var
	$journal[$date] = $walletCache->result->rowset->row[$i];
}
ksort($payedTax); // sort alphabetically
krsort($journal); // sort by time

$percentage = round((array_sum($payedTax)/(count($members)*$goal))*100,2);

///******* DIRTY DIRTY FREELOADERS
$dirtyFreeLoaders = array_diff_key($members, $payedTax);
ksort($dirtyFreeLoaders);
?>
<html>
<head>
	<link type="text/css" href="style.css" title="Style" rel="stylesheet">
	<title>Alliance Tax System</title>
</head>
<body>

<div class='infoBar'>
	<button class='button' style='float: left;'<?php echo (strpos($_SERVER['HTTP_USER_AGENT'], 'EVE-IGB') ? " onclick='CCPEVE.showInfo(2, 98022296)'" : " disabled='disabled'"); ?>>M.DYN Show Info</button>
	<form method='post' style='float: right;' ><button class='button' name='refresh' type='submit'<?php echo (time() < strtotime($membersCache->cachedUntil) && time() < strtotime($walletCache->cachedUntil) ? " disabled='disabled'" : null); ?>>Refresh Now!</button></form>
	Member API last updated: <strong><?php echo $membersCache->currentTime; ?></strong>; cached until <strong><?php echo $membersCache->cachedUntil; ?></strong> || 
	Wallet API last updated: <strong><?php echo $walletCache->currentTime; ?></strong>; cached until <strong><?php echo $walletCache->cachedUntil; ?></strong>
</div>
<?php echo $apiMessage; ?>
<div class='wrapper'>
<h1>Alliance Tax System</h1>

<h4>Welcome!</h4>
<p>Welcome to the <tt>M.DYN</tt> Alliance Tax System! Every month, <tt>M.DYN</tt>, along with the other <tt>.SUDO</tt> corps, must pay the alliance 10 million ISK per corp member. This will help fund an alliance reimbursment program for logistics, capitals, and other shiney ships. This little application will help you and the corp leadership keep track of who as payed and who still needs to dontate. For those who haven't donated yet, the last login date date is displayed to better determine whether they just gotten around to it yet or if they dropped off the face of the Earth 3 months ago.</p>

<h4>How Do I Donate?</h4>
<p>Simply 'Show Info' on the <tt>M.DYN</tt> corp (in IGB, click button in top-left corner of the page), click the white box in the top left corner, and select 'Give Money'. Set the account to <strong>3rd Wallet Division</strong>, the amount to <strong>10000000</strong> (10 million), and reason to <strong>Alliance Tax</strong>. Click 'OK'. Done! Give it a bit of time, then refresh the API cache by clicking the button in the top right hand corner of this page to verify your payment. <strong>Donate only once a month.</strong> Please don't donate 50mil and think that your set for 5 months, that will just create more overhead for everyone (and you won't get to see your name on the list! you do want that, don't you? =P)</p>

<hr />
<h2 style='text-align:center;'>Month <?php echo $month ?></h2>
<h3 style='text-align: center;'>Days Left: <?php print date('t') - date('j');?> | <?php echo $percentage; ?>% complete</h3>
<div style='margin-top: 1.5em; width: 50%; float:right; text-align:center;'>
<h3>Payed: <?php echo count($payedTax); ?></h3>
<table>
<tr><th>Name</th><th>Amount (deficit/surplus)</th></tr>
<?php
$i = 0;
foreach ($payedTax AS $name => $amount) {
	if($i % 2 != 0) {
      $class = " class='zebra'"; }
    else {
      $class = ''; }

	if (($amount - $goal) === 0){
		$offset = ""; }
	else if(($amount - $goal) > 0) {
		$offset = "<span style='color: green; font-weight: bold;'> (+".number_format($amount - $goal).")</span>"; }
	else {
		$offset = "<span style='color: red; font-weight: bold;'> (".number_format($amount - $goal).")</span>"; }
	echo "\n<tr$class><td>$name</td><td>".number_format($amount).$offset."</td></tr>"; 
	$i++;
}
?>
</table>
</div>
<div style='margin-top: 1.5em; width: 50%; text-align:center;'>
<h3>Have NOT Payed: <?php echo count($dirtyFreeLoaders); ?></h3>
<table>
<tr><th>Name</td><th>Last login</td></tr>

<?php
$i = 0;
foreach ($dirtyFreeLoaders AS $name => $lastLogin) {
	if($i % 2 != 0) {
      $class = " class='zebra'"; }
    else {
      $class = ''; }
	  
	echo "\n<tr$class><td>$name</td><td>".substr($lastLogin, 0, -9)."</td></tr>"; 
	
	$i++;
}
?>
</table>
</div>
</div>

<div style='text-align:center; margin: 0 auto;'>
<h3 style='margin: 1em 0;'>Journal</h3>
<table>
<tr><th>Timestamp</th><th>From</th><th>To</th><th>Type</th><th>Amount</th><th>Balance</th><th>Reason</th><th>Authorized By</th></tr>
<?php
$i = 0;
foreach ($journal AS $date => $attr){
	if($i % 2 != 0) {
      $class = " class='zebra'"; }
    else {
      $class = ''; }
	  
	$object2array = get_object_vars($attr);
	extract($object2array['@attributes']);
	
	echo "<tr$class>
	<td>".date("Y-m-d H:i:s", $date)."</td>
	<td>$ownerName1</td>
	<td>$ownerName2</td>
	<td>".$ref[$refTypeID]."</td>
	<td style='text-align: right;'>". ($amount > 0 ? "<span style='color: green; font-weight: bold;'>+".number_format($amount)."</span>" : "<span style='color: red; font-weight: bold;'>".number_format($amount)."</span>")."</td>
	<td style='text-align: right;'><strong>".number_format($balance)."</strong></td>
	<td>$reason</td>
	<td>$argName1</td>
</tr>";
$i++;
}
?>
</table>
</div>
<div class='wrapper'>
<h4>Problems?</h4>
<p>This is still very new, and it may have bugs. It only works if you've donated to the proper wallet division. If you donated to the master wallet and then moved it over, it's not going to work and you will most likely be left out of the 'payed' list. For this reason, please make sure you donate to the proper wallet division to save everyone the headache. Currently, it's only set up to see if you've donated anything at all, not neccessarily if it was 10 million or more/less. There's a few cavets, but it should work for the most part. Any bugs/questions about the application itself should be directed towards Sable Blitzmann. Any questions or concerns about your monthly Alliance Tax status ("I haz donated to teh wrong wallet, plz halp!", etc.) should be directed to anyone in a leadership position (Aurum Pax, Hal IV, Nimloth Valinor, Sable Blitzmann, Torvix) -- they'll get you sorted out.</p>

<h4>EVE Mail</h4>
<p>Here is a nicely put-together string that can be used to easily mail everyone who has not yet payed to remind them to do so. Put it in the "To:" field.<br /><br />
<?php echo "<tt>".implode(array_flip($dirtyFreeLoaders), ', ')."</tt>"; ?>
</p>
</div>
<div id='footer'>
v1.1 | Copyright &copy; 2011 Ryan Holmes | EVE Online &copy; CCP | <?php echo sprintf('%01.002fms', (microtime(true) - TIMESTART) * 1000); ?> | <a href="?source">View Source</a>
</div>
</body>
</html>	