<?php
/*
 * Payment Processor IPN class for DataTrans.
 * Joshua Walker (drastik) http://drastikbydesign.com
 */
require_once 'CRM/Core/Payment/BaseIPN.php';
class CRM_Core_Payment_DataTransIPN extends CRM_Core_Payment_BaseIPN {

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  static private $_singleton = NULL;

  /**
   * mode of operation: live or test
   *
   * @var object
   * @static
   */
  static protected $_mode = NULL;

/**
 * Wrapper for CRM_Utils_Array::value().
 *
 * @param  string  $name
 *   Name of index for value being looked up.
 * @param  string  $type
 *   What type of value to look for.
 * @param  array  $object
 *   The array to look in.
 * @param  boolean $abort
 * @return string
 *   The value that was retrieved.
 */
  static function retrieve($name, $type, $object, $abort = TRUE) {
    $value = CRM_Utils_Array::value($name, $object);
    if ($abort && $value === NULL) {
      CRM_Core_Error::debug_log_message("Could not find an entry for {$name}");
      print "Failure: Missing Parameter - " . $name . "<p>";
      exit();
    }

    if ($value) {
      if (!CRM_Utils_Type::validate($value, $type)) {
        CRM_Core_Error::debug_log_message("Could not find a valid entry for {$name}");
        print "Failure: Invalid Parameter<p>";
        exit();
      }
    }

    return $value;
  }

  /**
   * Constructor
   *
   * @param string $mode
   *   The mode of operation: live or test.
   *
   *
   * @return void
   */
  function __construct($mode, &$paymentProcessor) {
    parent::__construct();

    $this->_mode = $mode;
    $this->_paymentProcessor = $paymentProcessor;
  }

  /**
   * singleton function used to manage this object
   *
   * @param string $mode
   *   The mode of operation: live or test
   *
   * @return object
   * @static
   */
  static function &singleton($mode, $component, &$paymentProcessor) {
    if (self::$_singleton === NULL) {
      self::$_singleton = new self($mode, $paymentProcessor);
    }
    return self::$_singleton;
  }

  /**
   * The function gets called when a new order takes place.
   *
   * @param array $post_data_exp
   *   Response from DataTrans
   * @param decimal $formatted_amount
   *   The native display of amount (e.g. in USD: 10.50).
   *
   * @return void
   *
   */
  function newOrderNotify($post_data_exp, $formatted_amount) {
    $ids = $input = $params = array();

    $input['component'] = $post_data_exp['component'];

    $ids['contact'] = self::retrieve('contactID', 'Integer', $post_data_exp, TRUE);
    $ids['contribution'] = self::retrieve('contributionID', 'Integer', $post_data_exp, TRUE);

    if ($input['component'] == "event") {
      $ids['event']       = self::retrieve('eventID', 'Integer', $post_data_exp, TRUE);
      $ids['participant'] = self::retrieve('participantID', 'Integer', $post_data_exp, TRUE);
      $ids['membership']  = NULL;
    } else {
      $ids['membership'] = self::retrieve('membershipID', 'Integer', $post_data_exp, FALSE);
    }
    $ids['contributionRecur'] = $ids['contributionPage'] = NULL;

    if (!$this->validateData($input, $ids, $objects, TRUE, $post_data_exp['payment_processor_id'])) {
      return FALSE;
    }

    // Make sure the invoice is valid and matches what we have in the contribution record.
    $input['invoice']    = $post_data_exp['refno'];
    $input['newInvoice'] = $post_data_exp['uppTransactionId'];
    $contribution        = &$objects['contribution'];
    $input['trxn_id']    = $post_data_exp['uppTransactionId'];

    if ($contribution->invoice_id != $input['invoice']) {
      CRM_Core_Error::debug_log_message("Invoice values dont match between database and IPN request");
      print "Failure: Invoice values dont match between database and IPN request<p>";
      return;
    }

    // Replace invoice-id with Payment Processor trxn_id.
    $contribution->invoice_id = $input['newInvoice'];

    $input['amount'] = $formatted_amount;

    if ($contribution->total_amount != $input['amount']) {
      CRM_Core_Error::debug_log_message("Amount values dont match between database and IPN request");
      print "Failure: Amount values dont match between database and IPN request. " . $contribution->total_amount . "/" . $input['amount'] . "<p>";
      return;
    }
    require_once 'CRM/Core/Transaction.php';
    $transaction = new CRM_Core_Transaction();
    // Check if contribution is already completed, if so we ignore this ipn.
    if ($contribution->contribution_status_id == 1) {
      CRM_Core_Error::debug("Returning since contribution has already been handled");
      print "Success: Contribution has already been handled<p>";
      return TRUE;
    }
    $this->completeTransaction($input, $ids, $objects, $transaction);
    return TRUE;
  }

   /**
   * The function returns whether this transaction has already been handled.
   *
   * @param string @component
   *   event/contribute
   * @param array $post_data_exp
   *   Contains the name-value pairs of transaction response data.
   * @param string $dt_trxn_id
   *   Transaction ID from DT response.
   *
   * @return boolean
   *   Has this transaction been handled?  TRUE/FALSE.
   * @static
   */
  static function getContext($component, $post_data_exp, $dt_trxn_id) {
    require_once 'CRM/Contribute/DAO/Contribution.php';
    $contributionID = $post_data_exp['contributionID'];
    $contribution = new CRM_Contribute_DAO_Contribution();
    $contribution->id = $contributionID;
    /*
     * @TODO For recurring?
     * if(new contrib)
     * $contribution->invoice_id = $dt_trxn_id;
     */
    if (!$contribution->find(TRUE)) {
      CRM_Core_Error::debug_log_message("Could not find contribution record: {$contributionID}");
      print "Failure: Could not find contribution record for $contributionID<p>";
      exit();
    }

    $duplicate_transaction = FALSE;
    if ($contribution->contribution_status_id == 1) {
      //  Contribution already handled.
      $duplicate_transaction = TRUE;
    }

    if ($component == 'contribute') {
      if (empty($contribution->contribution_page_id)) {
        CRM_Core_Error::debug_log_message("Could not find contribution page for contribution record: {$contributionID}");
        print "Failure: Could not find contribution page for contribution record: $contributionID<p>";
        exit();
      }
    } else {
      if (!empty($post_data_exp['eventID'])) {
        require_once 'CRM/Event/DAO/Event.php';
        $eventID = $post_data_exp['eventID'];
        // Make sure event exists and is valid.
        $event = new CRM_Event_DAO_Event();
        $event->id = $eventID;
        if (!$event->find(TRUE)) {
          CRM_Core_Error::debug_log_message("Could not find event: {$eventID}");
          print "Failure: Could not find event: $eventID<p>";
          exit();
        }
      } else {
        CRM_Core_Error::debug_log_message("Could not find event ID");
        print "Failure: Could not find eventID<p>";
        exit();
      }
    }

    return $duplicate_transaction;
  }

/**
 * Handles the response sent by the payment processor.
 * This is the entry point from datatransNotify.php.
 *
 * @param string $method
 *   Security method.  Always set to HMAC (highest) for now.
 * @param array $post_data
 *   The payment processor response data.
 */
  static function main($method, $post_data) {
    if(is_array($post_data)) {
      $post_data_exp = $post_data;
    } else {
      parse_str($post_data, $post_data_exp);
    }

    require_once 'CRM/Core/BAO/PaymentProcessor.php';
    $paymentProcessor = CRM_Core_BAO_PaymentProcessor::getPayment($post_data_exp['payment_processor_id'], $post_data_exp['mode']);
    $ipn = self::singleton($post_data_exp['mode'], $post_data_exp['component'], $paymentProcessor);

    //  Map errors to their definition and reroute to error page.
    if (isset($post_data_exp['errorCode'])) {
      $error_message = $ipn->_datatrans_map_error_code($post_data_exp['errorCode']);
      CRM_Core_Error::debug_log_message("DataTrans Response: {$error_message}");
      exit();
    }
    //  Handle canceled transactions & extra error layer.
    if (isset($post_data_exp['status'])) {
      switch($post_data_exp['status']) {
        case 'error':
        //  This is for errors other than dealing w/ the card.
          $error_msg = "DataTrans Error, but no errorCode.";
          if(!empty($post_data_exp[errorDetail])) {
            $error_msg .= $post_data_exp[errorDetail];
          }
          if(!empty($post_data_exp[errorMessage])) {
            $error_msg .= $post_data_exp[errorMessage];
          }
          CRM_Core_Error::debug_log_message($error_msg);
          exit();
          break;

        case 'cancel':
          CRM_Core_Error::debug_log_message("Transaction canceled in DataTrans. {$cancel_url}");
          exit();
          break;
      }
    }
    //  Check if this transaction has already been handled.
    $duplicate_transaction = self::getContext($post_data_exp['component'], $post_data_exp, $post_data_exp['uppTransactionId']);
    if (!$duplicate_transaction) {
      $formatted_amount = $post_data_exp['amount'] / 100;
      $ipn->newOrderNotify($post_data_exp, $formatted_amount);
    }
    //CRM_Utils_System::redirect($success_url);
  }

/**
 * Error code mapping.
 * @param  integer $code
 *   Error code number from DataTrans response.
 * @return string $message
 *   Message with details on what the error code means.
 */
  function _datatrans_map_error_code($code) {
      switch ($code) {
        case '1001':
          $message = 'Datrans transaction failed: missing required parameter.';
          break;

        case '1002':
          $message = 'Datrans transaction failed: invalid parameter format.';
          break;

        case '1003':
          $message = 'Datatrans transaction failed: value of parameter not found.';
          break;

        case '1004':
        case '1400':
          $message = 'Datatrans transaction failed: invalid card number.';
          break;

        case '1007':
          $message = 'Datatrans transaction failed: access denied by sign control/parameter sign invalid.';
          break;

        case '1008':
          $message = 'Datatrans transaction failed: merchant disabled by Datatrans.';
          break;

        case '1401':
          $message = 'Datatrans transaction failed: invalid expiration date.';
          break;

        case '1402':
        case '1404':
          $message = 'Datatrans transaction failed: card expired or blocked.';
          break;

        case '1403':
          $message = 'Datatrans transaction failed: transaction declined by card issuer.';
          break;

        case '1405':
          $message = 'Datatrans transaction failed: amount exceeded.';
          break;

        case '3000':
        case '3001':
        case '3002':
        case '3003':
        case '3004':
        case '3005':
        case '3006':
        case '3011':
        case '3012':
        case '3013':
        case '3014':
        case '3015':
        case '3016':
          $message = 'Datatrans transaction failed: denied by fraud management.';
          break;

        case '3031':
          $message = 'Datatrans transaction failed: declined due to response code 02.';
          break;

        case '3041':
          $message = 'Datatrans transaction failed: Declined due to post error/post URL check failed.';
          break;

        case '10412':
          $message = 'Datatrans transaction failed: PayPal duplicate error.';
          break;

        case '-885':
        case '-886':
          $message = 'Datatrans transaction failed: CC-alias update/insert error.';
          break;

        case '-887':
          $message = 'Datatrans transaction failed: CC-alias does not match with cardno.';
          break;

        case '-888':
          $message = 'Datatrans transaction failed: CC-alias not found.';

        case '-900':
          $message = 'Datatrans transaction failed: CC-alias service not enabled.';
          break;

        default:
          break;
          $message = 'Datatrans transaction failed: undefined error.';
        break;
      }
      return $message;
  }
}
