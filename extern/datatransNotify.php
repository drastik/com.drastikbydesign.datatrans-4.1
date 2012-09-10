<?php
/*
 * DataTrans IPN Notifier.
 * Joshua Walker (drastik) http://drastikbydesign.com
 */
session_start();
require_once '../civicrm.config.php';
require_once 'CRM/Core/Config.php';
$config = CRM_Core_Config::singleton();
/*
 *   @TODO Allow option of varied security settings?
 *   Use $method if so.  For now, always use highest security.
 *   Note: currently, changing method will not do anything.
 */
$method = "HMAC";
$post_data = file_get_contents('php://input');
require_once 'CRM/Core/Payment/DataTransIPN.php';
CRM_Core_Payment_DataTransIPN::main($method, $post_data);
