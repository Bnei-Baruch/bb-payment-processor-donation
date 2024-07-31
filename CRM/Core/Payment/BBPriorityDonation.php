<?php

/**
 *
 * @package BBPriorityDonation [after AuthorizeNet Payment Processor]
 * @author Gregory Shilin <gshilin@gmail.com>
 */

require_once 'CRM/Core/Payment.php';
require_once 'includes/PelecardDonationAPI.php';
require_once 'BBPriorityDonationIPN.php';

/**
 * BBPriorityDonation payment processor
 */
class CRM_Core_Payment_BBPriorityDonation extends CRM_Core_Payment {
    protected $_mode = NULL;

    protected $_params = array();

    /**
     * Constructor.
     *
     * @param string $mode
     *   The mode of operation: live or test.
     *
     * @param $paymentProcessor
     *
     */
    public function __construct(string $mode, &$paymentProcessor) {
        $this->_mode = $mode;
        $this->_paymentProcessor = $paymentProcessor;

        $this->_setParam('processorName', 'BB Payment Donation');
    }

    public function setTrxnId(string $mode): string {
        $query = "SELECT MAX(trxn_id) AS trxn_id FROM civicrm_contribution WHERE trxn_id LIKE '{$mode}_%' LIMIT 1";
        $tid = CRM_Core_Dao::executeQuery($query);
        if (!$tid->fetch()) {
            throw new CRM_Core_Exception('Could not find contribution max id');
        }
        $trxn_id = strval($tid->trxn_id);
        $trxn_id = str_replace("{$mode}_", '', $trxn_id);
        $trxn_id = intval($trxn_id) + 1;
        $uniqid = uniqid();
        return "{$mode}_{$trxn_id}_{$uniqid}";
    }

    /**
     * This function checks to see if we have the right config values.
     *
     * @return string
     *   the error message if any
     */
    public function checkConfig() {
        $error = array();

        if (empty($this->_paymentProcessor["user_name"])) {
            $error[] = ts("Merchant Name is not set in the BBPD Payment Processor settings.");
        }
        if (empty($this->_paymentProcessor["password"])) {
            $error[] = ts("Merchant Password is not set in the BBPD Payment Processor settings.");
        }

        if (!empty($error)) {
            return implode("<p>", $error);
        } else {
            return NULL;
        }
    }

    function doPayment(&$params, $component = 'contribute') {
        /* DEBUG
            echo "<pre>";
            var_dump($this->_paymentProcessor);
            var_dump($params);
            echo "</pre>";
            exit();
        */

        global $base_url;
        global $language;
        $lang = strtoupper($language->language);

        if ($component != 'contribute' && $component != 'event') {
            Civi::log()->error('bbprioritycc_payment_exception',
                ['context' => [
                    'message' => "Component '{$component}' is invalid."
                ]]);
            CRM_Utils_System::civiExit();
        }
        $this->_component = $component;

        $statuses = CRM_Contribute_BAO_Contribution::buildOptions('contribution_status_id', 'validate');

        $invoiceID = $this->_getParam('invoiceID');
        $contributionID = $params['contributionID'] ?? NULL;
        if ($this->checkDupe($invoiceID, $contributionID)) {
            throw new PaymentProcessorException('It appears that this transaction is a duplicate.  Have you already submitted the form once?  If so there may have been a connection problem.  Check your email for a receipt.  If you do not receive a receipt within 2 hours you can try your transaction again.  If you continue to have problems please contact the site administrator.', 9004);
        }

        // If we have a $0 amount, skip call to processor and set payment_status to Completed.
        // Conceivably a processor might override this - perhaps for setting up a token - but we don't
        // have an example of that at the moment.
        if ($params['amount'] == 0) {
            $result = array();
            $result['payment_status_id'] = array_search('Completed', $statuses);
            $result['payment_status'] = 'Completed';
            return $result;
        }

        $params['trxn_id'] = $this->setTrxnId($this->_mode);
        //Total amount is from the form contribution field
        $amount = $this->_getParam('total_amount');
        if (empty($amount)) {
            $amount = $this->_getParam('amount');
        }
        if ($params["amount"] < 0) {
            throw new PaymentProcessorException(ts('Amount must be positive!!!'), 9004);
        }
        $params['gross_amount'] = $amount;
        // Add a fee_amount so we can be sure fees are handled properly in underlying classes.
        $params['fee_amount'] = 1.50;
        $params['net_amount'] = $params['gross_amount'] - $params['fee_amount'];

        if (array_key_exists('webform_redirect_success', $params)) {
            $returnURL = $params['webform_redirect_success'];
            $cancelURL = $params['webform_redirect_cancel'];
        } else {
            $url = ($component == 'event') ? 'civicrm/event/register' : 'civicrm/contribute/transact';
            $cancel = ($component == 'event') ? '_qf_Register_display' : '_qf_Main_display';
            $returnURL = CRM_Utils_System::url($url,
                "_qf_ThankYou_display=1&qfKey={$params['qfKey']}",
                TRUE, NULL, FALSE
            );

            $cancelUrlString = "$cancel=1&cancel=1&qfKey={$params['qfKey']}";
            if ($params['is_recur'] ?? false) {
                $cancelUrlString .= "&isRecur=1&recurId={$params['contributionRecurID']}&contribId={$params['contributionID']}";
            }

            $cancelURL = CRM_Utils_System::url(
                $url,
                $cancelUrlString,
                TRUE, NULL, FALSE
            );
        }

        $merchantUrlParams = "contactID={$params['contactID']}&contributionID={$params['contributionID']}";
        if ($component == 'event') {
            $merchantUrlParams .= "&eventID={$params['eventID']}&participantID={$params['participantID']}";
        } else {
            $membershipID = CRM_Utils_Array::value('membershipID', $params);
            if ($membershipID) {
                $merchantUrlParams .= "&membershipID=$membershipID";
            }
            $contributionPageID = CRM_Utils_Array::value('contributionPageID', $params) ||
                CRM_Utils_Array::value('contribution_page_id', $params);
            if ($contributionPageID) {
                $merchantUrlParams .= "&contributionPageID=$contributionPageID";
            }
            $relatedContactID = CRM_Utils_Array::value('related_contact', $params);
            if ($relatedContactID) {
                $merchantUrlParams .= "&relatedContactID=$relatedContactID";

                $onBehalfDupeAlert = CRM_Utils_Array::value('onbehalf_dupe_alert', $params);
                if ($onBehalfDupeAlert) {
                    $merchantUrlParams .= "&onBehalfDupeAlert=$onBehalfDupeAlert";
                }
            }
        }

        $pelecard = new PelecardDonationAPI;
        $merchantUrl = $base_url . '/' . strtolower($lang) . '/civicrm/payment/ipn?processor_id=' . $this->_paymentProcessor["id"] . '&mode=' . $this->_mode
            . '&md=' . $component . '&qfKey=' . $params["qfKey"] . '&' . $merchantUrlParams
            . '&returnURL=' . $pelecard->base64_url_encode($returnURL);

        $pelecard->setParameter("user", $this->_paymentProcessor["user_name"]);
        $pelecard->setParameter("password", $this->_paymentProcessor["password"]);
        $pelecard->setParameter("terminal", $this->_paymentProcessor["signature"]);

        $pelecard->setParameter("UserKey", $params['qfKey']);
        $pelecard->setParameter("ParamX", 'civicrm-' . $params['contributionID']);

        //    $sandBoxUrl = 'https://gateway20.pelecard.biz/sandbox/landingpage?authnum=123';
        $pelecard->setParameter("GoodUrl", $merchantUrl); // ReturnUrl should be used _AFTER_ payment confirmation
        $pelecard->setParameter("ErrorUrl", $merchantUrl);
        $pelecard->setParameter("CancelUrl", $cancelURL);

        $pelecard->setParameter("Total", $amount * 100);
        if ($params["currencyID"] == "EUR") {
            $currency = 978;
        } elseif ($params["currencyID"] == "USD") {
            $currency = 2;
        } else { // ILS -- default
            $currency = 1;
        }
        $pelecard->setParameter("Currency", $currency);

        $financialTypeID = self::array_column_recursive_first($params, "financialTypeID");
        if (empty($financialTypeID)) {
            $financialTypeID = self::array_column_recursive_first($params, "financial_type_id");
        }
        $financial_account_id = civicrm_api3('EntityFinancialAccount', 'getvalue', array(
            'return' => "financial_account_id",
            'entity_id' => $financialTypeID,
            'account_relationship' => 1,
        ));

        $contact_id = civicrm_api3('FinancialAccount', 'getvalue', array(
            'return' => "contact_id",
            'id' => $financial_account_id,
            'account_relationship' => 1,
        ));
        $nick_name = civicrm_api3('Contact', 'getvalue', array(
            'return' => "nick_name",
            'id' => $contact_id,
            'account_relationship' => 1,
        ));

        global $language;
        $lang = strtoupper($language->language);
        $pelecard->setParameter("Language", $lang);
        if ($nick_name == 'ben2') {
            if ($lang == 'HE') {
                $pelecard->setParameter("TopText", 'סכום לתשלום בהוראת קבע: ' . $amount . $params["currencyID"]);
                $pelecard->setParameter("BottomText", '© בני ברוך קבלה לעם');
                $pelecard->setParameter('ShowConfirmationCheckbox', 'True');
                $pelecard->setParameter('TextOnConfirmationBox', 'אני מסכים עם תנאי השימוש');
                $pelecard->setParameter('ConfirmationLink', 'https://checkout.kabbalah.info/legacy-statement-crm-he.html');
            } elseif ($lang == 'RU') {
                $pelecard->setParameter("TopText", 'Сумма пожертвования: ' . $amount . $params["currencyID"]);
                $pelecard->setParameter("BottomText", '© Бней Барух Каббала лаАм');
                $pelecard->setParameter('ShowConfirmationCheckbox', 'True');
                $pelecard->setParameter('TextOnConfirmationBox', 'Я согласен с условиями обслуживания');
                $pelecard->setParameter('ConfirmationLink', 'https://checkout.kabbalah.info/legacy-statement-crm-ru.html');
            } else {
                $pelecard->setParameter("TopText", 'Donation Amount: ' . $amount . $params["currencyID"]);
                $pelecard->setParameter("BottomText", '© Bnei Baruch Kabbalah laAm');
                $pelecard->setParameter('ShowConfirmationCheckbox', 'True');
                $pelecard->setParameter('TextOnConfirmationBox', 'I agree with the terms of service');
                $pelecard->setParameter('ConfirmationLink', 'https://checkout.kabbalah.info/legacy-statement-crm-en.html');
            }
            $pelecard->setParameter("LogoUrl", "https://checkout.kabbalah.info/logo1.png");
        } elseif ($nick_name == 'arvut2') {
            if ($lang == 'HE') {
                $pelecard->setParameter("TopText", 'סכום התרומה: ' . $amount . $params["currencyID"]);
                $pelecard->setParameter("BottomText", '© תנועת הערבות לאיחוד העם');
            } elseif ($lang == 'RU') {
                $pelecard->setParameter("TopText", 'Сумма пожертвования: ' . $amount . $params["currencyID"]);
                $pelecard->setParameter("BottomText", '© Общественное движение «Арвут»');
            } else {
                $pelecard->setParameter("TopText", 'Donation Amount: ' . $amount . $params["currencyID"]);
                $pelecard->setParameter("BottomText", '© The Arvut Social Movement');
            }
            $pelecard->setParameter("LogoUrl", "https://checkout.arvut.org/arvut_logo.png");
        } elseif ($nick_name == 'meshp18') {
            $pelecard->setParameter("TopText", 'משפחה בחיבור');
            $pelecard->setParameter("BottomText", '© משפחה בחיבור');
            $pelecard->setParameter('ShowConfirmationCheckbox', 'True');
            $pelecard->setParameter('TextOnConfirmationBox', 'אני מסכים עם תנאי השימוש');
            $pelecard->setParameter("Language", 'HE');
            $pelecard->setParameter('ConfirmationLink', 'https://www.1family.co.il/privacy-policy/');
            $pelecard->setParameter("LogoUrl", "https://www.1family.co.il/wp-content/uploads/2019/06/cropped-Screen-Shot-2019-06-16-at-00.12.07-140x82.png");
        }

        $pelecard->setParameter("MinPayments", 1);
        $installments = civicrm_api3('FinancialAccount', 'getvalue', array('return' => "account_type_code", 'id' => $financial_account_id,));
        try {
            $min_amount = civicrm_api3('FinancialAccount', 'getvalue', array('return' => "description", 'id' => $financial_account_id,));
        } catch (Exception $e) {
            $min_amount = 0;
        }
        if ((int)$installments == 0) {
            $pelecard->setParameter("MaxPayments", 1);
        } else if ((int)$installments > 0 && $params["amount"] >= (int)$min_amount) {
            $pelecard->setParameter("MaxPayments", $installments);
        }

        $result = $pelecard->getRedirectUrl();
        $error = $result[0];
        if ($error > 0) {
            return FALSE;
        }
        $url = $result[1];

        // Print the tpl to redirect to Pelecard
        $template = CRM_Core_Smarty::singleton();
        $template->assign('url', $url);
        print $template->fetch('CRM/Core/Payment/BbpriorityDonation.tpl');

        CRM_Utils_System::civiExit();
    }

    public function handlePaymentNotification() {
        $ipnClass = new CRM_Core_Payment_BBPriorityDonationIPN();

        $input = $ids = array();
        $ipnClass->getInput($input, $ids);
        $ipnClass->main($this->_paymentProcessor, $input, $ids);
    }

    /* Return dashed field (like email-4) from array */
    function getField($array, $field) {
        if (array_key_exists($field, $array)) {
            return $array[$field];
        }

        $keys = array_keys($array);
        $pattern = "/^" . $field . "/";
        $result = preg_grep($pattern, $keys);
        if (empty($result)) {
            return '';
        } else {
            return $array[array_values($result)[0]];
        }
    }

    /* Find first occurrence of needle somewhere in haystack (on all levels) */
    static function array_column_recursive_first(array $haystack, $needle) {
        $found = [];
        array_walk_recursive($haystack, function ($value, $key) use (&$found, $needle) {
            if (gettype($key) == 'string' && $key == $needle) {
                $found[] = $value;
            }
        });
        return $found[0];
    }

    /**
     * Get the value of a field if set.
     *
     * @param string $field
     *   The field.
     *
     * @param bool $xmlSafe
     * @return mixed
     *   value of the field, or empty string if the field is not set
     */
    public function _getParam(string $field, bool $xmlSafe = FALSE): string {
        $value = $this->_params[$field] ?? '';
        if ($xmlSafe) {
            $value = str_replace(['&', '"', "'", '<', '>'], '', $value);
        }
        return $value;
    }

    /**
     * Set a field to the specified value.  Value must be a scalar (int,
     * float, string, or boolean)
     *
     * @param string $field
     * @param string $value
     *
     */
    public function _setParam(string $field, string $value) {
        $this->_params[$field] = $value;
    }
}
