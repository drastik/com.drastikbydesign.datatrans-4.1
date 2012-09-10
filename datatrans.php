<?php
/*
 * Payment Processor class for DataTrans.
 * Joshua Walker (drastik) http://drastikbydesign.com
 */
class com_drastikbydesign_datatrans extends CRM_Core_Payment {
  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  static private $_singleton = null;

  /**
   * mode of operation: live or test
   *
   * @var object
   * @static
   */
  static protected $_mode = null;

  /**
   * Constructor
   *
   * @param string $mode
   *   The mode of operation: live or test.
   *
   * @return void
   */
  function __construct($mode, &$paymentProcessor) {
    $this->_mode             = $mode;
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName    = ts('DataTrans');
  }

  /**
   * Singleton function used to manage this object.
   *
   * @param string $mode
   *   The mode of operation: live or test.
   *
   * @return object
   * @static
   *
   */
  static function &singleton($mode, &$paymentProcessor) {
      $processorName = $paymentProcessor['name'];
      if (self::$_singleton[$processorName] === NULL ) {
          self::$_singleton[$processorName] = new self($mode, $paymentProcessor);
      }
      return self::$_singleton[$processorName];
  }

  /**
   * This function checks to see if we have the right config values.
   *
   * @return string
   *   The error message if any.
   * @public
   */
  function checkConfig() {
    $config = CRM_Core_Config::singleton();
    $error = array();

    if (empty($this->_paymentProcessor['user_name'])) {
      $error[] = ts('The "merchantId" is not set in the DataTrans Payment Processor settings.');
    }

    if (empty($this->_paymentProcessor['password'])) {
      $error[] = ts('The "HMAC" is not set in the DataTrans Payment Processor settings.');
    }

    if (!empty($error)) {
      return implode('<p>', $error);
    }
    else {
      return NULL;
    }
  }

  /**
   * DataTrans uses Notify / Transfer method instead of doDirectPayment.
   */
  function doDirectPayment(&$params) {
    CRM_Core_Error::fatal(ts('DataTrans does not implement Direct Payment method (use 4/notify instead).'));
  }

  /**
   * CiviCRM Notify / Transfer method payment.
   * This is fired when you click to make your contribution.
   *
   * @param array $params
   *   Name-value pair of contribution data.
   * @param string $component
   *   contribute or event
   *
   * @return void
   * @access public
   *
   */
  function doTransferCheckout(&$params, $component) {
    $config = CRM_Core_Config::singleton();

    // Prepare return URLs.
    switch ($component) {
      case 'contribute':
        $dt_params['successUrl'] =  $config->userFrameworkBaseURL .
          'civicrm/contribute/transact%3F_qf_ThankYou_display=1%26qfKey='. $params['qfKey'];
        $dt_params['errorUrl'] = $config->userFrameworkBaseURL .
          'civicrm/contribute/transact%3F_qf_Main_display=1%26cancel=1%26qfKey='. $params['qfKey'];
        $dt_params['cancelUrl'] =  $config->userFrameworkBaseURL .
          'civicrm/contribute/transact%3F_qf_Confirm_display=true%26qfKey='. $params['qfKey'];
        break;
      case 'event':
        $dt_params['successUrl'] =  $config->userFrameworkBaseURL .
          'civicrm/event/register%3F_qf_ThankYou_display=1%26qfKey='. $params['qfKey'];
        $dt_params['errorUrl'] =  $config->userFrameworkBaseURL .
          'civicrm/event/register%3F_qf_Confirm_display=true%26qfKey='. $params['qfKey'];
        $dt_params['cancelUrl'] =  $config->userFrameworkBaseURL .
          'civicrm/event/register%3F_qf_Confirm_display=true%26qfKey='. $params['qfKey'];
        break;
    }

    //  Prepare manditory parameters.
    $dt_params['merchantId'] = $this->_paymentProcessor['user_name'];
    $dt_params['language'] = 'en';
    $dt_params['reqtype'] = 'CAA';
    $dt_params['refno'] = $params['invoiceID'];
    $dt_params['qfKey'] = $params['qfKey'];
    $dt_params['mode'] = $this->_mode;
    $dt_params['component'] = $component;
    $dt_params['payment_processor_id'] = $this->_paymentProcessor['id'];
    $dt_params['contactID'] = $params['contactID'];
    $dt_params['contributionID'] = $params['contributionID'];
    $dt_params['contributionTypeID'] = $params['contributionTypeID'];

    //  Conditional parameters based on contribution or event.
    switch($component) {
      case 'contribute':
        if(isset($params['membershipID'])) {
          $dt_params['membershipID'] = $params['membershipID'];
        }
        break;

      case 'event':
        $dt_params['participantID'] = $params['participantID'];
        $dt_params['eventID'] = $params['eventID'];
        break;
    }

    //  Amount required in smallest unit of currency.
    //  @TODO Handle other currencies.
    switch($params['currencyID']) {
      //  Example handling of currency rule.  Obviously default can handle USD.
      case 'USD':
        $dt_params['amount'] = $params['amount'] * 100;
        break;
      //  Default currencies to Native * 100 = Smallest Unit.
      default:
        $dt_params['amount'] = $params['amount'] * 100;
        break;
    }

    //  @TODO Handle switching currency code if any require.  (Civi/DT mismatch).
    $dt_params['currency'] = $params['currencyID'];

    //  Prepare Customer details.
    //  Turn on Name & Address profile to capture more info.
    if(isset($params['email'])) {
      $email = $params['email'];
    } elseif(isset($params['email-5'])) {
      $email = $params['email-5'];
    } elseif(isset($params['email-Primary'])) {
      $email = $params['email-Primary'];
    }

    if(isset($email)) {
      $dt_params['uppCustomerDetails'] = 'yes';
      $dt_params['uppCustomerEmail'] = $email;
      if(!empty($params['first_name'])) {
        $dt_params['uppCustomerFirstName'] = $params['first_name'];
      }
      if(!empty($params['last_name'])) {
        $dt_params['uppCustomerLastName'] = $params['last_name'];
      }
      if(!empty($params['street_address-1'])) {
        $dt_params['uppCustomerStreet'] = $params['street_address-1'];
      }
      if(!empty($params['city-1'])) {
        $dt_params['uppCustomerCity'] = $params['city-1'];
      }
      if(!empty($params['postal_code-1'])) {
        $dt_params['uppCustomerZipCode'] = $params['postal_code-1'];
      }
    }

    //  @TODO Does DT allow for a payment description?  This does nothing for now.
    $payment_description = '# CiviCRM Donation Page # ' . $params['description'] .  ' # Invoice ID # ' . $params['invoiceID'];

    //  Create the 'sign' parameter for HMAC Security option.
    $dt_hmac = pack("H*", $this->_paymentProcessor['password']);
    $dt_params['sign'] = hash_hmac('md5', $this->_paymentProcessor['user_name'].
      $dt_params['amount'].$dt_params['currency'].$dt_params['refno'], $dt_hmac);

    //  Allow further manipulation of the arguments via custom hooks.
    CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $dt_params);

    // Prepare query string & remove ending '&'.
    $dt_post_string = '';
    foreach ($dt_params as $name => $value) {
      $dt_post_string .= $name . '=' . $value . '&';
    }
    $dt_post_string = rtrim($dt_post_string, '&');

    // Fire away!
    CRM_Utils_System::redirect($this->_paymentProcessor['url_site'] . '?' . $dt_post_string);
  }
}
