<?php
/**************************************************************************
**********      English Wikipedia Account Request Interface      **********
***************************************************************************
** Wikipedia Account Request Graphic Design by Charles Melbye,           **
** which is licensed under a Creative Commons                            **
** Attribution-Noncommercial-Share Alike 3.0 United States License.      **
**                                                                       **
** All other code are released under the Public Domain                   **
** by the ACC Development Team.                                          **
**                                                                       **
** See CREDITS for the list of developers.                               **
***************************************************************************/

// stop all output until we want it
ob_start();

// load the configuration
require_once 'config.inc.php';

// Initialize the session data.
session_start();

// Get all the classes.
require_once 'functions.php';
require_once 'includes/PdoDatabase.php';
require_once 'includes/SmartyInit.php'; // this needs to be high up, but below config, functions, and database
require_once 'includes/session.php';

// Check to see if the database is unavailable.
// Uses the false variable as its the internal interface.
if (Offline::isOffline()) {
	echo Offline::getOfflineMessage(false);
	die();
}

// Initialize the class objects.
$session = new session();
$date = new DateTime();

// initialise providers
global $squidIpList;
/** @var ILocationProvider $locationProvider */
$locationProvider = new $locationProviderClass(gGetDb('acc'), $locationProviderApiKey);
/** @var IRDnsProvider $rdnsProvider */
$rdnsProvider = new $rdnsProviderClass(gGetDb('acc'));
/** @var IAntiSpoofProvider $antispoofProvider */
$antispoofProvider = new $antispoofProviderClass();
/** @var IXffTrustProvider $xffTrustProvider */
$xffTrustProvider = new $xffTrustProviderClass($squidIpList);

// Clears the action variable.
$action = '';

// Assign the correct value to the action variable.
// The value is retrieved from the $GET variable.
if (isset($_GET['action'])) {
	$action = $_GET['action'];
}


// Checks whether the user is set - the user should first login.
if (!isset($_SESSION['userID'])) {
	ob_end_clean();
	global $baseurl;
	header("Location: $baseurl/internal.php/login");
	die();
}

// Forces the current user to ogout if necessary.
if (isset($_SESSION['userID'])) {
	$session->forceLogout($_SESSION['userID']);
}

BootstrapSkin::displayInternalHeader();
$session->checksecurity();


// When no action is specified the default Internal ACC are displayed.
// TODO: Improve way the method is called.
if ($action == '') {
	ob_end_clean();
	global $baseurl;
	header("Location: $baseurl/internal.php");
	die();
}

elseif ($action == "sreg") {
	global $useOauthSignup, $smarty;
        
	// TODO: check blocked
	// TODO: check age.
    
	// check if user checked the "I have read and understand the interface guidelines" checkbox
	if (!isset($_REQUEST['guidelines'])) {
		$smarty->display("registration/alert-interfaceguidelines.tpl");
		BootstrapSkin::displayInternalFooter();
		die();
	}
	
	if (!filter_var($_REQUEST['email'], FILTER_VALIDATE_EMAIL)) {
		$smarty->display("registration/alert-invalidemail.tpl");
		BootstrapSkin::displayInternalFooter();
		die();
	}
    
	if ($_REQUEST['pass'] !== $_REQUEST['pass2']) {
		$smarty->display("registration/alert-passwordmismatch.tpl");
		BootstrapSkin::displayInternalFooter();
		die();
	}
    
	if (!$useOauthSignup) {
		if (!((string)(int)$_REQUEST['conf_revid'] === (string)$_REQUEST['conf_revid']) || $_REQUEST['conf_revid'] == "") {
			$smarty->display("registration/alert-confrevid.tpl");
			BootstrapSkin::displayInternalFooter();
			die();		
		}
	}
    
	if (User::getByUsername($_REQUEST['name'], gGetDb()) != false) {
		$smarty->display("registration/alert-usernametaken.tpl");
		BootstrapSkin::displayInternalFooter();
		die();
	}
    
	$query = gGetDb()->prepare("SELECT * FROM user WHERE email = :email LIMIT 1;");
	$query->execute(array(":email" => $_REQUEST['email']));
	if ($query->fetchObject("User") != false) {
		$smarty->display("registration/alert-emailtaken.tpl");
		BootstrapSkin::displayInternalFooter();
		die();
	}
	$query->closeCursor();

	$database = gGetDb();
    
	$database->transactionally(function() use ($database, $useOauthSignup)
	{
    
		$newUser = new User();
		$newUser->setDatabase($database);
    
		$newUser->setUsername($_REQUEST['name']);
		$newUser->setPassword($_REQUEST['pass']);
		$newUser->setEmail($_REQUEST['email']);
        
		if (!$useOauthSignup) {
			$newUser->setOnWikiName($_REQUEST['wname']);
			$newUser->setConfirmationDiff($_REQUEST['conf_revid']);
		}
        
		$newUser->save();
    
		global $oauthConsumerToken, $oauthSecretToken, $oauthBaseUrl, $oauthBaseUrlInternal, $useOauthSignup;
    
		if ($useOauthSignup) {
			try {
				// Get a request token for OAuth
				$util = new OAuthUtility($oauthConsumerToken, $oauthSecretToken, $oauthBaseUrl, $oauthBaseUrlInternal);
				$requestToken = $util->getRequestToken();
    
				// save the request token for later
				$newUser->setOAuthRequestToken($requestToken->key);
				$newUser->setOAuthRequestSecret($requestToken->secret);
				$newUser->save();
            
				Notification::userNew($newUser);
        
				$redirectUrl = $util->getAuthoriseUrl($requestToken);
            
				header("Location: {$redirectUrl}");
			}
			catch (Exception $ex) {
				throw new TransactionException(
					$ex->getMessage(), 
					"Connection to Wikipedia failed.", 
					"alert-error", 
					0, 
					$ex);
			}
		}
		else {
			global $baseurl;
			Notification::userNew($newUser);
			header("Location: {$baseurl}/acc.php?action=registercomplete");
		}
	});
    
	die();
}
elseif ($action == "register") {
	global $useOauthSignup, $smarty;
	$smarty->assign("useOauthSignup", $useOauthSignup);
	$smarty->display("registration/register.tpl");
	BootstrapSkin::displayInternalFooter();
	die();
}
elseif ($action == "registercomplete") {
	$smarty->display("registration/alert-registrationcomplete.tpl");
	BootstrapSkin::displayInternalFooter();
}

elseif ($action == "defer" && $_GET['id'] != "" && $_GET['sum'] != "") {
	global $availableRequestStates;
	
	if (array_key_exists($_GET['target'], $availableRequestStates)) {
		$request = Request::getById($_GET['id'], gGetDb());
		
		if ($request == false) {
			BootstrapSkin::displayAlertBox(
				"Could not find the specified request!", 
				"alert-error", 
				"Error!", 
				true, 
				false);
            
			BootstrapSkin::displayInternalFooter();
			die();
		}
		
		if ($request->getChecksum() != $_GET['sum']) {
			SessionAlert::error(
				"This is similar to an edit conflict on Wikipedia; it means that you have tried to perform an action "
				. "on a request that someone else has performed an action on since you loaded the page",
				"Invalid checksum");
            
			header("Location: acc.php?action=zoom&id={$request->getId()}");
			die();
		}
        
		$sqlText = <<<SQL
SELECT timestamp FROM log
WHERE objectid = :request and objecttype = 'Request' AND action LIKE 'Closed%'
ORDER BY timestamp DESC LIMIT 1;
SQL;
        
		$statement = gGetDb()->prepare($sqlText);
		$statement->execute(array(":request" => $request->getId()));
		$logTime = $statement->fetchColumn();
		$statement->closeCursor();
        
		$date = new DateTime();
		$date->modify("-7 days");
		$oneweek = $date->format("Y-m-d H:i:s");
        
		if ($request->getStatus() == "Closed" 
			&& $logTime < $oneweek 
			&& !User::getCurrent()->isAdmin() 
			&& !User::getCurrent()->isCheckuser()) {
			SessionAlert::error("Only administrators and checkusers can reopen a request that has been closed for over a week.");
			header("Location: acc.php?action=zoom&id={$request->getId()}");
			die();
		}
        
		if ($request->getStatus() == $_GET['target']) {
			SessionAlert::error(
				"Cannot set status, target already deferred to " . htmlentities($_GET['target']), 
				"Error");
			header("Location: acc.php?action=zoom&id={$request->getId()}");
			die();
		}
        
		$database = gGetDb();
		$database->transactionally(function() use ($database, $request)
		{
			global $availableRequestStates;
                
			$request->setReserved(0);
			$request->setStatus($_GET['target']);
			$request->updateChecksum();
			$request->save();
            
			$deto = $availableRequestStates[$_GET['target']]['deferto'];
			$detolog = $availableRequestStates[$_GET['target']]['defertolog'];
            
			Logger::deferRequest($database, $request, $detolog);
        
			Notification::requestDeferred($request);
			SessionAlert::success("Request {$request->getId()} deferred to $deto");
			header("Location: acc.php");
		});
        
		die();
	}
	else {
		BootstrapSkin::displayAlertBox("Defer target not valid.", "alert-error", "Error", true, false);
		BootstrapSkin::displayInternalFooter();
		die();
	}
}
elseif ($action == "done" && $_GET['id'] != "") {
	// check for valid close reasons
	global $messages, $baseurl, $smarty;
	
	if (isset($_GET['email'])) {
		if ($_GET['email'] == 0 || $_GET['email'] == "custom") {
			$validEmail = true;
		}
		else {
			$validEmail = EmailTemplate::getById($_GET['email'], gGetDb()) != false;
		}
	}
	else {
		$validEmail = false;
	}
    
	if ($validEmail == false) {
		BootstrapSkin::displayAlertBox("Invalid close reason", "alert-error", "Error", true, false);
		BootstrapSkin::displayInternalFooter();
		die();
	}
	
	// sanitise this input ready for inclusion in queries
	$request = Request::getById($_GET['id'], gGetDb());
    
	if ($request == false) {
		// Notifies the user and stops the script.
		BootstrapSkin::displayAlertBox("The request ID supplied is invalid!", "alert-error", "Error", true, false);
		BootstrapSkin::displayInternalFooter();
		die();
	}
    
	$gem = $_GET['email'];
	
	// check the checksum is valid
	if ($request->getChecksum() != $_GET['sum']) {
		BootstrapSkin::displayAlertBox("This is similar to an edit conflict on Wikipedia; it means that you have tried to perform an action on a request that someone else has performed an action on since you loaded the page.", "alert-error", "Invalid Checksum", true, false);
		BootstrapSkin::displayInternalFooter();
		die();
	}
	
	// check if an email has already been sent
	if ($request->getEmailSent() == "1" && !isset($_GET['override']) && $gem != 0) {
		$alertContent = "<p>This request has already been closed in a manner that has generated an e-mail to the user, Proceed?</p><br />";
		$alertContent .= "<div class=\"row-fluid\">";
		$alertContent .= "<a class=\"btn btn-success offset3 span3\"  href=\"$baseurl/acc.php?sum=" . $_GET['sum'] . "&amp;action=done&amp;id=" . $_GET['id'] . "&amp;override=yes&amp;email=" . $_GET['email'] . "\">Yes</a>";
		$alertContent .= "<a class=\"btn btn-danger span3\" href=\"$baseurl/acc.php\">No</a>";
		$alertContent .= "</div>";
        
		BootstrapSkin::displayAlertBox($alertContent, "alert-info", "Warning!", true, false, false, true);
		BootstrapSkin::displayInternalFooter();
		die();
	}
	
	// check the request is not reserved by someone else
	if ($request->getReserved() != 0 && !isset($_GET['reserveoverride']) && $request->getReserved() != User::getCurrent()->getId()) {
		$alertContent = "<p>This request is currently marked as being handled by " . $request->getReservedObject()->getUsername() . ", Proceed?</p><br />";
		$alertContent .= "<div class=\"row-fluid\">";
		$alertContent .= "<a class=\"btn btn-success offset3 span3\"  href=\"$baseurl/acc.php?" . $_SERVER["QUERY_STRING"] . "&reserveoverride=yes\">Yes</a>";
		$alertContent .= "<a class=\"btn btn-danger span3\" href=\"$baseurl/acc.php\">No</a>";
		$alertContent .= "</div>";
        
		BootstrapSkin::displayAlertBox($alertContent, "alert-info", "Warning!", true, false, false, true);
		BootstrapSkin::displayInternalFooter();
		die();
	}
	    
	if ($request->getStatus() == "Closed") {
		BootstrapSkin::displayAlertBox("Cannot close this request. Already closed.", "alert-error", "Error", true, false);
		BootstrapSkin::displayInternalFooter();
		die();
	}
	
	// Checks whether the username is already in use on Wikipedia.
	$userexist = file_get_contents("http://en.wikipedia.org/w/api.php?action=query&list=users&ususers=" . urlencode($request->getName()) . "&format=php");
	$ue = unserialize($userexist);
	if (!isset ($ue['query']['users']['0']['missing'])) {
		$exists = true;
	}
	else {
		$exists = false;
	}
	
	// check if a request being created does not already exist. 
	if ($gem == 1 && !$exists && !isset($_GET['createoverride'])) {
		$alertContent = "<p>You have chosen to mark this request as \"created\", but the account does not exist on the English Wikipedia, proceed?</p><br />";
		$alertContent .= "<div class=\"row-fluid\">";
		$alertContent .= "<a class=\"btn btn-success offset3 span3\"  href=\"$baseurl/acc.php?" . $_SERVER["QUERY_STRING"] . "&amp;createoverride=yes\">Yes</a>";
		$alertContent .= "<a class=\"btn btn-danger span3\" href=\"$baseurl/acc.php\">No</a>";
		$alertContent .= "</div>";
        
		BootstrapSkin::displayAlertBox($alertContent, "alert-info", "Warning!", true, false, false, true);
		BootstrapSkin::displayInternalFooter();
		die();
	}
	
	$messageBody = null;
    
	// custom close reasons
	if ($gem == 'custom') {
		if (!isset($_POST['msgbody']) or empty($_POST['msgbody'])) {
			// Send it through htmlspecialchars so HTML validators don't complain. 
			$querystring = htmlspecialchars($_SERVER["QUERY_STRING"], ENT_COMPAT, 'UTF-8'); 
            
			$template = false;
			if (isset($_GET['preload'])) {
				$template = EmailTemplate::getById($_GET['preload'], gGetDb());
			}
            
			if ($template != false) {
				$preloadTitle = $template->getName();
				$preloadText = $template->getText();
				$preloadAction = $template->getDefaultAction();
			}
			else {
				$preloadText = "";
				$preloadTitle = "";
				$preloadAction = "";
			}
            
			$smarty->assign("requeststates", $availableRequestStates);
			$smarty->assign("defaultAction", $preloadAction);
			$smarty->assign("preloadtext", $preloadText);
			$smarty->assign("preloadtitle", $preloadTitle);
			$smarty->assign("querystring", $querystring);
			$smarty->assign("request", $request);
			$smarty->assign("iplocation", $locationProvider->getIpLocation($request->getTrustedIp()));
			$smarty->display("custom-close.tpl");
			BootstrapSkin::displayInternalFooter();
			die();
		}

		$headers = 'From: accounts-enwiki-l@lists.wikimedia.org' . "\r\n";
		if (!User::getCurrent()->isAdmin() || isset($_POST['ccmailist']) && $_POST['ccmailist'] == "on") {
			$headers .= 'Cc: accounts-enwiki-l@lists.wikimedia.org' . "\r\n";
		}

		$headers .= 'X-ACC-Request: ' . $request->getId() . "\r\n";
		$headers .= 'X-ACC-UserID: ' . User::getCurrent()->getId() . "\r\n";

		// Get the closing user's Email signature and append it to the Email.
		if (User::getCurrent()->getEmailSig() != "") {
			$emailsig = html_entity_decode(User::getCurrent()->getEmailSig(), ENT_QUOTES, "UTF-8");
			mail($request->getEmail(), "RE: [ACC #{$request->getId()}] English Wikipedia Account Request", $_POST['msgbody'] . "\n\n" . $emailsig, $headers);
		}
		else {
			mail($request->getEmail(), "RE: [ACC #{$request->getId()}] English Wikipedia Account Request", $_POST['msgbody'], $headers);
		}

		$request->setEmailSent(1);
		$messageBody = $_POST['msgbody'];

		if ($_POST['action'] == EmailTemplate::CREATED || $_POST['action'] == EmailTemplate::NOT_CREATED) {
			$request->setStatus('Closed');

			if ($_POST['action'] == EmailTemplate::CREATED) {
				$gem  = 'custom-y';
				$crea = "Custom, Created";
			}
			else {
				$gem  = 'custom-n';
				$crea = "Custom, Not Created";
			}

			Logger::closeRequest(gGetDb(), $request, $gem, $messageBody);
			
			Notification::requestClosed($request, $crea);
			BootstrapSkin::displayAlertBox(
				"Request " . $request->getId() . " (" . htmlentities($request->getName(), ENT_COMPAT, 'UTF-8') . ") marked as 'Done'.", 
				"alert-success");
		}
		else if ($_POST['action'] == "mail") {
			// no action other than send mail!
			Logger::sentMail(gGetDb(), $request, $messageBody);
			Logger::unreserve(gGetDb(), $request);

			Notification::sentMail($request);
			BootstrapSkin::displayAlertBox("Sent mail to Request {$request->getId()}", 
				"alert-success");
		}
		else if (array_key_exists($_POST['action'], $availableRequestStates)) {
			// Defer

			$request->setStatus($_POST['action']);
			$deto = $availableRequestStates[$_POST['action']]['deferto'];
			$detolog = $availableRequestStates[$_POST['action']]['defertolog'];

			Logger::sentMail(gGetDb(), $request, $messageBody);
			Logger::deferRequest(gGetDb(), $request, $detolog);
			
			Notification::requestDeferredWithMail($request);
			BootstrapSkin::displayAlertBox("Request {$request->getId()} deferred to $deto, sending an email.", 
				"alert-success");
		}
		else {
			// hmm. not sure what happened. Log that we sent the mail anyway.
			Logger::sentMail(gGetDb(), $request, $messageBody);
			Logger::unreserve(gGetDb(), $request);

			Notification::sentMail($request);
			BootstrapSkin::displayAlertBox("Sent mail to Request {$request->getId()}", 
				"alert-success");
		}

		$request->setReserved(0);
		$request->save();
		
		$request->updateChecksum();
		$request->save();

		echo defaultpage();
		BootstrapSkin::displayInternalFooter();
		die();		
	}
	else {
		// Not a custom close, just a normal close
	    
		$request->setStatus('Closed');
		$request->setReserved(0);
		
		// TODO: make this transactional
		$request->save();
		
		Logger::closeRequest(gGetDb(), $request, $gem, $messageBody);
		
		if ($gem == '0') {
			$crea = "Dropped";
		}
		else {
			$template = EmailTemplate::getById($gem, gGetDb());
			$crea = $template->getName();
		}

		Notification::requestClosed($request, $crea);
		BootstrapSkin::displayAlertBox("Request " . $request->getId() . " (" . htmlentities($request->getName(), ENT_COMPAT, 'UTF-8') . ") marked as 'Done'.", "alert-success");
		
		$towhom = $request->getEmail();
		if ($gem != "0") {
			sendemail($gem, $towhom, $request->getId());
			$request->setEmailSent(1);
		}
		
		$request->updateChecksum();
		$request->save();
		
		echo defaultpage();
		BootstrapSkin::displayInternalFooter();
		die();
	}
}
elseif ($action == "zoom") {
	if (!isset($_GET['id'])) {
		BootstrapSkin::displayAlertBox("No request specified!", "alert-error", "Error!", true, false);
		BootstrapSkin::displayInternalFooter();
		die();
	}
    
	if (isset($_GET['hash'])) {
		$urlhash = $_GET['hash'];
	}
	else {
		$urlhash = "";
	}
	echo zoomPage($_GET['id'], $urlhash);
	BootstrapSkin::displayInternalFooter();
	die();
}
elseif ($action == "reserve") {
	$database = gGetDb();
    
	$database->transactionally(function() use ($database)
	{
		$request = Request::getById($_GET['resid'], $database);
        
		if ($request == false) {
			throw new TransactionException("Request not found", "Error");
		}
        
		global $enableEmailConfirm, $baseurl;
		if ($enableEmailConfirm == 1) {
			if ($request->getEmailConfirm() != "Confirmed") {
				throw new TransactionException("Email address not yet confirmed for this request.", "Error");
			}
		}

		$logQuery = $database->prepare(<<<SQL
SELECT timestamp FROM log
WHERE objectid = :request AND objecttype = 'Request' AND action LIKE 'Closed%'
ORDER BY timestamp DESC LIMIT 1;
SQL
		);
		$logQuery->bindValue(":request", $request->getId());
		$logQuery->execute();
		$logTime = $logQuery->fetchColumn();
		$logQuery->closeCursor();
        
		$date = new DateTime();
		$date->modify("-7 days");
		$oneweek = $date->format("Y-m-d H:i:s");
        
		if ($request->getStatus() == "Closed" && $logTime < $oneweek && !User::getCurrent($database)->isAdmin()) {
			throw new TransactionException("Only administrators and checkusers can reserve a request that has been closed for over a week.", "Error");
		}
        
	   	if ($request->getReserved() != 0 && $request->getReserved() != User::getCurrent($database)->getId()) {
			throw new TransactionException("Request is already reserved by {$request->getReservedObject()->getUsername()}.", "Error");
		}
           
		if ($request->getReserved() == 0) {
			// Check the number of requests a user has reserved already
			$doubleReserveCountQuery = $database->prepare("SELECT COUNT(*) FROM request WHERE reserved = :userid;");
			$doubleReserveCountQuery->bindValue(":userid", User::getCurrent($database)->getId());
			$doubleReserveCountQuery->execute();
			$doubleReserveCount = $doubleReserveCountQuery->fetchColumn();
			$doubleReserveCountQuery->closeCursor();

			// User already has at least one reserved. 
			if ($doubleReserveCount != 0) {
				SessionAlert::warning("You have multiple requests reserved!");
			}

			// Is the request closed?
			if (!isset($_GET['confclosed'])) {
				if ($request->getStatus() == "Closed") {
					// FIXME: bootstrappify properly
					throw new TransactionException('This request is currently closed. Are you sure you wish to reserve it?<br /><ul><li><a href="' . $_SERVER["REQUEST_URI"] . '&confclosed=yes">Yes, reserve this closed request</a></li><li><a href="' . $baseurl . '/acc.php">No, return to main request interface</a></li></ul>', "Request closed", "alert-info");
				}
			}	
        
			$request->setReserved(User::getCurrent($database)->getId());
			$request->save();
	
			Logger::reserve($database, $request);
                
			Notification::requestReserved($request);
                
			SessionAlert::success("Reserved request {$request->getId()}.");
		}
        
		header("Location: $baseurl/acc.php?action=zoom&id={$request->getId()}");
	});
	    
	die();	
}
elseif ($action == "breakreserve") {
	global $smarty;
    
	$database = gGetDb();
    
	$request = Request::getById($_GET['resid'], $database);
        
	if ($request == false) {
		BootstrapSkin::displayAlertBox("Could not find request.", "alert-error", "Error", true, false);
		BootstrapSkin::displayInternalFooter();
		die();
	}
    
	if ($request->getReserved() == 0) {
		BootstrapSkin::displayAlertBox("Request is not reserved.", "alert-error", "Error", true, false);
		BootstrapSkin::displayInternalFooter();
		die();
	}
    
	$reservedUser = $request->getReservedObject();
    
	if ($reservedUser == false) {
		BootstrapSkin::displayAlertBox("Could not find user who reserved the request (!!).", "alert-error", "Error", true, false);
		BootstrapSkin::displayInternalFooter();
		die();
	}
    
	if ($reservedUser->getId() != User::getCurrent()->getId()) {
		if (User::getCurrent()->isAdmin()) {
			if (isset($_GET['confirm']) && $_GET['confirm'] == 1) {
				$database->transactionally(function() use($database, $request)
				{
					$request->setReserved(0);
					$request->save();

					Logger::breakReserve($database, $request);
                
					Notification::requestReserveBroken($request);
					header("Location: acc.php");
				});
                
				die();
			}
			else {
				global $baseurl;
				$smarty->assign("reservedUser", $reservedUser);
				$smarty->assign("request", $request);
                
				$smarty->display("confirmations/breakreserve.tpl");
			}
		}
		else {
			echo "You cannot break " . htmlentities($reservedUser->getUsername()) . "'s reservation";
		}
	}
	else {
		$database->transactionally(function() use ($database, $request)
		{
			$request->setReserved(0);
			$request->save();

			Logger::unreserve($database, $request);
        
			Notification::requestUnreserved($request);
			header("Location: acc.php");
		});
        
		die();
	}
    
	BootstrapSkin::displayInternalFooter();
	die();		
}
elseif ($action == "comment") {
	global $smarty;
    
	$request = Request::getById($_GET['id'], gGetDb());
	$smarty->assign("request", $request);
	$smarty->display("commentform.tpl");
	BootstrapSkin::displayInternalFooter();
	die();
}
elseif ($action == "comment-add") {
	global $baseurl, $smarty;
    
	$request = Request::getById($_POST['id'], gGetDb());
	if ($request == false) {
		BootstrapSkin::displayAlertBox("Could not find request!", "alert-error", "Error", true, false);
		BootstrapSkin::displayInternalFooter();
		die();
	}
    
	if (!isset($_POST['comment']) || $_POST['comment'] == "") {
		BootstrapSkin::displayAlertBox("Comment must be supplied!", "alert-error", "Error", true, false);
		BootstrapSkin::displayInternalFooter();
		die(); 
	}
    
	$visibility = 'user';
	if (isset($_POST['visibility'])) {
		// sanity check
		$visibility = $_POST['visibility'] == 'user' ? 'user' : 'admin';
	}
    
	//Look for and detect IPv4/IPv6 addresses in comment text, and warn the commenter.
	if ((preg_match('/\b(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\b/', $_POST['comment']) || preg_match('/(([0-9a-fA-F]{1,4}:){7,7}[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,7}:|([0-9a-fA-F]{1,4}:){1,6}:[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,5}(:[0-9a-fA-F]{1,4}){1,2}|([0-9a-fA-F]{1,4}:){1,4}(:[0-9a-fA-F]{1,4}){1,3}|([0-9a-fA-F]{1,4}:){1,3}(:[0-9a-fA-F]{1,4}){1,4}|([0-9a-fA-F]{1,4}:){1,2}(:[0-9a-fA-F]{1,4}){1,5}|[0-9a-fA-F]{1,4}:((:[0-9a-fA-F]{1,4}){1,6})|:((:[0-9a-fA-F]{1,4}){1,7}|:)|fe80:(:[0-9a-fA-F]{0,4}){0,4}%[0-9a-zA-Z]{1,}|::(ffff(:0{1,4}){0,1}:){0,1}((25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])\.){3,3}(25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])|([0-9a-fA-F]{1,4}:){1,4}:((25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])\.){3,3}(25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9]))/', $_POST['comment'])) && $_POST['privpol-check-override'] != "override") {
			BootstrapSkin::displayAlertBox("IP address detected in comment text.  Warning acknowledgement checkbox must be checked.", "alert-error", "Error", true, false);
			$smarty->assign("request", $request);
			$smarty->assign("comment", $_POST['comment']);
			$smarty->assign("actionLocation", "comment-add");
			$smarty->display("privpol-warning.tpl");
			BootstrapSkin::displayInternalFooter();
			die();
		}
    
	$comment = new Comment();
	$comment->setDatabase(gGetDb());
    
	$comment->setRequest($request->getId());
	$comment->setVisibility($visibility);
	$comment->setUser(User::getCurrent()->getId());
	$comment->setComment($_POST['comment']);
    
	$comment->save();
    
	if (isset($_GET['hash'])) {
		$urlhash = urlencode(htmlentities($_GET['hash']));
	}
	else {
		$urlhash = "";
	}

	BootstrapSkin::displayAlertBox(
		"<a href='$baseurl/acc.php?action=zoom&amp;id={$request->getId()}&amp;hash=$urlhash'>Return to request #{$request->getId()}</a>",
		"alert-success",
		"Comment added Successfully!",
		true, false);
        
	Notification::commentCreated($comment);
        
	BootstrapSkin::displayInternalFooter();
	die();
}
elseif ($action == "comment-quick") {
	$request = Request::getById($_POST['id'], gGetDb());
	if ($request == false) {
		BootstrapSkin::displayAlertBox("Could not find request!", "alert-error", "Error", true, false);
		BootstrapSkin::displayInternalFooter();
		die();
	}
    
	if (!isset($_POST['comment']) || $_POST['comment'] == "") {
		header("Location: acc.php?action=zoom&id=" . $request->getId());
		die(); 
	}
    
	$visibility = 'user';
	if (isset($_POST['visibility'])) {
		// sanity check
		$visibility = $_POST['visibility'] == 'user' ? 'user' : 'admin';
	}

	//Look for and detect IPv4/IPv6 addresses in comment text, and warn the commenter.
	if ((preg_match('/\b(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\b/', $_POST['comment']) || preg_match('/(([0-9a-fA-F]{1,4}:){7,7}[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,7}:|([0-9a-fA-F]{1,4}:){1,6}:[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,5}(:[0-9a-fA-F]{1,4}){1,2}|([0-9a-fA-F]{1,4}:){1,4}(:[0-9a-fA-F]{1,4}){1,3}|([0-9a-fA-F]{1,4}:){1,3}(:[0-9a-fA-F]{1,4}){1,4}|([0-9a-fA-F]{1,4}:){1,2}(:[0-9a-fA-F]{1,4}){1,5}|[0-9a-fA-F]{1,4}:((:[0-9a-fA-F]{1,4}){1,6})|:((:[0-9a-fA-F]{1,4}){1,7}|:)|fe80:(:[0-9a-fA-F]{0,4}){0,4}%[0-9a-zA-Z]{1,}|::(ffff(:0{1,4}){0,1}:){0,1}((25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])\.){3,3}(25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])|([0-9a-fA-F]{1,4}:){1,4}:((25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])\.){3,3}(25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9]))/', $_POST['comment'])) && $_POST['privpol-check-override'] != "override") {
			BootstrapSkin::displayAlertBox("IP address detected in comment text.  Warning acknowledgement checkbox must be checked.", "alert-error", "Error", true, false);
			$smarty->assign("request", $request);
			$smarty->assign("comment", $_POST['comment']);
			$smarty->assign("actionLocation", "comment-quick");
			$smarty->display("privpol-warning.tpl");
			BootstrapSkin::displayInternalFooter();
			die();
		}
    
	$comment = new Comment();
	$comment->setDatabase(gGetDb());
    
	$comment->setRequest($request->getId());
	$comment->setVisibility($visibility);
	$comment->setUser(User::getCurrent()->getId());
	$comment->setComment($_POST['comment']);
    
	$comment->save();
    
	Notification::commentCreated($comment);
    
	header("Location: acc.php?action=zoom&id=" . $request->getId());
}
elseif ($action == "ec") {
	// edit comment
  
	global $smarty, $baseurl;
    
	$comment = Comment::getById($_GET['id'], gGetDb());
    
	if ($comment == false) {
		// Only using die("Message"); for errors looks ugly.
		BootstrapSkin::displayAlertBox("Comment not found.", "alert-error", "Error", true, false);
		BootstrapSkin::displayInternalFooter();
		die();
	}
	
	// Unauthorized if user is not an admin or the user who made the comment being edited.
	if (!User::getCurrent()->isAdmin() && !User::getCurrent()->isCheckuser() && $comment->getUser() != User::getCurrent()->getId()) {
		BootstrapSkin::displayAccessDenied();
		BootstrapSkin::displayInternalFooter();
		die();
	}
	
	// get[id] is safe by this point.
	
	if ($_SERVER['REQUEST_METHOD'] == 'POST') {
		$database = gGetDb();
		$database->transactionally(function() use ($database, $comment, $baseurl)
		{
            
			$comment->setComment($_POST['newcomment']);
			$comment->setVisibility($_POST['visibility']);
        
			$comment->save();
        
			Logger::editComment($database, $comment);
        
			Notification::commentEdited($comment);
        
			SessionAlert::success("Comment has been saved successfully");
			header("Location: $baseurl/acc.php?action=zoom&id=" . $comment->getRequest());
		});
        
		die();    
	}
	else {
		$smarty->assign("comment", $comment);
		$smarty->display("edit-comment.tpl");
		BootstrapSkin::displayInternalFooter();
		die();
	}
}
elseif ($action == "sendtouser") {
	global $baseurl;
    
	$database = gGetDb();
    
	$requestObject = Request::getById($_POST['id'], $database);
	if ($requestObject == false) {
		BootstrapSkin::displayAlertBox("Request invalid", "alert-error", "Could not find request", true, false);
		BootstrapSkin::displayInternalFooter();
		die();
	}
    
	$request = $requestObject->getId();
    
	$user = User::getByUsername($_POST['user'], $database);
	$curuser = User::getCurrent()->getUsername();
    
	if ($user == false) {
		BootstrapSkin::displayAlertBox("We couldn't find the user you wanted to send the reservation to. Please check that this user exists and is an active user on the tool.", "alert-error", "Could not find user", true, false);
		BootstrapSkin::displayInternalFooter();
		die();
	}
    
	$database->transactionally(function() use ($database, $user, $request, $curuser)
	{
		$updateStatement = $database->prepare("UPDATE request SET reserved = :userid WHERE id = :request LIMIT 1;");
		$updateStatement->bindValue(":userid", $user->getId());
		$updateStatement->bindValue(":request", $request);
		if (!$updateStatement->execute()) {
			throw new TransactionException("Error updating reserved status of request.");   
		}
        
		Logger::sendReservation($database, Request::getById($request, $database), $user);
	});
    
	Notification::requestReservationSent($request, $user);
	SessionAlert::success("Reservation sent successfully");
	header("Location: $baseurl/acc.php?action=zoom&id=$request");
}

elseif ($action == "emailmgmt") {
	global $smarty, $createdid, $availableRequestStates;
    
	/* New page for managing Emails, since I would rather not be handling editing
	interface messages (such as the Sitenotice) and the new Emails in the same place. */
	if (isset($_GET['create'])) {
		if (!User::getCurrent()->isAdmin()) {
			BootstrapSkin::displayAccessDenied();
			BootstrapSkin::displayInternalFooter();
			die();
		}
		if (isset($_POST['submit'])) {
			$database = gGetDb();
			$database->transactionally(function() use ($database)
			{
				global $baseurl;
                
				$emailTemplate = new EmailTemplate();
				$emailTemplate->setDatabase($database);
            
				$emailTemplate->setName($_POST['name']);
				$emailTemplate->setText($_POST['text']);
				$emailTemplate->setJsquestion($_POST['jsquestion']);
				$emailTemplate->setDefaultAction($_POST['defaultaction']);
				$emailTemplate->setActive(isset($_POST['active']));

				// Check if the entered name already exists (since these names are going to be used as the labels for buttons on the zoom page).
				// getByName(...) returns false on no records found.
				if (EmailTemplate::getByName($_POST['name'], $database)) {
					throw new TransactionException("That Email template name is already being used. Please choose another.");
				}
			
				$emailTemplate->save();
                
				Logger::createEmail($database, $emailTemplate);
                
				Notification::emailCreated($emailTemplate);
                
				SessionAlert::success("Email template has been saved successfully.");
				header("Location: $baseurl/acc.php?action=emailmgmt");
			});
            
			die();
		}
        
		$smarty->assign('id', null);
		$smarty->assign('createdid', $createdid);
		$smarty->assign('requeststates', $availableRequestStates);
		$smarty->assign('emailTemplate', new EmailTemplate());
		$smarty->assign('emailmgmtpage', 'Create'); //Use a variable so we don't need two Smarty templates for creating and editing.
		$smarty->display("email-management/edit.tpl");
		BootstrapSkin::displayInternalFooter();
		die();
	}
	if (isset($_GET['edit'])) {
		global $createdid;
        
		$database = gGetDb();
        
		if (isset($_POST['submit'])) {
			$emailTemplate = EmailTemplate::getById($_GET['edit'], $database);
			// Allow the user to see the edit form (with read only fields) but not POST anything.
			if (!User::getCurrent()->isAdmin()) {
				BootstrapSkin::displayAccessDenied();
				BootstrapSkin::displayInternalFooter();
				die();
			}
            
			$emailTemplate->setName($_POST['name']);
			$emailTemplate->setText($_POST['text']);
			$emailTemplate->setJsquestion($_POST['jsquestion']);
			
			if ($_GET['edit'] == $createdid) {
				// Both checkboxes on the main created message should always be enabled.
				$emailTemplate->setDefaultAction(EmailTemplate::CREATED);
				$emailTemplate->setActive(1);
				$emailTemplate->setPreloadOnly(0);
			}
			else {
				$emailTemplate->setDefaultAction($_POST['defaultaction']);
				$emailTemplate->setActive(isset($_POST['active']));
				$emailTemplate->setPreloadOnly(isset($_POST['preloadonly']));
			}
				
			// Check if the entered name already exists (since these names are going to be used as the labels for buttons on the zoom page).
			$nameCheck = EmailTemplate::getByName($_POST['name'], gGetDb());
			if ($nameCheck != false && $nameCheck->getId() != $_GET['edit']) {
				BootstrapSkin::displayAlertBox("That Email template name is already being used. Please choose another.");
				BootstrapSkin::displayInternalFooter();
				die();
			}

			$database->transactionally(function() use ($database, $emailTemplate)
			{
				$emailTemplate->save();
                
				Logger::editedEmail($database, $emailTemplate);
            
				global $baseurl;
                
				Notification::emailEdited($emailTemplate);
				SessionAlert::success("Email template has been saved successfully.");
				header("Location: $baseurl/acc.php?action=emailmgmt");
			});
            
			die();
		}
        
		$emailTemplate = EmailTemplate::getById($_GET['edit'], gGetDb());
		$smarty->assign('id', $emailTemplate->getId());
		$smarty->assign('emailTemplate', $emailTemplate);
		$smarty->assign('createdid', $createdid);
		$smarty->assign('requeststates', $availableRequestStates);
		$smarty->assign('emailmgmtpage', 'Edit'); // Use a variable so we don't need two Smarty templates for creating and editing.
		$smarty->display("email-management/edit.tpl");
		BootstrapSkin::displayInternalFooter();
		die();
	}
    
	$query = "SELECT * FROM emailtemplate WHERE active = 1";
	$statement = gGetDb()->prepare($query);
	$statement->execute();
	$rows = $statement->fetchAll(PDO::FETCH_CLASS, "EmailTemplate");
	$smarty->assign('activeemails', $rows);
        
	$query = "SELECT * FROM emailtemplate WHERE active = 0";
	$statement = gGetDb()->prepare($query);
	$statement->execute();
	$inactiverows = $statement->fetchAll(PDO::FETCH_CLASS, "EmailTemplate");
	$smarty->assign('inactiveemails', $inactiverows);
 
	if (count($inactiverows) > 0) {
		$smarty->assign('displayinactive', true);
	}
	else {
		$smarty->assign('displayinactive', false);
	}
    
	$smarty->display("email-management/main.tpl");
	BootstrapSkin::displayInternalFooter();
	die();
}

elseif ($action == "oauthdetach") {
	if ($enforceOAuth) {
		BootstrapSkin::displayAccessDenied();
		BootstrapSkin::displayInternalFooter();
		die();
	}
    
	global $baseurl;
        
	$currentUser = User::getCurrent();
	$currentUser->detachAccount();
        
	header("Location: {$baseurl}/acc.php?action=logout");
}
elseif ($action == "oauthattach") {
	$database = gGetDb();
	$database->transactionally(function() use ($database)
	{
		try {
			global $oauthConsumerToken, $oauthSecretToken, $oauthBaseUrl, $oauthBaseUrlInternal;
            
			$user = User::getCurrent();
            
			// Get a request token for OAuth
			$util = new OAuthUtility($oauthConsumerToken, $oauthSecretToken, $oauthBaseUrl, $oauthBaseUrlInternal);
			$requestToken = $util->getRequestToken();

			// save the request token for later
			$user->setOAuthRequestToken($requestToken->key);
			$user->setOAuthRequestSecret($requestToken->secret);
			$user->save();
        
			$redirectUrl = $util->getAuthoriseUrl($requestToken);
        
			header("Location: {$redirectUrl}");
        
		}
		catch (Exception $ex) {
			throw new TransactionException($ex->getMessage(), "Connection to Wikipedia failed.", "alert-error", 0, $ex);
		}
	});
}
# If the action specified does not exist, goto the default page.
else {
	ob_end_clean();
	global $baseurl;
	header("Location: $baseurl/internal.php");
	die();
}
