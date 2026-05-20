<?php

use Civi\Api4\Contribution;
use CRM\BBPelecard\API\Pelecard;
use CRM\BBPelecard\Payment\BBPriorityBaseIPN;

class CRM_Core_Payment_BBPriorityDonationIPN extends BBPriorityBaseIPN {

  function __construct($inputData) {
    parent::__construct(Pelecard::TYPE_DONATION, $inputData);
  }

  protected function getTemplateName(): string {
    return 'CRM/Core/Payment/BbpriorityDonation.tpl';
  }

  protected function getLogChannel(): string {
    return 'BBPDonation IPN';
  }

  function validateResult(&$paymentProcessor, &$input, &$contribution) {
    if ($input['UserKey'] != $input['qfKey']) {
      Civi::log('BBPDonation IPN')->debug("Pelecard Response param UserKey is invalid");
      return [false, null];
    }

    $approval = 0;
    $input['amount'] = $contribution['total_amount'];
    list($valid, $data, $errorCode) = $this->_bbpAPI->validateResponse($paymentProcessor, $input, $contribution, false, $approval);
    if (!$valid) {
      $query_params = array(
        1 => array($errorCode > 0 ? $errorCode : -1, 'String'),
        2 => array($contribution->id, 'String')
      );
      CRM_Core_DAO::executeQuery(
        'UPDATE civicrm_contribution SET invoice_number = %1, contribution_status_id = 4 WHERE id = %2', $query_params);

      Civi::log('BBPDonation IPN')->debug("Pelecard Response is invalid");
      return [false, null];
    }

    $input['Token'] = $data['Token'] ?? '';

    if (!$this->_bbpAPI->firstCharge($paymentProcessor, $input, $contribution, $approval)) {
      Civi::log('BBPDonation IPN')->debug("Unable to Charge the First Payment");
      echo("<p>Unable to Charge the First Payment</p>");
      return [false, null];
    }

    list($valid, $data, $errorCode) = $this->_bbpAPI->validateResponse($paymentProcessor, $input, $contribution, true, $approval);
    if (!$valid) {
      $query_params = array(
        1 => array($errorCode > 0 ? $errorCode : -1, 'String'),
        2 => array($contribution['id'], 'String')
      );
      CRM_Core_DAO::executeQuery(
        'UPDATE civicrm_contribution SET invoice_number = %1, contribution_status_id = 4 WHERE id = %2', $query_params);

      Civi::log('BBPDonation IPN')->debug("Pelecard Response is invalid");
      return [false, $data];
    }

    $this->storePaymentResponse($contribution['id'], $data, false, $data['Token'] ?? '');

    $contribution['trxn_id'] = $data['PelecardTransactionId'];
    return [true, $data];
  }
}
