<?php
if (!defined('_PS_VERSION_'))
  exit;
 
class Turboly extends Module
{
	private $api_url;

    public function __construct()
    {
        $this->name = 'turboly';
        $this->tab = 'merchandizing';
        $this->version = '1.0.0';
        $this->author = 'PT Turboly Teknologi Indonesia';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_); 
        $this->bootstrap = true;

		// Set API URL: For Testing, let's use localhost
		// Do not use trailing slashes, and use slash
		// In every url specified to call the api
		$this->api_url = "localhost:5000";

        parent::__construct();

        $this->displayName = $this->l('Turboly');
        $this->description = $this->l('Turboly integration module.');

        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');

        if (!Configuration::get('TURBOLY'))      
            $this->warning = $this->l('No name provided');
    }

    public function install()
    {
        if (Shop::isFeatureActive())
            Shop::setContext(Shop::CONTEXT_ALL);
        
        if (!parent::install() ||
            !$this->registerHook('actionOrderStatusUpdate') ||
            !Configuration::updateValue('TURBOLY', 'turboly')
        )
            return false;
        
        return true;
    }

    public function uninstall()
    {
        if (!parent::uninstall() ||
            !Configuration::deleteByName('TURBOLY')
        )
            return false;
        
        return true;
    }

    public function getContent()
    {
        $output = null;
    
        if (Tools::isSubmit('submit'.$this->name))
        {
			$user_email = strval(Tools::getValue('user_email'));
			if (!$user_email
			|| empty($user_email))
				$output .= $this->displayError($this->l('Invalid Email'));
			else
			{
				Configuration::updateValue('user_email', $user_email);
			}

			$user_passwd = strval(Tools::getValue('user_passwd'));

			$authenticate = $this->call_api("/api/public/v1/get_token", [
				"type" => 'post',
				"fields" => [
					"email" => Configuration::get('user_email'),
					"password" => $user_passwd,
				]
			]);

			if ($authenticate->status == "success") {
				$token = $authenticate->data->token;
				Configuration::updateValue('authentication_token', $token);
			} else {
				$output .= $this->displayError($this->l('Invalid Credentials'));
			}
		
			$output .= $this->displayConfirmation($this->l('Settings updated'));
        }

        return $output.$this->displayForm();
    }

	public function displayForm()
	{
		$default_lang = (int)Configuration::get('PS_LANG_DEFAULT');
		
		$fields_form[0]['form'] = array(
			'legend' => array(
				'title' => $this->l('Authentication'),
			),
			'input' => array(
				array(
					'type' => 'text',
					'label' => $this->l('User Email'),
					'name' => 'user_email',
					'size' => 20,
					'required' => true
				),
				array(
					'type' => 'password',
					'label' => $this->l('User Password'),
					'name' => 'user_passwd',
					'size' => 20,
					'required' => true
				),
			),
			'submit' => array(
				'title' => $this->l('Save'),
				'class' => 'btn btn-default pull-right'
			)
		);
		
		$helper = new HelperForm();
		
		$helper->module = $this;
		$helper->name_controller = $this->name;
		$helper->token = Tools::getAdminTokenLite('AdminModules');
		$helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
		
		$helper->default_form_language = $default_lang;
		$helper->allow_employee_form_lang = $default_lang;
		
		$helper->title = $this->displayName;
		$helper->show_toolbar = true;        
		$helper->toolbar_scroll = true;      
		$helper->submit_action = 'submit'.$this->name;
		$helper->toolbar_btn = array(
			'save' =>
			array(
				'desc' => $this->l('Save'),
				'href' => AdminController::$currentIndex.'&configure='.$this->name.'&save'.$this->name.
				'&token='.Tools::getAdminTokenLite('AdminModules'),
			),
			'back' => array(
				'href' => AdminController::$currentIndex.'&token='.Tools::getAdminTokenLite('AdminModules'),
				'desc' => $this->l('Back to list')
			)
		);
		
		$helper->fields_value['user_email'] = Configuration::get('user_email');
		
		return $helper->generateForm($fields_form);
	}

	function hookActionOrderStatusUpdate($params) {
		$order_status = $params['newOrderStatus'];
		$order_id = $params['id_order'];
		$order = new Order($order_id);

		$total = $order->total_paid;
		$total_inc_tax = $order->total_paid_tax_incl;
		
		$cart_id = $order->id_cart;
		$cart = new Cart($cart_id);

		$order_products = $cart->getProducts();
		$products = [];
		foreach ($order_products as $product) {
			$products[] = [
				'sku' => $product['reference'],
				'price' => $product['price'],
				'qty' => $product['cart_quantity']
			];
		}
		
		$customer_id = $order->id_customer;
		$customer = new Customer($customer_id);

		if ($order_status->name == "Payment accepted") {
			$sales = $this->call_api("/api/public/v1/sales", [
				"auth" => true,
				"type" => "post",
				"fields" => [
					"request_uuid" => $this->generate_request_uuid(),
					"platform" => "prestashop",
					"sale" => [
						"currency" => "IDR",
						"customer" => $customer,
						"notes" => "Online sales via Prestashop",
						"sale_lines" => $products,
						"total_inc_tax" => $total_inc_tax,
					]
				]
			]);

            if ($sales->status == "success") {
				return true;
			}

			return false;
		}

		return true;
	}

	function call_api($url, $options = []) {
		$url = $this->api_url . $url;
		$ch = curl_init( $url );

		$headers = array(
			'Accept: application/json',
			'Content-type: multipart/form-data',
		);

		if (array_key_exists('auth', $options) && $options['auth'] == true) {
			$auth_headers = [
				'X-AUTH-EMAIL: ' . Configuration::get('user_email'),
				'X-AUTH-TOKEN: ' . Configuration::get('authentication_token'),
			];

			$headers = array_merge($headers, $auth_headers);
		}

		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		if(array_key_exists('type', $options) && $options['type'] == 'post') {
			curl_setopt($ch, CURLOPT_POST, true);
		}

		if(array_key_exists('fields', $options)) {
			$fields_string = http_build_query($options['fields']);
			// foreach($options['fields'] as $key => $value) { $fields_string .= $key.'='.$value.'&'; }
			rtrim($fields_string, '&');
			
			curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
		}

		$content = curl_exec( $ch );
		curl_close( $ch );

		return json_decode($content);
	}

	function generate_request_uuid() {
		return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),

			mt_rand( 0, 0xffff ),

			mt_rand( 0, 0x0fff ) | 0x4000,

			mt_rand( 0, 0x3fff ) | 0x8000,

			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
		);
	}
}