<?php
namespace Waca;

use \Offline;
use Waca\Providers\GlobalStateProvider;

/**
 * Internal application entry point.
 *
 * @package Waca
 */
class WebStart
{
	/**
	 * Application entry point.
	 *
	 * Sets up the environment and runs the application, performing any global cleanup operations when done.
	 */
	public function run()
	{
		if ($this->setupEnvironment()) {
			$this->main();
			$this->cleanupEnvironment();
		}
	}

	/**
	 * Global exception handler
	 *
	 * Smarty would be nice to use, but it COULD BE smarty that throws the errors.
	 * Let's build something ourselves, and hope it works.
	 *
	 * @param $exception
	 */
	public static function exceptionHandler($exception)
	{
		global $baseurl, $filepath;

		$errorDocument = <<<HTML
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8">
<title>Oops! Something went wrong!</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="{$baseurl}/lib/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<style>
  body {
    padding-top: 60px;
  }
</style>
<link href="{$baseurl}/lib/bootstrap/css/bootstrap-responsive.min.css" rel="stylesheet">
</head><body><div class="container">
<h1>Oops! Something went wrong!</h1>
<p>We'll work on fixing this for you, so why not come back later?</p><p class="muted">If our trained monkeys ask, tell them this:</p><pre>$1$</pre>
</div></body></html>
HTML;

		$message = "Unhandled " . $exception;

		// strip out database passwords from the error message
		// TODO

		// clean any secret variables, including the first 15 chars of the database password (yay php.)
		// TODO
		//$message = str_replace($mycnf['user'], "webserver", $message);
		//$message = str_replace($mycnf['password'], "sekrit", $message);
		//$message = str_replace(substr($mycnf['password'], 0, 15) . '...', "sekrit", $message);
		$message = str_replace($filepath, "", $message);

		// clear and discard any content that's been saved to the output buffer
		ob_end_clean();

		// push exception into the document.
		$message = str_replace('$1$', $message, $errorDocument);

		// output the document
		print $message;

		die;
	}

	/**
	 * Environment setup
	 *
	 * This method initialises the tool environment. If the tool cannot be initialised correctly, it will return false
	 * and shut down prematurely.
	 *
	 * @return bool
	 */
	private function setupEnvironment()
	{
		// initialise global exception handler
		set_exception_handler(array(WebStart::class, "exceptionHandler"));

		// start output buffering
		ob_start();

		// initialise superglobal providers
		WebRequest::setGlobalStateProvider(new GlobalStateProvider());

		// check the tool is still online
		if (Offline::isOffline()) {
			print Offline::getOfflineMessage(false);
			ob_end_flush();
			return false;
		}

		Session::start();

		// environment initialised!
		return true;
	}

	/**
	 * Main application logic
	 */
	private function main()
	{
		// Get the right route for the request
		$router = new RequestRouter();
		$page = $router->route();

		// run the route code for the request.
		$page->execute();
	}

	/**
	 * Any cleanup tasks should go here
	 */
	private function cleanupEnvironment()
	{
		// Clean up anything we splurged after sending the page.
		ob_end_clean();
	}
}