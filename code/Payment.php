<?php 

/**
 * "Abstract" class for a number of payment models
 * 
 *  @package payment
 */
class Payment extends DataObject {
  /**
   * Get the payment gateway class name from the associating module name
   * 
   * @param $gatewayName
   * @return gateway class name
   * 
   * TODO: Generalize naming convention
   *       Take care of merchant hosted and gateway hosted sub classes
   */
  public static function gatewayClassName($gatewayname) {
    $className = $gatewayname . "_Payment";
    if (class_exists($className)) {
      return $className;
    } else {
      user_error("Payment gateway class is not defined", E_USER_ERROR);
    }
  }
  
  /**
   * Incomplete (default): Payment created but nothing confirmed as successful
   * Success: Payment successful
   * Failure: Payment failed during process
   * Pending: Payment awaiting receipt/bank transfer etc
   */
  public static $db = array(
    'Status' => "Enum('Incomplete, Success, Failure, Pending')",
    'Amount' => 'Money',
    'Message' => 'Text',
    'PaidForID' => "Int",
    'PaidForClass' => 'Varchar',

    //Used for storing any Exception during this payment Process.
    'ExceptionError' => 'Text'
  );
  
  public static $has_one = array(
    'PaidObject' => 'Object',
    'PaidBy' => 'Member',
  );
  
  /**
   * Make payment table transactional.
   */
  static $create_table_options = array(
    'MySQLDatabase' => 'ENGINE=InnoDB'
  );
  
  /**
   * The currency code used for payments.
   * @var string
   */
  protected static $site_currency = 'USD';
  
  /**
   * Set the currency code that this site uses.
   * @param string $currency Currency code. e.g. "NZD"
   */
  public static function set_site_currency($currency) {
    self::$site_currency = $currency;
  }
  
  /**
   * Return the site currency in use.
   * @return string
   */
  public static function site_currency() {
    return self::$site_currency;
  }
  
  function populateDefaults() {
    parent::populateDefaults();
  
    $this->Amount->Currency = Payment::site_currency();
  }
}

class Payment_MerchantHosted extends Payment {
  /**
   * The payment form fields that should be shown on the checkout order form
   */
  public $formFields;
  public $requiredFormFields;
  
  protected static $cvn_mode = true;
  
  public function __construct() {
    parent::__construct();
    
    $this->formFields = new FieldList();
    $this->requiredFormFields = array();
  }
  
  public function getCreditCardFields() {
    $fields = new FieldList(
        new TextField('CardHolderName', 'Credit Card Holder Name :'),
        new CreditCardField('CardNumber', 'Credit Card Number :'),
        new TextField('DateExpiry', 'Credit Card Expiry : (MMYY)', '', 4)
    );
    
    if (self::$cvn_mode) {
      $fields->push(new TextField('Cvc2', 'Credit Card CVN : (3 or 4 digits)', '', 4));
    }
    return $fields;
  }
  
  public function getFormFields() {
    // Other business fields
    
    // Credit card fields
    $ccFields = $this->getCreditCardFields();
    foreach ($ccFields as $ccFields) {
      $this->formFields->push($ccField);
    }
  }
  
  public function getFormRequirements() {
    
  }
}

class Payment_GatewayHosted extends Payment {
  
}

/**
 *  Interface for a payment gateway controller
 */
interface Payment_Controller_Interface {

  public function processRequest($data);
  public function processResponse($response);
}

/**
 * Abstract class for a number of payment controllers.
 */
class Payment_Controller extends Controller implements Payment_Controller_Interface {

  static $URLSegment;

  /**
   * The payment object to be injected to this controller
   */
  public $payment;

  /**
   * Return the relative url for completing payment
   */
  public function complete_link() {
    // Bypass the inheritance limitation
    $class = get_class($this);
    return $class::$URLSegment . '/complete';
  }

  /**
   * Return the relative url for cancelling payment
   */
  public function cancel_link() {
    // Bypass the inheritance limitation
    $class = get_class($this);
    return $class::$URLSegment . '/cancel';
  }

  /**
   * Construct a controller with an injected payment object
   */
  public static function initWithPayment($payment) {
    // construct the correct child class
    $class = get_called_class();
    $instance = new $class();

    $instance->payment = $payment;
    return $instance;
  }

  /**
   * Get the controller's class name from a given gateway module name and config
   * TODO: Generalize naming convention; make use of _config.php
   *
   * @param $gatewayName
   * @return Controller class name
   */
  public static function controllerClassName($gatewayName) {
    $paymentClass = Payment::gatewayClassName($gatewayName);
    $controllerClass = $paymentClass . "_Controller";

    if (class_exists($controllerClass)) {
      return $controllerClass;
    } else {
      user_error("Controller class is not defined", E_USER_ERROR);
    }
  }

  /**
   * Process a payment from an order form.
   * TODO: If this function becomes unneccesseary, omit it.
   *
   * @param $data
   */
  public function processPayment($data) {
    if (! isset($data['Amount'])) {
      user_error("Payment amount not set!", E_USER_ERROR);
    } else {
      $amount = $data['Amount'];
    }

    if (! isset($data['Currency'])) {
      $currency = $data['Currency'];
    } else {
      $currency = $this->payment->site_currency();
    }

    // Save preliminary data to database
    $this->payment->Amount->Amount = $amount;
    $this->payment->Amount->Currency = $currency;
    $this->payment->Status = 'Pending';
    $this->payment->write();

    // Process payment
    $this->processRequest($data);
  }

  /**
   * Process a payment request, to be implemented by specific gateways
   *
   * @param $data
   * @return Payment_Result
   */
  public function processRequest($data) {
    user_error("Please implement processRequest() on $this->class", E_USER_ERROR);
  }

  /**
   * Process a payment response, to be implemented by specific gateways.
   * This function should return the ID of the payment
   */
  public function processResponse($response) {
    user_error("Please implement processResponse() on $this->class", E_USER_ERROR);
  }

  /**
   * Get the payment ID from the gateway response. To be implemented by specific gateways
   */
  public function getPaymentID($response) {
    user_error("Please implement getPaymentID() on $this->class", E_USER_ERROR);
  }

  /**
   * Private helper function to update payment status
   * for the returned payment. This function is called by
   * compelete() and cancel()
   *
   * @param unknown_type $paymentID
   * @param unknown_type $status
   *
   * @return Payment object
   */
  private function updatePaymentStatus($request, $status) {
    $paymentID = $this->getPaymentID($request);
    if ($payment = Payment::get()->byID($paymentID)) {
      $payment->Status = $status;
      $payment->write();
      return $payment;
    } else {
      user_error("Cannot load the corresponding payment of id $paymentID", E_USER_ERROR);
    }
  }

  /**
   * Payment complete handler.
   * This function should be persistent accross all payment gateways.
   * Additional processing of payment response can be done in processResponse().
   */
  public function complete($request) {
    // Additional processing
    $this->processResponse($request);
    // Update payment status
    $payment = $this->updatePaymentStatus($request, 'Success');

    return $payment->renderWith($payment->class . "_complete");
  }

  /**
   * Payment cancel handler
   */
  public function cancel($request) {
    // Additional processing
    $this->processResponse($request);
    // Update payment status
    $payment = $this->updatePaymentStatus($request, 'Incomplete');

    return $payment->renderWith($payment->class . "_cancel");
  }
}