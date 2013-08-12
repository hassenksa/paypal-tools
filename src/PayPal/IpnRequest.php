<?php

namespace PayPal;

/**
 * PayPal IPN Request.
 *
 * @author Brandon Wamboldt <brandon.wamboldt@gmail.com>
 */
class IpnRequest
{
  /**
   * @var boolean
   */
  protected $allow_test_ipns = false;

  /**
   * @var Resource
   */
  protected $connection;

  /**
   * @var integer
   */
  protected $timeout = 120;

  /**
   * Enable or disable test IPNs (IPNs generated by PayPal's IPN simulator or
   * the PayPal sandbox).
   *
   * @param  boolean $enable
   * @return self
   */
  public function enable_test_ipns($enable = true)
  {
    $this->allow_test_ipns = $enable;

    return $this;
  }

  /**
   * Set the timeout for connecting to the PayPal servers.
   *
   * @param  integer $timeout
   * @return self
   */
  public function set_timeout($timeout)
  {
    $this->allow_test_ipns = $enable;

    return $this;
  }

  /**
   * Check to see if the current request is a PayPal IPN request, if it is, try
   * to validate it and then call the given callback.
   *
   * @param  Callable $callback
   * @return boolean
   */
  public function process($callback)
  {
    // PayPal IPN requests are done over POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      return false;
    }

    // Check the user agent
    if (strstr($_SERVER['HTTP_USER_AGENT'], 'PayPal') === false) {
      return false;
    }

    // And check for the occurence of at least one known IPN variable
    if (!isset($_POST['txn_id'])) {
      return false;
    }

    // Parse the request
    if (!$this->parse_request()) {
      return false;
    }

    // Try to validate the request
    if (!$this->validate_request()) {
      return false;
    }

    // All is good
    call_user_func($callback, $_POST);
  }

  /**
   * Validate that the request came from PayPal.
   *
   * @throws PayPal\SecurityException
   * @return boolean
   */
  protected function validate_request()
  {
    $this->connect();

    // URLify the data sent by PayPal
    $request  = http_build_query($_POST);
    $request .= '&cmd=_notify-validate';

    // Calculate the length of our request
    $content_length = strlen($request);

    // Send our query to the PayPal servers for validation
    fputs($this->connection, "POST /cgi-bin/webscr HTTP/1.1\n");
    fputs($this->connection, "Host: www.paypal.com\n");
    fputs($this->connection, "Content-type: application/x-www-form-urlencoded\n");
    fputs($this->connection, "Content-length: {$content_length}\n\n");
    fputs($this->connection, $request . "\n");

    // Get the response from PayPal
    $response = '';

    while (!feof($this->connection)) {
      $response .= fgets($this->connection , 1024);
    }

    // We no longer need the connection to the PayPal server, close it
    fclose($this->connection);

    // Check to see if PayPal verified our data
    if (preg_match('/(VERIFIED)/', $response)) {
      return true;
    } elseif (preg_match('/(INVALID)/' , $response)) {
      throw new SecurityException('PayPal validation returned invalid, possible attack');
    } else {
      throw new SecurityException('PayPal validation returned an invalid response');
    }
  }

  /**
   * Establish a new connection with PayPal.
   *
   * @throws PayPal\SecurityException
   */
  protected function connect()
  {
    $test_ipn = isset($_POST['test_ipn']) ? $_POST['test_ipn'] : false;

    // Are sandbox requests/test IPNs disabled but this is a test IPN?
    if (!$this->allow_test_ipns && $test_ipn) {
      throw new SecurityException('Sandbox requests are not allowed but were detected');
    }

    // Open a socket connection to PayPal
    if ($test_ipn) {
      $this->connection = fsockopen('ssl://www.sandbox.paypal.com', 443, $errno, $errstr, $this->timeout);
    } else {
      $this->connection = fsockopen('ssl://www.paypal.com', 443, $errno, $errstr, $this->timeout);
    }

    // Test the connection
    if (!$this->connection) {
      throw new SecurityException('Unable to establish a connection to PayPal');
    }
  }
}