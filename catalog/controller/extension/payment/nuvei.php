<?php

require_once DIR_SYSTEM . 'library' . DIRECTORY_SEPARATOR . 'nuvei' 
    . DIRECTORY_SEPARATOR . 'NUVEI_CLASS.php';

/**
 * Recurring Order statuses:
 * 1 - Active,
 * 2 - Inactive,
 * 3 - Cancelled,
 * 4 - Suspended,
 * 5 - Expired,
 * 6 - Pending
 */
class ControllerExtensionPaymentNuvei extends Controller
{
    private $plugin_settings    = [];
    private $order_addresses    = [];
    private $new_order_status   = 0;
    private $total_curr_alert   = false;
    private $is_user_logged;
	private $order_info;
    
	public function index()
    {
        $this->load->model('checkout/order');
		$this->load->model('account/reward');
        $this->load_settings();
        $this->language->load(NUVEI_CONTROLLER_PATH);
        
        $this->order_info       = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        $this->order_addresses  = $this->get_order_addresses();
        $this->is_user_logged   = !empty($this->session->data['customer_id']) ? 1 : 0;
        $products               = $this->cart->getProducts();
		
        // the pre-payment Ajax call
        if(isset($this->request->server['HTTP_X_REQUESTED_WITH'])
            && 'XMLHttpRequest' == $this->request->server['HTTP_X_REQUESTED_WITH']
            && NUVEI_CONTROLLER_PATH == $this->request->get['route']
        ) {
            $this->ajax_call($products);
            exit;
        }
        
        // before call Open Order check for not allowed combination of prdocusts
        $rebilling_data = $this->cart->getRecurringProducts();
        
        if (count($rebilling_data) > 0 && count($products) > 1) {
            foreach($rebilling_data as $reb_data) {
                // check for nuvei into recurring name
                if (isset($reb_data['recurring']['name'])
                    && strpos(strtolower($reb_data['recurring']['name']), NUVEI_PLUGIN_CODE) !== false
                ) {
                    exit('<div class="alert alert-danger">'. $this->language->get('error_nuvei_products') .'</div>');
                }
            }
        }
        
        // Open Order
        $order_data = $this->open_order($products);
		
		if(empty($order_data) || empty($order_data['sessionToken'])) {
			NUVEI_CLASS::create_log($this->plugin_settings, $order_data, 'Open Order problem with the response', 'CRITICAL');
			
			exit('<div class="alert alert-danger">'. $this->language->get('error_nuvei_gateway') .'</div>');
		}
        
        $pm_black_list = [];
        
        if(!empty($this->plugin_settings[NUVEI_SETTINGS_PREFIX . 'block_pms'])
            && is_array($this->plugin_settings[NUVEI_SETTINGS_PREFIX . 'block_pms'])
        ) {
            $pm_black_list = $this->plugin_settings[NUVEI_SETTINGS_PREFIX . 'block_pms'];
        }
        
        $use_upos = $save_pm = (bool) $this->plugin_settings[NUVEI_SETTINGS_PREFIX . 'use_upos'];
        
        if(0 == $this->is_user_logged) {
            $use_upos = $save_pm = false;
        }
        elseif($this->cart->hasRecurringProducts()) {
            $save_pm = 'always';
        }
        
        $useDCC = $this->plugin_settings[NUVEI_SETTINGS_PREFIX . 'use_dcc'];
        
        if (0 == $order_data['amount']) {
            $useDCC = 'false';
        }
        
        $locale     = substr($this->get_locale(), 0, 2);
        $sdk_transl	= html_entity_decode($this->plugin_settings[NUVEI_SETTINGS_PREFIX . 'sdk_transl']);
        $sdk_style	= html_entity_decode($this->plugin_settings[NUVEI_SETTINGS_PREFIX . 'sdk_style']);
        
        // set the template data
        $data = $this->load->language(NUVEI_CONTROLLER_PATH);
        
        $data['nuvei_sdk_params'] = [
            'renderTo'               => '#nuvei_checkout',
            'strict'                 => false,
            'alwaysCollectCvv'       => true,
            'maskCvv'                => true,
            'showResponseMessage'    => false,
            'sessionToken'           => $order_data['sessionToken'],
            'env'                    => 1 == $this->plugin_settings[NUVEI_SETTINGS_PREFIX . 'test_mode'] ? 'test' : 'prod',
            'merchantId'             => trim($this->plugin_settings[NUVEI_SETTINGS_PREFIX . 'merchantId']),
            'merchantSiteId'         => trim($this->plugin_settings[NUVEI_SETTINGS_PREFIX . 'merchantSiteId']),
            'country'                => $order_data['billingAddress']['country'],
            'currency'               => $order_data['currency'],
            'amount'                 => $order_data['amount'],
            'useDCC'                 => $useDCC,
            'savePM'                 => $save_pm,
            'showUserPaymentOptions' => $use_upos,
            'pmWhitelist'            => null,
            'pmBlacklist'            => $pm_black_list,
            'email'                  => $order_data['billingAddress']['email'],
            'fullName'               => trim($order_data['billingAddress']['firstName'] 
                . ' ' . $order_data['billingAddress']['lastName']),
            'payButton'              => $this->plugin_settings[NUVEI_SETTINGS_PREFIX . 'pay_btn_text'],
            'locale'                 => $locale,
            'autoOpenPM'             => (bool) $this->plugin_settings[NUVEI_SETTINGS_PREFIX . 'auto_expand_pms'],
            'logLevel'               => $this->plugin_settings[NUVEI_SETTINGS_PREFIX . 'sdk_log_level'],
            'i18n'                   => json_decode($sdk_transl, true),
            'theme'                  => $this->plugin_settings[NUVEI_SETTINGS_PREFIX . 'sdk_theme'],
            'apmWindowType'          => $this->plugin_settings[NUVEI_SETTINGS_PREFIX . 'apm_window_type'],
            'apmConfig'              => [
                'googlePay' => [
                    'locale' => $locale
                ]
            ],
            'sourceApplication'     => NUVEI_SOURCE_APP,
			'fieldStyle'			=> json_decode($sdk_style, true),
        ];
        
        // add specific parameter for QA site
        if ($this->isQaSite()) {
            $data['nuvei_sdk_params']['webSdkEnv'] = 'devmobile';
        }
        
        $data['action'] = $this->url->link(NUVEI_CONTROLLER_PATH . '/process_payment')
			. '&order_id=' . $this->session->data['order_id'];
        
        // check for product with a plan or Zero-Total Order
        if($this->cart->hasRecurringProducts() || 0 == $order_data['amount']) {
            $data['nuvei_sdk_params']['pmWhitelist'][] = 'cc_card';
            unset($data['nuvei_sdk_params']['pmBlacklist']);
        }
        
        // load common php template and then pass it to the real template
        // as single variable. The form is same for both versions
        $tpl_path = 'default/template/'  . NUVEI_CONTROLLER_PATH;
        
        NUVEI_CLASS::create_log($this->plugin_settings, $data['nuvei_sdk_params'], 'SDK params');
        
        ob_start();
        require DIR_TEMPLATE . $tpl_path . '.php';
        return ob_get_clean(); // the template of OC wants array
	}
    
    // on success add history note for the order
    public function success()
    {
        $this->load_settings();
        $this->load->model('checkout/order');
        $this->language->load(NUVEI_CONTROLLER_PATH);
        
        NUVEI_CLASS::create_log($this->plugin_settings, 'Success page');
        
        $this->session->data['nuvei_last_oo_details'] = [];
        
		if(!empty($this->request->get['order_id'])) {
			$order_id = (int) $this->request->get['order_id'];
		}
		elseif(NUVEI_CLASS::get_param('invoice_id')) {
			$arr		= explode("_", NUVEI_CLASS::get_param('invoice_id'));
			$order_id	= (int) $arr[0];
		}
		else {
			NUVEI_CLASS::create_log(
                $this->plugin_settings,
                @$_REQUEST,
                'Success Error - can not recognize order ID.'
            );
			
            $this->response->redirect($this->url->link(NUVEI_CONTROLLER_PATH . '/fail'));
		}
		
		$this->order_info = $this->model_checkout_order->getOrder($order_id);
        
        if(isset($this->order_info['order_status_id'])
            && (int) $this->order_info['order_status_id'] == 0
        ) {
			$this->model_checkout_order->addOrderHistory(
				$order_id,
                $this->order_info['order_status_id'],
				$this->language->get('nuvei_payment_complete'),
				true
			);
        }
        
        $this->response->redirect($this->url->link('checkout/success') . '&order_id=' . $order_id);
    }
    
    /*
     * Function fail()
     * When order fail came here.
     */
    public function fail()
	{
        $this->load_settings();
        $this->language->load(NUVEI_CONTROLLER_PATH);
        
        NUVEI_CLASS::create_log($this->plugin_settings, @$_REQUEST, 'Order FAIL');
        
		if(!empty($this->request->get['order_id'])) {
			$order_id = intval($this->request->get['order_id']);
		}
		elseif(NUVEI_CLASS::get_param('invoice_id')) {
			$arr		= explode("_", NUVEI_CLASS::get_param('invoice_id'));
			$order_id	= (int) $arr[0];
		}
		else {
			$this->session->data['error']= $this->language->get('nuvei_payment_faild');
			$this->response->redirect($this->url->link('checkout/cart'));
		}
		
		$this->load->model('checkout/order');
		$this->order_info = $this->model_checkout_order->getOrder($order_id);

		if ($this->order_info) {
            $this->change_order_status($order_id, 'FAIL');
		}

		$this->session->data['error']= $this->language->get('nuvei_payment_faild');
        
        $this->response->redirect($this->url->link('checkout/cart'));
	}
    
    /**
     * Receive DMNs here
     */
	public function callback()
    {
        $this->load_settings();
        $this->load->model('checkout/order');
        
        NUVEI_CLASS::create_log($this->plugin_settings, @$_REQUEST, 'DMN request');
        
        ### Manual stop DMN for tests
//        die('manually stoped');
        
        // exit
        if ('CARD_TOKENIZATION' == NUVEI_CLASS::get_param('type')) {
            $this->return_message('CARD_TOKENIZATION DMN, wait for the next one.');
        }
        
        $req_status = $this->get_request_status();
        
        // exit
//        if ('pending' == strtolower($req_status)) {
//            $this->return_message('Pending DMN, wait for the next one.');
//        }
        
        if(!$this->validate_dmn()) {
            $this->return_message('DMN report: You receive DMN from not trusted source. The process ends here.');
        }
        
        // check for Subscription State DMN
        $this->process_subs_state();
        
        // check for Subscription Payment DMN
        $this->process_subs_payment();
        
        $this->get_order_info_by_dmn();
        
        // exit
        if (!empty($this->order_info['payment_custom_field'])
            && 'pending' == strtolower($req_status)
        ) {
            $this->return_message('This is Pending DMN, but Order is already processed.');
        }
        
        $order_id = $this->order_info['order_id'];
        
        # in Case of CPanel Refund DMN
//        if(in_array($trans_type, array('Credit', 'Refund'))
//            && strpos(NUVEI_CLASS::get_param('clientUniqueId'), 'gwp_') !== false
//        ) {
//            $this->model_checkout_order->addOrderHistory(
//                $order_id,
//                $this->order_info['order_status_id'],
//                $this->language->get('CPanel Refund detected. Please, create a manual refund!'),
//                false
//            );
//
//            $this->return_message('DMN received.');
//        }
        # in Case of CPanel Refund DMN END
        
        $this->new_order_status = $this->order_info['order_status_id'];
        
        $trans_type = NUVEI_CLASS::get_param('transactionType', FILTER_SANITIZE_STRING);
        
        # Sale and Auth
        if(in_array($trans_type, array('Sale', 'Auth'))) {
            NUVEI_CLASS::create_log(
                $this->plugin_settings,
                array(
                    'order_status_id' => $this->order_info['order_status_id'],
                    'default complete status' => $this->config->get(NUVEI_SETTINGS_PREFIX . 'order_status_id'),
                ),
                'DMN Sale/Auth compare order status and default complete status:'
            );
            
            if ('declined' == strtolower($req_status)) {
                $this->return_message('Declined DMN received. Wait for new payment try.');
            }
            
			// if is different than the default Complete status
			if($this->order_info['order_status_id'] 
                != $this->config->get(NUVEI_SETTINGS_PREFIX . 'order_status_id')
            ) {
				$this->change_order_status($order_id, $req_status, $trans_type);
			}
            
            $this->update_custom_fields($order_id);
            $this->subscription_start($trans_type, $order_id);
            $this->return_message('DMN received.');
        }
        
        # Refund
        if(in_array($trans_type, array('Credit', 'Refund'))) {
            $this->update_custom_fields($order_id);
            $this->change_order_status($order_id, $req_status, 'Credit');
            $this->return_message('DMN received.');
        }
        
        # Void, Settle
        if(in_array($trans_type, array('Void', 'Settle'))) {
            $this->update_custom_fields($order_id);
            $this->change_order_status($order_id, $req_status, $trans_type);
            
            if ('Settle' == $trans_type) {
                NUVEI_CLASS::create_log($this->plugin_settings, 'DMN Settle');
                $this->subscription_start($trans_type, $order_id);
            }
            else {
                NUVEI_CLASS::create_log($this->plugin_settings, 'DMN Void');
                $this->subscription_cancel($trans_type, $order_id);
            }
            
			$this->return_message('DMN received.');
        }
        
        $this->return_message('DMN was not recognized!');
	}
    
    /**
     * We use this method with REST API.
     * Here we send the data from the form and prepare it before send it to the API.
     */
    public function process_payment()
    {
        $this->load_settings();
        $this->load->model('checkout/order');
        
        NUVEI_CLASS::create_log($this->plugin_settings, @$_POST, 'process_payment()');
        
        $this->session->data['nuvei_last_oo_details'] = [];
		
        $this->order_info   = $this->model_checkout_order->getOrder($this->request->get['order_id']);
		
		$success_url        = $this->url->link(NUVEI_CONTROLLER_PATH . '/success') 
            . '&order_id=' . $this->request->get['order_id'];
		
        $error_url          = $this->url->link(NUVEI_CONTROLLER_PATH . '/fail') 
            . '&order_id=' . $this->request->get['order_id'];
		
		if(!empty($this->request->post['sc_transaction_id'])
            && is_numeric($this->request->post['sc_transaction_id'])
		) {
			$this->response->redirect($success_url);
		}
        
        $this->response->redirect($error_url);
    }
    
    /**
     * Event callback method.
     * 
     * @param string $route
     * @param array $data
     */
    public function addJsScriptsToCatalog(&$route, &$data)
    {
        $this->document->addScript(
            ($this->isQaSite() ? NUVEI_SDK_URL_TAG : NUVEI_SDK_URL_PROD), 
            'footer'
        );

        // add Nuvei common modify script
        $this->document->addScript('catalog/view/javascript/nuvei_common_js_mod.js', 'footer');
    }
    
    /**
     * Event callback method.
     * 
     * @param string $route
     * @param array $data
     */
    public function addProductToCart(&$route, &$args)
    {
        $product_id = 0;
        
        if (isset($this->request->post['product_id'])) {
			$product_id = (int) $this->request->post['product_id'];
		}
		
        $this->load->model('catalog/product');

		$product_info = $this->model_catalog_product->getProduct($product_id);
        
        if ($product_info) {
            $recurrings		= $this->model_catalog_product->getProfiles($product_info['product_id']);
			$currRecurr		= array();
            $json			= array();
			$recurring_id	= 0;
			
			if (isset($this->request->post['recurring_id'])) {
				$recurring_id = (int) $this->request->post['recurring_id'];
			}
			
			foreach ($recurrings as $data) {
				if ($data['recurring_id'] == $recurring_id) {
					$currRecurr = $data;
					break;
				}
			}
            
            $this->language->load(NUVEI_CONTROLLER_PATH);
            
			// for the incoming product
            if (!empty($currRecurr) 
                && isset($currRecurr['name']) 
                && strpos(strtolower($currRecurr['name']), NUVEI_PLUGIN_CODE) !== false
            ) {
                if(count($this->cart->getProducts())) {
                    $json['error_nuvei'] = $this->language->get('nuvei_rec_error');
                }
                if(empty($this->session->data['customer_id'])) {
                    $json['error_nuvei'] = $this->language->get('nuvei_rec_user_error');
                }
            }
            // check for rebilling products into the Cart
            else {
                $rebilling_data = $this->cart->getRecurringProducts();

                if(count($rebilling_data) > 0) {
                    foreach($rebilling_data as $reb_data) {
                        // check for nuvei into recurring name
                        if (isset($reb_data['recurring']['name'])
                            && strpos(strtolower($reb_data['recurring']['name']), NUVEI_PLUGIN_CODE) !== false
                        ) {
                            $json['error_nuvei'] = $this->language->get('nuvei_rec_error');
                            break;
                        }
                    }
                }
            }
            
			// On error, end the JSON response and stop further execution
            if (isset($json['error_nuvei']) || isset($json['error'])) {
                $this->response->addHeader('Content-Type: application/json');
                $this->response->setOutput(json_encode($json));

                return false;
            }
        }
    }
    
    /**
     * Event callback method.
     * 
     * @param string $route
     * @param array $args
     * @param array $output
     */
    public function filterPaymentProviders(&$route, &$args, &$output)
    {
        if (!empty($args) && current($args) == 'payment') {
            $rebilling_data = $this->cart->getRecurringProducts();

            if(count($rebilling_data) > 0) {
                foreach($rebilling_data as $reb_data) {
                    // check for nuvei into recurring name
                    if (isset($reb_data['recurring']['name'])
                        && strpos(strtolower($reb_data['recurring']['name']), NUVEI_PLUGIN_CODE) !== false
                    ) {
                        foreach($output as $key => $payment) {
                            if ($payment['code'] != NUVEI_PLUGIN_CODE) {
                                unset($output[$key]);
                            }
                        }
                    }
                }
            }
        }
    }
	
    /**
     * Function validate_dmn
     * Check if the DMN is not fake.
     * 
     * @return boolean
     */
    private function validate_dmn()
    {
        $advanceResponseChecksum = NUVEI_CLASS::get_param('advanceResponseChecksum');
		$responsechecksum        = NUVEI_CLASS::get_param('responsechecksum');
		
		if (empty($advanceResponseChecksum) && empty($responsechecksum)) {
            NUVEI_CLASS::create_log(
                $this->plugin_settings,
                'advanceResponseChecksum and responsechecksum parameters are empty.',
                '',
                'CRITICAL'
            );
			return false;
		}
		
		// advanceResponseChecksum case
		if (!empty($advanceResponseChecksum)) {
            $str = hash(
                $this->config->get(NUVEI_SETTINGS_PREFIX . 'hash'),
                trim($this->config->get(NUVEI_SETTINGS_PREFIX . 'secret'))
                    . NUVEI_CLASS::get_param('totalAmount')
                    . NUVEI_CLASS::get_param('currency')
                    . NUVEI_CLASS::get_param('responseTimeStamp')
                    . NUVEI_CLASS::get_param('PPP_TransactionID')
                    . $this->get_request_status()
                    . NUVEI_CLASS::get_param('productId')
            );

            if (NUVEI_CLASS::get_param('advanceResponseChecksum') == $str) {
                return true;
            }

            NUVEI_CLASS::create_log(
                $this->plugin_settings,
                'advanceResponseChecksum validation fail.',
                '',
                'WARN'
            );
            return false;
		}
		
		# subscription DMN with responsechecksum case
		$concat        = '';
		$request_arr   = $_REQUEST;
		$custom_params = array(
			'route'             => '',
			'responsechecksum'  => '',
		);
		
		// remove parameters not part of the checksum
		$dmn_params = array_diff_key($request_arr, $custom_params);
		$concat     = implode('', $dmn_params);
		
		$concat_final = $concat . trim($this->config->get(NUVEI_SETTINGS_PREFIX . 'secret'));
		$checksum     = hash($this->config->get(NUVEI_SETTINGS_PREFIX . 'hash'), $concat_final);
		
		if ($responsechecksum !== $checksum) {
            NUVEI_CLASS::create_log(
                $this->plugin_settings,
                'responsechecksum validation fail.',
                '',
                'WARN'
            );
			return false;
		}
		
		return true;
	}
    
    /**
     * Function get_request_status
     * 
     * We need this stupid function because as response request variable
     * we get 'Status' or 'status'...
     * 
     * @return string
     */
    private function get_request_status($params = array())
    {
        if(empty($params)) {
            if(isset($_REQUEST['Status'])) {
                return filter_var($_REQUEST['Status'], FILTER_SANITIZE_STRING);
            }

            if(isset($_REQUEST['status'])) {
                return filter_var($_REQUEST['status'], FILTER_SANITIZE_STRING);
            }
        }
        else {
            if(isset($params['Status'])) {
                return filter_var($params['Status'], FILTER_SANITIZE_STRING);
            }

            if(isset($params['status'])) {
                return filter_var($params['status'], FILTER_SANITIZE_STRING);
            }
        }
        
        return '';
    }
    
    /**
     * Function get_locale
     * Extract locale code in format "en_GB"
     * 
     * @return string
     */
    private function get_locale()
    {
		$langs = $this->model_localisation_language->getLanguages();
        $langs = current($langs);
        
        if(isset($langs['locale']) && $langs['locale'] != '') {
            $locale_parts = explode(',', $langs['locale']);
            
            foreach($locale_parts as $part) {
                if(strlen($part) == 5 && strpos($part, '_') != false) {
                    return $part;
                }
            }
        }
        
        return '';
	}
    
    /**
     * Function change_order_status
     * Change the status of the order.
     * 
     * @param int $order_id - escaped
     * @param string $status
     * @param string $transactionType - not mandatory for the DMN
     */
    private function change_order_status($order_id, $status, $transactionType = '')
    {
        NUVEI_CLASS::create_log($this->plugin_settings, 'change_order_status()');
        
        $message		= '';
        $send_message	= true;
        $trans_id       = NUVEI_CLASS::get_param('TransactionID');
        $rel_tr_id      = NUVEI_CLASS::get_param('relatedTransactionId');
        $payment_method = NUVEI_CLASS::get_param('payment_method', FILTER_SANITIZE_STRING);
        $total_amount   = (float) NUVEI_CLASS::get_param('totalAmount');
        $status_id      = $this->order_info['order_status_id'];
        $order_total    = $this->get_price($this->order_info['total']);
        
        $comment_details = '<br/>' 
            . $this->language->get('Status: ') . $status . '<br/>'
            . $this->language->get('Transaction Type: ') . $transactionType . '<br/>'
            . $this->language->get('Transaction ID: ') . $trans_id . '<br/>'
            . $this->language->get('Related Transaction ID: ') . $rel_tr_id . '<br/>'
            . $this->language->get('Payment Method: ') . $payment_method . '<br/>'
            . $this->language->get('Total Amount: ') . $total_amount . '<br/>'
            . $this->language->get('Currency: ') . NUVEI_CLASS::get_param('currency') . '<br/>';
        
        switch($status) {
            case 'CANCELED':
                $message = $this->language->get('Your request was Canceled.') . $comment_details;
                break;

            case 'APPROVED':
                if($transactionType == 'Void') {
                    $message    = $this->language->get('Your Order was Voided.') . $comment_details;
                    $status_id  = $this->config->get(NUVEI_SETTINGS_PREFIX . 'canceled_status_id');
                    break;
                }
                
                // Refund
                if($transactionType == 'Credit') {
                    $send_message   = false;
                    $status_id      = $this->config->get(NUVEI_SETTINGS_PREFIX . 'refunded_status_id');

                    $message = $this->language->get('Your Order was Refunded.') . $comment_details;

                    $formated_refund = $this->currency->format(
                        $total_amount,
                        $this->order_info['currency_code'],
                        1 // because we pass converted amount, else - $this->order_info['currency_value']
                    );

                    $message .= $this->language->get('Refund Amount: ') . $formated_refund;
                    
                    break;
                }
                
                $status_id = $this->config->get(NUVEI_SETTINGS_PREFIX . 'order_status_id'); // "completed"
                
                if($transactionType == 'Auth') {
                    $message    = $this->language->get('The amount has been authorized and wait for Settle.');
                    $status_id  = $this->config->get(NUVEI_SETTINGS_PREFIX . 'pending_status_id');
                    
                    if(0 == $total_amount) {
                        $status_id  = $this->config->get(NUVEI_SETTINGS_PREFIX . 'order_status_id');
                        $message    = $this->language->get('The amount has been authorized.');
                    }
                }
                elseif($transactionType == 'Settle') {
                    $message = $this->language->get('The amount has been captured by Nuvei.');
                }
                // set the Order status to Complete
                elseif($transactionType == 'Sale') {
                    $message = $this->language->get('The amount has been authorized and captured by Nuvei.');
                }
                
                // check for different Order Amount
                if(in_array($transactionType, array('Sale', 'Auth'))) {
                    $original_amount        = (float) NUVEI_CLASS::get_param('customField1');
                    $original_curr          = NUVEI_CLASS::get_param('customField4');
                    
                    if ($order_total != $total_amount && $order_total != $original_amount) {
                        $this->total_curr_alert = true;
                    }
                    
                    if ($this->order_info['currency_code'] != NUVEI_CLASS::get_param('currency') 
                        && $this->order_info['currency_code'] != $original_curr
                    ) {
                        $this->total_curr_alert = true;
                    }
                    
                    if ($this->total_curr_alert) {
                        $msg = $this->language->get('Attention - the Order total is ') 
                            . $this->order_info['currency_code'] . ' ' . $order_total
                            . $this->language->get(', but the Captured amount is ')
                            . NUVEI_CLASS::get_param('currency', FILTER_SANITIZE_STRING)
                            . ' ' . $total_amount . '!';

                        $this->model_checkout_order->addOrderHistory($order_id, $status_id, $msg, false);
                    }
                }
                
				$message .= $comment_details;
                break;

            case 'ERROR':
            case 'DECLINED':
            case 'FAIL':
                $message = $this->language->get('Your request faild.') . $comment_details
                    . $this->language->get('Reason: ');
                
                if( ($reason = NUVEI_CLASS::get_param('reason', FILTER_SANITIZE_STRING)) ) {
                    $message .= $reason;
                }
                elseif( ($reason = NUVEI_CLASS::get_param('Reason', FILTER_SANITIZE_STRING)) ) {
                    $message .= $reason;
                }
                elseif( ($reason = NUVEI_CLASS::get_param('paymentMethodErrorReason', FILTER_SANITIZE_STRING)) ) {
                    $message .= $reason;
                }
                elseif( ($reason = NUVEI_CLASS::get_param('gwErrorReason', FILTER_SANITIZE_STRING)) ) {
                    $message .= $reason;
                }
                
                $message .= '<br/>';
                
                $message .= 
                    $this->language->get("Error code: ") 
                    . (int) NUVEI_CLASS::get_param('ErrCode') . '<br/>'
                    . $this->language->get("Message: ") 
                    . NUVEI_CLASS::get_param('message', FILTER_SANITIZE_STRING) . '<br/>';
                
                if(in_array($transactionType, array('Sale', 'Auth'))) {
                    $status_id = $this->config->get(NUVEI_SETTINGS_PREFIX . 'failed_status_id');
                    break;
                }

                // Void, do not change status
                if($transactionType == 'Void') {
                    $status_id = $this->order_info['order_status_id'];
                    break;
                }
                
                // Refund
                if($transactionType == 'Credit') {
					//if($cl_unique_id) {
						$formated_refund = $this->currency->format(
                            $total_amount,
							$this->order_info['currency_code'],
							$this->order_info['currency_value']
						);
						
						$message .= $this->language->get('Refund Amount: ') . $formated_refund;
					//}
                    
                    $status_id = $this->order_info['order_status_id'];
                    $send_message = false;
                    break;
                }
                
                $status_id = $this->config->get(NUVEI_SETTINGS_PREFIX . 'failed_status_id');
                break;

            case 'PENDING':
                $message    = $this->language->get('The Transaction is Pending.') . $comment_details;
                $status_id  = $this->config->get(NUVEI_SETTINGS_PREFIX . 'pending_status_id');
                break;
                
            default:
                NUVEI_CLASS::create_log($this->plugin_settings, $status, 'Unexisting status:');
        }
        
        NUVEI_CLASS::create_log(
            $this->plugin_settings,
            array(
                'order_id'  => $order_id,
                'status_id' => $status_id,
            ),
            'addOrderHistory()'
        );
        
        $this->model_checkout_order->addOrderHistory($order_id, $status_id, $message, $send_message);
        
        $this->new_order_status = $status_id;
    }
    
	private function open_order($products)
    {
        NUVEI_CLASS::create_log($this->plugin_settings, 'open_order()');
        
        $resp                   = [];
        $nuvei_last_oo_details  = isset($this->session->data['nuvei_last_oo_details'])
            ? $this->session->data['nuvei_last_oo_details'] : [];
        // rebiling parameters
        $rebilling_params       = $this->preprare_rebilling_params();
        $amount                 = $this->get_price($this->order_info['total']);
        $try_update_order       = false;
        $session_tr_type        = '';
        
        NUVEI_CLASS::create_log($this->plugin_settings, $nuvei_last_oo_details);
        
        # try to update Order
        if (! (empty($this->session->data['nuvei_last_oo_details']['userTokenId'])
            && !empty($rebilling_params['merchantDetails']['customField3'])
        ) ) {
            $try_update_order = true;
        }
        
        if (empty($this->session->data['nuvei_last_oo_details']['transactionType'])) {
            $try_update_order = false;
        }
        else {
            $session_tr_type = $this->session->data['nuvei_last_oo_details']['transactionType'];
        }
        
        if ($amount == 0
            && (empty($session_tr_type) || 'Auth' != $session_tr_type)
        ) {
            $try_update_order = false;
        }
        
        if ($amount > 0
            && !empty($session_tr_type)
            && 'Auth' == $session_tr_type
            && $session_tr_type != $this->plugin_settings[NUVEI_SETTINGS_PREFIX . 'payment_action']
        ) {
            $try_update_order = false;
        }
        
        // in case when APM popup type is changed
        $lood_apmWindowType = isset($nuvei_last_oo_details['apmWindowType'])
            ? $nuvei_last_oo_details['apmWindowType'] : '';
        
        $setting_apm_window_type = isset($this->plugin_settings[NUVEI_SETTINGS_PREFIX . 'apm_window_type']) 
            ? $this->plugin_settings[NUVEI_SETTINGS_PREFIX . 'apm_window_type'] : '';
        
        if ('redirect' == $lood_apmWindowType) {
            if ('redirect' != $setting_apm_window_type) {
                NUVEI_CLASS::create_log(
                    $this->plugin_settings,
                    [
                        '$lood_apmWindowType'       => $lood_apmWindowType,
                        '$setting_apm_window_type'  => $setting_apm_window_type,
                    ],
                    'New Open order desision - new apmWindowType was set'
                );

                $try_update_order = false;
            }
        }
        elseif ('redirect' == $setting_apm_window_type) {
            NUVEI_CLASS::create_log(
                    $this->plugin_settings,
                    [
                        '$lood_apmWindowType'       => $lood_apmWindowType,
                        '$setting_apm_window_type'  => $setting_apm_window_type,
                    ],
                    'New Open order desision - new apmWindowType was set'
                );

            $try_update_order = false;
        }
        
        if ($try_update_order) {
            $resp = $this->update_order($products);
            
            if (!empty($resp['status']) && 'SUCCESS' == $resp['status']) {
                return $resp;
            }
        }
        # /try to update Order
        
        $success_url    = $this->url->link(NUVEI_CONTROLLER_PATH . '/success') 
            . '&order_id=' . $this->session->data['order_id'];
		
        $error_url  = $this->url->link(NUVEI_CONTROLLER_PATH . '/fail') 
            . '&order_id=' . $this->session->data['order_id'];
        
		$oo_params = array(
			'clientUniqueId'	=> $this->session->data['order_id'] . '_' . uniqid(),
			'amount'            => $amount,
            'transactionType'	=> (float) $amount == 0 ? 'Auth' : $this->plugin_settings[NUVEI_SETTINGS_PREFIX . 'payment_action'],
			'currency'          => $this->order_info['currency_code'],
			'userDetails'       => $this->order_addresses['billingAddress'],
			'billingAddress'	=> $this->order_addresses['billingAddress'],
            'shippingAddress'   => $this->order_addresses['shippingAddress'],
            'userTokenId'       => $this->order_addresses['billingAddress']['email'],
            'urlDetails'        => array(
				'backUrl'			=> $this->url->link('checkout/checkout', '', true),
				'notificationUrl'   => $this->url->link(NUVEI_CONTROLLER_PATH . '/callback'),
			),
		);
		
        // change urlDetails
        if ('redirect' == @$this->plugin_settings[NUVEI_SETTINGS_PREFIX . 'apm_window_type']) {
            $oo_params['urlDetails']['successUrl']  = $success_url;
            $oo_params['urlDetails']['pendingUrl']  = $success_url;
            $oo_params['urlDetails']['failureUrl']  = $error_url;
        }
//        elseif (1 == @$this->plugin_settings[NUVEI_SETTINGS_PREFIX . 'auto_close_apm_popup']) {
        else {
            $oo_params['urlDetails']['successUrl']  = $oo_params['urlDetails']['failureUrl']
                                                    = $oo_params['urlDetails']['pendingUrl']
                                                    = NUVEI_SDK_AUTOCLOSE_URL;
        }
        
        $oo_params = array_merge_recursive($oo_params, $rebilling_params);
        
        $oo_params['merchantDetails']['customField1'] = $amount;
        $oo_params['merchantDetails']['customField4'] = $this->order_info['currency_code'];
        
		$resp = NUVEI_CLASS::call_rest_api(
            'openOrder',
            $this->plugin_settings,
            array('merchantId', 'merchantSiteId', 'clientRequestId', 'amount', 'currency', 'timeStamp'),
            $oo_params
        );
		
		if (empty($resp['status']) || empty($resp['sessionToken']) || 'SUCCESS' != $resp['status']) {
			if(!empty($resp['message'])) {
				return $resp;
			}
			
			return array();
		}
        
        // set them to session for the check before submit the data to the SDK
        $this->session->data['nuvei_last_oo_details']['productsHash']       = md5(serialize($products));
        $this->session->data['nuvei_last_oo_details']['amount']             = $oo_params['amount'];
        $this->session->data['nuvei_last_oo_details']['transactionType']    = $oo_params['transactionType'];
        $this->session->data['nuvei_last_oo_details']['sessionToken']       = $resp['sessionToken'];
        $this->session->data['nuvei_last_oo_details']['clientRequestId']    = $resp['clientRequestId'];
        $this->session->data['nuvei_last_oo_details']['orderId']            = $resp['orderId'];
        $this->session->data['nuvei_last_oo_details']['apmWindowType']      = $setting_apm_window_type;
        $this->session->data['nuvei_last_oo_details']['userTokenId']        = $oo_params['userTokenId'];
        $this->session->data['nuvei_last_oo_details']['billingAddress']['country']
            = $oo_params['billingAddress']['country'];
        
        $oo_params['sessionToken'] = $resp['sessionToken'];
		
		return $oo_params;
	}
    
    private function update_order($products)
    {
        NUVEI_CLASS::create_log($this->plugin_settings, 'update_order()');
        
        if (empty($this->session->data['nuvei_last_oo_details'])
			|| empty($this->session->data['nuvei_last_oo_details']['sessionToken'])
			|| empty($this->session->data['nuvei_last_oo_details']['orderId'])
			|| empty($this->session->data['nuvei_last_oo_details']['clientRequestId'])
		) {
			NUVEI_CLASS::create_log(
                $this->plugin_settings,
                'update_order() - exit updateOrder logic, continue with new openOrder.'
            );
			
			return array('status' => 'ERROR');
		}
        
        $amount = $this->get_price($this->order_info['total']);
        
        // updateOrder params
		$params = array(
			'sessionToken'		=> $this->session->data['nuvei_last_oo_details']['sessionToken'],
			'orderId'			=> $this->session->data['nuvei_last_oo_details']['orderId'],
            'clientUniqueId'	=> $this->session->data['order_id'] . '_' . uniqid(),
            'clientRequestId'	=> $this->session->data['nuvei_last_oo_details']['clientRequestId'],
            'currency'          => $this->order_info['currency_code'],
            'amount'            => $amount,
            'userDetails'       => $this->order_addresses['billingAddress'],
            'billingAddress'	=> $this->order_addresses['billingAddress'],
            'shippingAddress'   => $this->order_addresses['shippingAddress'],
            
            'items'				=> array(
				array(
					'name'		=> 'oc3_order',
					'price'		=> $amount,
					'quantity'	=> 1
				)
			),
		);

        // rebiling parameters
        $rebilling_params   = $this->preprare_rebilling_params();
        $params             = array_merge_recursive($params, $rebilling_params);
        
        $oo_params['merchantDetails']['customField1'] = $amount;
        $oo_params['merchantDetails']['customField4'] = $this->order_info['currency_code'];
        
		$resp = NUVEI_CLASS::call_rest_api(
            'updateOrder', 
            $this->plugin_settings, 
            array('merchantId', 'merchantSiteId', 'clientRequestId', 'amount', 'currency', 'timeStamp'), 
            $params
        );
        
        # Success
		if (!empty($resp['status']) && 'SUCCESS' == $resp['status']) {
            $this->session->data['nuvei_last_oo_details']['productsHash']   = md5(serialize($products));
            $this->session->data['nuvei_last_oo_details']['amount']         = $params['amount'];
            $this->session->data['nuvei_last_oo_details']['billingAddress']['country'] 
                = $params['billingAddress']['country'];
            
            // set the order status to Pending if enabled into plugin settings
            if(1 == (int) @$this->plugin_settings[NUVEI_SETTINGS_PREFIX . 'change_order_status']) {
                $pending_status = (int) $this->plugin_settings[NUVEI_SETTINGS_PREFIX . 'pending_status_id'];

                $this->db->query(
                    "UPDATE " . DB_PREFIX ."order "
                    . "SET order_status_id = {$pending_status} "
                    . "WHERE order_id = " . (int) $this->session->data['order_id']
                );
            }
            
			return array_merge($resp, $params);
		}
		
		NUVEI_CLASS::create_log($this->plugin_settings, 'Order update was not successful.');

		return array('status' => 'ERROR');
    }
    
    private function get_order_addresses()
    {
        return array(
            'billingAddress'	=> array(
				"firstName"	=> $this->order_info['payment_firstname'],
				"lastName"	=> $this->order_info['payment_lastname'],
				"address"   => $this->order_info['payment_address_1'],
				"phone"     => $this->order_info['telephone'],
				"zip"       => $this->order_info['payment_postcode'],
				"city"      => $this->order_info['payment_city'],
				'country'	=> $this->order_info['payment_iso_code_2'],
				'email'		=> $this->order_info['email'],
			),
            
            'shippingAddress'    => [
				"firstName"	=> $this->order_info['shipping_firstname'],
				"lastName"	=> $this->order_info['shipping_lastname'],
				"address"   => $this->order_info['shipping_address_1'],
				"phone"     => $this->order_info['telephone'],
				"zip"       => $this->order_info['shipping_postcode'],
				"city"      => $this->order_info['shipping_city'],
				'country'	=> $this->order_info['shipping_iso_code_2'],
				'email'		=> $this->order_info['email'],
			],
        );
    }

    /**
     * If this controller is called via Ajax, this is a call after the client
     * clicked on SDK Pay button.
     * Here we will make last check for product quality.
     */
	private function ajax_call($products)
    {
		NUVEI_CLASS::create_log($this->plugin_settings, 'ajax_call()');
        
        $nuvei_last_oo_details = isset($this->session->data['nuvei_last_oo_details'])
            ? $this->session->data['nuvei_last_oo_details'] : [];
        
        // success
        if (!empty($nuvei_last_oo_details['productsHash'])
            && $nuvei_last_oo_details['productsHash'] == md5(serialize($products))
        ) {
            exit(json_encode(array(
                'success' => 1,
            )));
        }
        
        NUVEI_CLASS::create_log(
            $this->plugin_settings, 
            [
                'products'      => $products,
                'products hash' => md5(serialize($products)),
                'session hash'  => @$nuvei_last_oo_details['productsHash'],
            ]
        );
        
        // error
        exit(json_encode(array(
            'success' => 0,
        )));
        
        
        // check for product quantity
//        $this->load->model('catalog/product');
        
//        $products = $this->cart->getProducts();
        
//        foreach ($products as $product) {
//            $prod_id        = $product['product_id'];
//            $product_data   = $this->model_catalog_product->getProduct($prod_id);
//            
//            if ($product_data['quantity'] < 1) {
//                exit(json_encode(array(
//                    'status'		=> 'error',
//                    'msg'           => $this->language->get('error_product_quantity')
//                )));
//            }
//            
//            NUVEI_CLASS::create_log($this->plugin_settings, $product_data, 'Cart $product_data');
//        }
        // /check for product quantity
        
//        $oo_data = $this->open_order();
//		
//		if(empty($oo_data)) {
//			exit(json_encode(array('status' => 'error')));
//		}
//		
//		exit(json_encode(array(
//			'status'		=> 'success',
//			'sessionToken'	=> $oo_data['sessionToken']
//		)));
	}
	
    /**
     * Save log, return a message to the sender and exit the code.
     * 
     * @param string    $msg
     * @param mixed     $data
     */
    private function return_message($msg, $data = '')
    {
        if(!is_string($msg)) {
            $msg = json_encode($msg);
        }
        
        if(!empty($data)) {
            NUVEI_CLASS::create_log($this->plugin_settings, $data, $msg);
        }
        else {
            NUVEI_CLASS::create_log($this->plugin_settings, $msg);
        }
        
        exit($msg);
    }
    
    private function preprare_rebilling_params()
    {
        $params                 = [];
        $nuvei_rebilling_data   = [];
        
        if(!isset($this->order_info)) {
            $this->order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        }
        
        # check for a product with a Payment Plan
        $rebilling_data = $this->cart->getRecurringProducts();
        
        NUVEI_CLASS::create_log($this->plugin_settings, $rebilling_data, 'Rebilling products data');
        
        if(count($rebilling_data) > 0) {
            foreach($rebilling_data as $data) {
                // check for nuvei into recurring name
                if (strpos(strtolower($data['recurring']['name']), NUVEI_PLUGIN_CODE) === false) {
                    continue;
                }
                
                // get recurring amount for all items
                $recurring_amount_base = $data['recurring']['price'] * $data['quantity'];
                
                // add taxes
                $rec_am_base_taxes = $this->tax->calculate(
                    $recurring_amount_base,
                    $data['tax_class_id'],
                    $this->config->get('config_tax')
                );
                
                // convert the amount with the taxes to the Store currency
                $recurring_amount = $this->get_price($rec_am_base_taxes);
                
                // format base amount with taxes by the Store currency
                $recurring_amount_formatted = $this->currency->format(
                    $rec_am_base_taxes,
                    $this->session->data['currency']
                );
                
                $nuvei_rebilling_data = [
                    'product_id'        => $data['product_id'],
                    'recurring_id'      => $data['recurring']['recurring_id'],
                    'recurring_amount'  => $recurring_amount,
                    'rec_am_formatted'  => $recurring_amount_formatted,
                ];
            }
            
            $params['merchantDetails']['customField3'] = json_encode($nuvei_rebilling_data);
        }
        
        return $params;
    }
    
    private function load_settings()
    {
        if(empty($this->plugin_settings) || null === $this->plugin_settings) {
            $this->load->model('setting/setting');
            $this->plugin_settings  = $this->model_setting_setting->getSetting(trim(NUVEI_SETTINGS_PREFIX, '_'));
        }
    }
    
    /**
     * Get some price by the currency convert rate.
     */
    private function get_price($price)
    {
        $new_price = round((float) $price * $this->order_info['currency_value'], 2);
        return number_format($new_price, 2, '.', '');
    }
    
    private function get_order_info_by_dmn()
    {
        $dmn_type               = NUVEI_CLASS::get_param('dmnType');
        $order_id               = 0;
        $trans_type             = NUVEI_CLASS::get_param('transactionType');
        $relatedTransactionId   = (int) NUVEI_CLASS::get_param('relatedTransactionId');
        $merchant_unique_id     = NUVEI_CLASS::get_param('merchant_unique_id');
        $merchant_uid_arr       = explode('_', $merchant_unique_id);
        
        // default case
        if (is_array($merchant_uid_arr)
            && count($merchant_uid_arr) > 1
            && is_numeric($merchant_uid_arr[0])
        ) {
            $order_id = $merchant_uid_arr[0];
        }
        // CPanel made action
        elseif (!empty($relatedTransactionId)) {
            $query = $this->db->query(
                'SELECT order_id FROM ' . DB_PREFIX . 'order '
                . 'WHERE custom_field = ' . $relatedTransactionId
            );
            
            $order_id = (int) @$query->row['order_id'];
        }
        // Subscription case
        elseif (in_array($dmn_type, ['subscription', 'subscriptionPayment'])) {
            $client_req_id_arr = explode('_', NUVEI_CLASS::get_param('clientRequestId'));
            
            if (is_array($client_req_id_arr)
                && count($client_req_id_arr) > 0
                && is_numeric($client_req_id_arr[0])
            ) {
                $order_id = $client_req_id_arr[0];
            }
        }
        
        $this->order_info = $this->model_checkout_order->getOrder($order_id);
        
        if (!is_array($this->order_info) || empty($this->order_info)) {
            $this->create_auto_void();
            
            // if the auto-void does not break the flow just return 200
            http_response_code(200);
            $this->return_message('There is no Order for this Transaction!.');
        }
        
        // check for Nuvei Order
        if(@$this->order_info['payment_code'] != 'nuvei') {
            $this->return_message('DMN error - the Order does not belongs to the Nuvei.');
        }

        // success
        return;
    }
    
    private function create_auto_void()
    {
        NUVEI_CLASS::create_log($this->plugin_settings, 'Try Auto Void.');
        
        // not allowed Auto-Void
        if (!in_array(NUVEI_CLASS::get_param('transactionType'), array('Auth', 'Sale'), true)) {
            NUVEI_CLASS::create_log($this->plugin_settings, 'The transacion is not in allowed range.');
            return;
        }
        
        // check the time
        $curr_time          = time();
        $order_request_time	= NUVEI_CLASS::get_param('customField2'); // time of create/update order

        if (!is_numeric($order_request_time)) {
            $order_request_time = strtotime($order_request_time);
        }

        // it is too early for auto void
        if ($curr_time - $order_request_time < 1800) {
            http_response_code(400);
            $this->return_message('There is no order info, Let\'s wait one more DMN try.');
        }
        
        // save notifiation
        $transId    = $this->db->escape(NUVEI_CLASS::get_param('TransactionID'));
        $query      = "INSERT INTO `" . DB_PREFIX . "setting` 
            (`store_id`,  `code`, `key`, `value`, `serialized`)
            VALUES (0, '" . trim(NUVEI_SETTINGS_PREFIX, '_') . "', 'trans_problem_" . $transId . "', '". $transId ."', 0)";
        
        $this->db->query($query);
        //
        
        // disabled auto-void
        if (!isset($this->plugin_settings[NUVEI_SETTINGS_PREFIX . 'enable_auto_void'])
            || 'yes' != $this->plugin_settings[NUVEI_SETTINGS_PREFIX . 'enable_auto_void']
        ) {
            NUVEI_CLASS::create_log($this->plugin_settings, 'The Auto Void is disabled.');
            return;
        }
        
        $notify_url     = $this->url->link(NUVEI_CONTROLLER_PATH . '/callback');
        $void_params    = [
            'clientUniqueId'        => date('YmdHis') . '-' . uniqid(),
            'amount'                => (float) NUVEI_CLASS::get_param('totalAmount'),
            'currency'              => NUVEI_CLASS::get_param('currency'),
            'relatedTransactionId'  => $transId,
            'url'                   => $notify_url,
            'urlDetails'            => ['notificationUrl' => $notify_url],
            'customData'            => 'This is Auto-Void transaction',
        ];

        $resp = NUVEI_CLASS::call_rest_api(
            'voidTransaction',
            $this->plugin_settings,
            ['merchantId', 'merchantSiteId', 'clientRequestId', 'clientUniqueId', 'amount', 'currency', 'relatedTransactionId', 'url', 'timeStamp'],
            $void_params
        );
        
        // Void Success
        if (!empty($resp['transactionStatus'])
            && 'APPROVED' == $resp['transactionStatus']
            && !empty($resp['transactionId'])
        ) {
            http_response_code(200);
            $this->return_message('The searched Order does not exists, a Void request was made for this Transacrion.');
        }
        
        return;
    }
    
    private function update_custom_fields($order_id)
    {
        $req_status             = $this->get_request_status();
        $trans_id               = (int) NUVEI_CLASS::get_param('TransactionID');
        $relatedTransactionId   = (int) NUVEI_CLASS::get_param('relatedTransactionId');
        $trans_type             = NUVEI_CLASS::get_param('transactionType', FILTER_SANITIZE_STRING);
        $order_data             = $this->order_info['payment_custom_field'];
        
        if(empty($order_data)) {
            $order_data = array();
        }
        
        NUVEI_CLASS::create_log($this->plugin_settings, $order_data, 'callback() payment_custom_field');
        
        // prevent dublicate data
        foreach($order_data as $trans) {
            if($trans['transactionId'] == $trans_id
                && $trans['transactionType'] == $trans_type
                && $trans['status'] == strtolower($req_status)
            ) {
                NUVEI_CLASS::create_log(
                    $this->plugin_settings, 
                    'Dublicate DMN. We already have this information. Stop here.'
                );

                $this->return_message('Dublicate DMN. We already have this information. Stop here.');
            }
        }
        
        $order_data[] = array(
            'status'                => strtolower((string) $req_status),
            'clientUniqueId'        => NUVEI_CLASS::get_param('clientUniqueId', FILTER_SANITIZE_STRING),
            'transactionType'       => $trans_type,
            'transactionId'         => $trans_id,
            'relatedTransactionId'  => $relatedTransactionId,
            'userPaymentOptionId'   => (int) NUVEI_CLASS::get_param('userPaymentOptionId'),
            'authCode'              => (int) NUVEI_CLASS::get_param('AuthCode'),
            'totalAmount'           => round((float) NUVEI_CLASS::get_param('totalAmount'), 2),
            'currency'              => NUVEI_CLASS::get_param('currency', FILTER_SANITIZE_STRING),
            'paymentMethod'         => NUVEI_CLASS::get_param('payment_method', FILTER_SANITIZE_STRING),
            'responseTimeStamp'     => NUVEI_CLASS::get_param('responseTimeStamp', FILTER_SANITIZE_STRING),
            'originalTotal'         => NUVEI_CLASS::get_param('customField1', FILTER_SANITIZE_STRING),
            'originalCurrency'      => NUVEI_CLASS::get_param('customField4', FILTER_SANITIZE_STRING),
            'totalCurrAlert'        => $this->total_curr_alert,
        );
        
        // all data
        $this->db->query(
            "UPDATE `" . DB_PREFIX . "order` "
            . "SET payment_custom_field = '" . json_encode($order_data) . "' "
            . "WHERE order_id = " . $order_id
        );
        
        // add only transaction ID if the transactions is Auth, Settle or Sale
        if(in_array($trans_type, array('Auth', 'Settle', 'Sale'))) {
            $this->db->query(
                "UPDATE `" . DB_PREFIX . "order` "
                . "SET custom_field = '" . $trans_id . "' "
                . "WHERE order_id = " . $order_id
            );
        }
    }
    
    /**
	 * The start of create subscriptions logic.
	 * We call this method when we've got Settle or Sale DMNs.
	 * 
	 * @param string    $transactionType
	 * @param int       $order_id
	 */
    private function subscription_start($transactionType, $order_id)
    {
        NUVEI_CLASS::create_log(
            $this->plugin_settings,
            [
                'status'        => $this->new_order_status,
                //'order info'    => $this->order_info,
            ],
            'subscription_start()'
        );
        
        $subscr_data = json_decode(NUVEI_CLASS::get_param('customField3'), true);
        
		if (!in_array($transactionType, array('Settle', 'Sale', 'Auth'))
            || 'APPROVED' != $this->get_request_status()
            || !is_array($subscr_data)
            || empty($subscr_data['product_id'])
            || empty($subscr_data['recurring_id'])
            || empty($subscr_data['recurring_amount'])
        ) {
            NUVEI_CLASS::create_log($this->plugin_settings, 'subscription_start() first check fail.');
			return;
		}
        
        // allow recurring only for Zero Auth Orders
        if('Auth' == $transactionType && 0 !== (int) NUVEI_CLASS::get_param('totalAmount')) {
            NUVEI_CLASS::create_log(
                $this->plugin_settings, 
                'The Auth Order total is not Zero. Do not start Rebilling'
            );
            return;
        }
        
        // get recurring data
        $prod_plan = $this->db->query(
            'SELECT * FROM ' . DB_PREFIX . 'recurring '
            . 'WHERE recurring_id = ' . (int) $subscr_data['recurring_id']
        );
		
		if (!is_object($prod_plan) || empty($prod_plan)) {
            NUVEI_CLASS::create_log($this->plugin_settings, $prod_plan, 'Error - $prod_plan problem.');
			return;
		}
        
        // check for more than one products of same type
        $order_products = $this->db->query(
            'SELECT product_id, name, quantity, total '
            . 'FROM ' . DB_PREFIX . 'order_product '
            . 'WHERE order_id = ' . (int) $order_id
        );
        
        if (!is_object($order_products) || empty($order_products->row['quantity'])) {
            NUVEI_CLASS::create_log($this->plugin_settings, $order_products, 'Error - $order_products problem.');
			return;
		}
        
        $qty = $order_products->row['quantity'];
        
        // get Recurring Name and Description
        $rec_descr = $this->db->query(
            'SELECT name '
            . 'FROM ' . DB_PREFIX . 'recurring_description '
            . 'WHERE recurring_id = ' . (int) $prod_plan->row['recurring_id'] . ' '
                . 'AND language_id = ' . (int) $this->config->get('config_language_id')
        );
        
        if (!is_object($rec_descr) || empty($rec_descr->row['name'])) {
            NUVEI_CLASS::create_log($this->plugin_settings, $rec_descr, 'Error - $rec_descr problem.');
			return;
		}
        
        $rec_name = $rec_descr->row['name'];
        
        // save the Order in Recurring Orders section
        $query = 'INSERT INTO ' . DB_PREFIX . 'order_recurring '
            . '(`order_id`, `reference`, `product_id`, `product_name`, `product_quantity`, `recurring_id`, `recurring_name`, `recurring_description`, `recurring_frequency`, `recurring_cycle`, `recurring_duration`, `recurring_price`, `trial`, `trial_frequency`, `trial_cycle`, `trial_duration`, `trial_price`, `status`, `date_added`) '
            . 'VALUES ('. $this->order_info['order_id'] .', '. (int) NUVEI_CLASS::get_param('TransactionID') .', '. $order_products->row['product_id'] .', "'. $order_products->row['name'] .'", '. $qty .', '. $prod_plan->row['recurring_id'] .', "'. $rec_name .'", "'. $rec_name .'", "'. $prod_plan->row['frequency'] .'", '. $prod_plan->row['cycle'] .', '. $prod_plan->row['duration'] .', '. $subscr_data['recurring_amount'] .', '. $prod_plan->row['trial_status'] .', "'. $prod_plan->row['trial_frequency'] .'", '. $prod_plan->row['trial_cycle'] .', '. $prod_plan->row['trial_duration'] .', '. $prod_plan->row['trial_price'] .', 6, NOW())';
        
        //NUVEI_CLASS::create_log($this->plugin_settings, $query, 'insert query');
        
        $this->db->query($query);
        
        // try to start rebillings
        $params = array(
            'clientRequestId'       => $order_id . '_' . uniqid(),
            'userPaymentOptionId'   => (int) NUVEI_CLASS::get_param('userPaymentOptionId'),
            'userTokenId'           => NUVEI_CLASS::get_param('user_token_id'),
            'currency'              => NUVEI_CLASS::get_param('currency'),
            'initialAmount'         => 0,
            'planId'            => @$this->plugin_settings[NUVEI_SETTINGS_PREFIX . 'plan_id'],
            'recurringAmount'   => $subscr_data['recurring_amount'],
            'recurringPeriod'   => [
                $prod_plan->row['frequency'] => $prod_plan->row['cycle'],
            ],
            'startAfter'        => [
                $prod_plan->row['trial_frequency'] => $prod_plan->row['trial_duration']
            ],
            'endAfter'          => [
                $prod_plan->row['frequency'] => $prod_plan->row['duration'],
            ],
        );

        $resp = NUVEI_CLASS::call_rest_api(
            'createSubscription',
            $this->plugin_settings,
            array('merchantId', 'merchantSiteId', 'userTokenId', 'planId', 'userPaymentOptionId', 'initialAmount', 'recurringAmount', 'currency', 'timeStamp'),
            $params
        );

        // On Error
        if (!$resp || !is_array($resp) || empty($resp['status']) || 'SUCCESS' != $resp['status']) {
            $msg = $this->language->get('Error when try to start a Subscription by the Order.');

            if (!empty($resp['reason'])) {
                $msg .= '<br/>' . $this->language->get('Reason: ') . $resp['reason'];
            }

            NUVEI_CLASS::create_log($this->plugin_settings, $msg);

            $this->model_checkout_order->addOrderHistory(
                $this->order_info['order_id'],
                $this->new_order_status,
                $msg,
                true // $send_message
            );
        }

        // On Success
        $msg = $this->language->get('Subscription was created. ') . '<br/>'
            . $this->language->get('Subscription ID: ') . $resp['subscriptionId'] . '.<br/>' 
            . $this->language->get('Recurring amount: ') . $subscr_data['rec_am_formatted'];

        $this->model_checkout_order->addOrderHistory(
            $this->order_info['order_id'],
            $this->new_order_status,
            $msg,
            true // $send_message
        );
			
		return;
    }
    
    private function subscription_cancel($transactionType, $order_id)
    {
        NUVEI_CLASS::create_log(
            $this->plugin_settings,
//            [
//                'order info'    => $this->order_info,
//            ],
            'subscription_cancel()'
        );
        
        if ('Void' != $transactionType || 'APPROVED' != $this->get_request_status()) {
            NUVEI_CLASS::create_log(
                $this->plugin_settings, 
                'We Cancel Subscription only when the Void request is APPROVED.'
            );
			return;
		}
        
        // check for active Subscription
        $query =
            "SELECT order_recurring_id "
            . "FROM ". DB_PREFIX ."order_recurring "
            . "WHERE order_id = " . (int) $order_id . " "
            . "AND status = 1"; // active

        $res = $this->db->query($query);

        if(!isset($res->num_rows) || $res->num_rows == 0) {
            NUVEI_CLASS::create_log(
                $this->plugin_settings, 
                'There is no active Subscription for this Order.'
            );
            return false;
        }
        // /check for active Subscription
        
        $order_data = $this->order_info['payment_custom_field'];
        
        foreach (array_reverse($order_data) as $transaction) {
//            if (!empty($transaction['subscrIDs']) && is_array($transaction['subscrIDs'])) {
            if (!empty($transaction['subscrIDs'])) {
//                foreach ($transaction['subscrIDs'] as $id) {
                    $resp = NUVEI_CLASS::call_rest_api(
                        'cancelSubscription',
                        $this->plugin_settings,
                        array('merchantId', 'merchantSiteId', 'subscriptionId', 'timeStamp'),
//                        ['subscriptionId' => $id]
                        ['subscriptionId' => $transaction['subscrIDs']]
                    );
                    
                    // On Error
                    if (!$resp || !is_array($resp) || 'SUCCESS' != $resp['status']) {
//                        $msg = $this->language->get('Error when try to cancel Subscription #') . $id . ' ';
                        $msg = $this->language->get('Error when try to cancel Subscription #')
                            . $transaction['subscrIDs'] . ' ';

                        if (!empty($resp['reason'])) {
                            $msg .= '<br/>' . $this->language->get('Reason: ', 'nuvei_woocommerce') 
                                . $resp['reason'];
                        }

                        $this->model_checkout_order->addOrderHistory(
                            $this->order_info['order_id'],
                            $this->new_order_status,
                            $msg,
                            true // $send_message
                        );
                    }
//                }
                
                break;
            }
        }
        
		return;
    }
    
    private function process_subs_state()
    {
        NUVEI_CLASS::create_log($this->plugin_settings, 'process_subs_state order_info');
        
        if ('subscription' != NUVEI_CLASS::get_param('dmnType')) {
            return;
        }
            
        $subscriptionState = NUVEI_CLASS::get_param('subscriptionState');
        $subscriptionId    = (int) NUVEI_CLASS::get_param('subscriptionId');

        if (empty($subscriptionState)) {
            $this->return_message('Subscription DMN missing subscriptionState. Stop the process.');
        }

        $this->get_order_info_by_dmn();
        
        if(!$this->order_info || empty($this->order_info)) {
            $this->return_message('DMN error - there is no order info.');
        }

        $order_data         = $this->order_info['payment_custom_field'];
        $rec_order_status   = 6;

        if ('active' == strtolower($subscriptionState)) {
            $message = $this->language->get('Subscription is Active.') . '<br/>'
                . $this->language->get('Subscription ID: ') . $subscriptionId . '<br/>'
                . $this->language->get('Plan ID: ') . (int) NUVEI_CLASS::get_param('planId');

            $rec_order_status = 1;
        }
        elseif ('inactive' == strtolower($subscriptionState)) {
            $message = $this->language->get('Subscription is Inactive.') . '<br/>'
                . $this->language->get('Subscription ID:') . ' ' . $subscriptionId . '<br/>'
                . $this->language->get('Plan ID:') . ' ' . (int) NUVEI_CLASS::get_param('planId');

            $rec_order_status = 2;
        }
        elseif ('canceled' == strtolower($subscriptionState)) {
            $message = $this->language->get('Subscription was canceled.') . '<br/>'
                . $this->language->get('Subscription ID:') . ' ' . $subscriptionId . '<br/>';

            $rec_order_status = 3;
        }

        // save the Subscription ID
        // just add the ID without the details, we need only the ID to cancel the Subscription
        foreach($order_data as $key => $tansaction) {
            if(in_array($tansaction['transactionType'], ['Sale', 'Settle'])) {
//                $order_data[$key]['subscrIDs'][] = (int) NUVEI_CLASS::get_param('subscriptionId');
                $order_data[$key]['subscrIDs'] = (int) NUVEI_CLASS::get_param('subscriptionId');
                break;
            }
            elseif ('Auth' == $tansaction['transactionType'] && 0 == $tansaction['totalAmount']) {
//                $order_data[$key]['subscrIDs'][] = (int) NUVEI_CLASS::get_param('subscriptionId');
                $order_data[$key]['subscrIDs'] = (int) NUVEI_CLASS::get_param('subscriptionId');
                break;
            }
            
        }

        // update Order payment_custom_field
        $this->db->query(
            "UPDATE `" . DB_PREFIX . "order` "
            . "SET payment_custom_field = '" . json_encode($order_data) . "' "
            . "WHERE order_id = " . $this->order_info['order_id']
        );

        NUVEI_CLASS::create_log(
            $this->plugin_settings, 
            $this->order_info['order_status_id'],
            'order status before update order_recurring'
        );

        // update Recurring Order status
        $this->db->query(
            "UPDATE `" . DB_PREFIX . "order_recurring` "
            . "SET status = " . $rec_order_status . " "
            . "WHERE order_id = " . $this->order_info['order_id']
        );

        $this->model_checkout_order->addOrderHistory(
            $this->order_info['order_id'],
            $this->order_info['order_status_id'],
            $message,
            true // $send_message
        );

        $this->return_message('DMN received.');
    }
    
    /**
     * Order recurring transactions types:
     * 0 - Date Added
     * 1 - Payment
     * 2 - Outstanding Payment
     * 3 - Transaction Skipped
     * 4 - Transaction Failed
     * 5 - Transaction Cancelled
     * 6 - Transaction Suspended
     * 7 - Transaction Suspended Failed
     * 8 - Transaction Outstanding Failed
     * 9 - Transaction Expired
     * 
     * 
     * @return void
     */
    private function process_subs_payment()
    {
        NUVEI_CLASS::create_log($this->plugin_settings, 'process_subs_payment()');
        
        $trans_id   = (int) NUVEI_CLASS::get_param('TransactionID');
        $req_status = $this->get_request_status();
        
        if ('subscriptionPayment' != NUVEI_CLASS::get_param('dmnType') || 0 == $trans_id) {
            return;
        }
        
        $this->get_order_info_by_dmn();
        
        // in order_recurring_transaction table we save the total in default value
        // but we get it in Order currency
        $rec_amount_default     = (float) NUVEI_CLASS::get_param('totalAmount') / $this->order_info['currency_value'];
        $rec_amount_formatted   = $this->currency->format(
            $rec_amount_default,
            NUVEI_CLASS::get_param('currency')
        );
        
//        NUVEI_CLASS::create_log($this->plugin_settings, $rec_amount_default);
//        NUVEI_CLASS::create_log($this->plugin_settings, $rec_amount_formatted);
        
        $message = $this->language->get('Subscription Payment was made.') . '<br/>'
            . $this->language->get('Status: ') . $req_status . '<br/>'
            . $this->language->get('Plan ID: ') . (int) NUVEI_CLASS::get_param('planId') . '<br/>'
            . $this->language->get('Subscription ID: ') . (int) NUVEI_CLASS::get_param('subscriptionId') . '<br/>'
            . $this->language->get('Amount: ') . $rec_amount_formatted . '<br/>'
            . $this->language->get('TransactionId: ') . $trans_id;

        NUVEI_CLASS::create_log($this->plugin_settings, $this->order_info['order_status_id'], 'order status when get subscriptionPayment');

        $this->model_checkout_order->addOrderHistory(
            $this->order_info['order_id'],
            $this->order_info['order_status_id'],
            $message,
            true // $send_message
        );

        $order_rec = $this->db->query(
            "SELECT order_recurring_id "
            . "FROM " . DB_PREFIX . "order_recurring "
            . "WHERE order_id = ". (int) $this->order_info['order_id']
        );

        switch(strtolower($req_status)) {
            case 'approved':
                $trans_type = 1;
                break;
            
            case 'declined':
                $trans_type = 5;
                break;
            
            default:
                $trans_type = 4;
                break;
        }
        
        // save the recurring transaction
        $this->db->query(
            "INSERT INTO `" . DB_PREFIX . "order_recurring_transaction` "
            . "(`order_recurring_id`, `reference`, `type`, `amount`, `date_added`) "
            . "VALUES (". $order_rec->row['order_recurring_id'] .", ". $trans_id .", "
                . $trans_type .", ". $rec_amount_default .", NOW())"
        );

        $this->return_message('DMN received.');
    }
    
    /**
     * Just check for the QA site.
     * 
     * @return bool
     */
    private function isQaSite()
    {
        if (!empty($_SERVER['SERVER_NAME']) 
            && 'opencartautomation.gw-4u.com' == $_SERVER['SERVER_NAME']
            && defined('NUVEI_SDK_URL_TAG')
        ) {
            return true;
        }
        
        return false;
    }
    
}
