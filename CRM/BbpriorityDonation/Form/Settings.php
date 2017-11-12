<?php

require_once 'CRM/Core/Form.php';

class CRM_BbpriorityDonation_Form_Settings extends CRM_Core_Form {
  public function buildQuickForm() {
    $this->add('checkbox', 'ipn_http', 'Use http for IPN Callback');
    $this->add('text', 'merchant_terminal', 'Merchant Terminal', array('size' => 5));

    $paymentProcessors = $this->getPaymentProcessors();
    foreach( $paymentProcessors as $paymentProcessor ) {
      $settingCode = 'merchant_terminal_' . $paymentProcessor[ "id" ];
      $settingTitle = $paymentProcessor[ "name" ] . " (" .
        ( $paymentProcessor["is_test"] == 0 ? "Live" : "Test" ) . ")";
      $this->add('text', $settingCode, $settingTitle, array('size' => 5));
    }

    $this->addButtons(array(
      array(
        'type' => 'submit',
        'name' => ts('Submit'),
        'isDefault' => TRUE,
      ),
    ));
    parent::buildQuickForm();
  }

  function setDefaultValues() {
    $defaults = array();
    $bbpriorityDonation_settings = CRM_Core_BAO_Setting::getItem("BbpriorityDonation Settings", 'bbpriorityDonation_settings');
    if (!empty($bbpriorityDonation_settings)) {
      $defaults = $bbpriorityDonation_settings;
    }
    return $defaults;
  }

  public function postProcess() {
    $values = $this->exportValues();
    $bbpriorityDonation_settings['ipn_http'] = $values['ipn_http'];
    $bbpriorityDonation_settings['merchant_terminal'] = $values['merchant_terminal'];
    
    $paymentProcessors = $this->getPaymentProcessors();
    foreach( $paymentProcessors as $paymentProcessor ) {
      $settingId = 'merchant_terminal_' . $paymentProcessor[ "id" ];
      $bbpriorityDonation_settings[$settingId] = $values[$settingId];
    }
    
    CRM_Core_BAO_Setting::setItem($bbpriorityDonation_settings, "Bb Priority Donation Settings", 'bbpriorityDonation_settings');
    CRM_Core_Session::setStatus(
      ts('Bb Priority Donation Settings Saved', array( 'domain' => 'info.kabbalah.payment.bbpriorityDonation')),
      'Configuration Updated', 'success');

    parent::postProcess();
  }

  public function getPaymentProcessors() {
    // Get the BbpriorityDonation payment processor type
    $bbpriorityDonationName = array( 'name' => 'BbpriorityDonation' );
    $paymentProcessorType = civicrm_api3( 'PaymentProcessorType', 'getsingle', $bbpriorityDonationName );

    // Get the payment processors of bbpriorityDonation type
    $bbpriorityDonationType = array(
      'payment_processor_type_id' => $paymentProcessorType[ 'id' ],
      'is_active' => 1 );
    $paymentProcessors = civicrm_api3( 'PaymentProcessor', 'get', $bbpriorityDonationType );

    return $paymentProcessors["values"];
  }
}
