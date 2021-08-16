<?php

namespace Jigoshop\Payment;

use Jigoshop\Endpoint\Processable;
use Jigoshop\Container;  
use Jigoshop\Core;
use Jigoshop\Core\Messages;
use Jigoshop\Core\Options;
use Jigoshop\Entity\Customer\CompanyAddress;
use Jigoshop\Entity\Order;
use Jigoshop\Entity\Product;
use Jigoshop\Helper\Api;
use Jigoshop\Helper\Currency;
use Jigoshop\Helper\Order as OrderHelper;
use Jigoshop\Helper\Validation;
use Monolog\Registry;
use WPAL\Wordpress; 

class Nochex implements Method2, Processable
{
	const ID = 'nochex';
	const LIVE_URL = 'https://secure.nochex.com/default.aspx'; 

	/** @var Wordpress */
	private $wp;
	/** @var Options */
	private $options; 
	/** @var Messages */
	private $messages;
	/** @var array */
	private $settings;
	/** @var Container */
	private $di;

	public function __construct(Wordpress $wp, Container $di, Options $options, Messages $messages)
	{
		$this->wp = $wp;
		$this->di = $di;
		$this->options = $options;
		$this->messages = $messages;
		$this->settings = $options->get('payment.'.self::ID);
				
		if (isset($_REQUEST["acapc"]) == 1){		
		 $this->wp->addAction('jigoshop_endpoint_nochex', $this->processResponse(), 1);
		}
	}
	

	public function isEnabled()
	{
		return $this->settings['enabled'];
	}

	public function isActive() {
		if(isset($this->settings['enabled'])) {
			return $this->settings['enabled'];
		}
	}

	public function setActive($state) {
		$this->settings['enabled'] = $state;

		return $this->settings;
	}
	
	public function isConfigured() {
			if(isset($this->settings['email']) && $this->settings['email']) {
				return true;
			}else{
				return false;
			}
	}
	
	public function hasTestMode() {
		return true;
	}

	public function isTestModeEnabled() {
		if(isset($this->settings['test_mode'])) {
			return $this->settings['test_mode'];
		}
	}

	public function setTestMode($state) {
		$this->settings['test_mode'] = $state;	
		return $this->settings;
	}	

	/**
	 * @return string ID of payment method.
	 */
	public function getId()
	{
		return self::ID;
	}

	/**
	 * @return string Human readable name of method.
	 */
	public function getName()
	{	
		return "Nochex" . $this->getLogoImage();
	}

	private function getLogoImage()
	{
		return '<img src="https://www.nochex.com/logobase-secure-images/logobase-banners/clear.png" style="width:250px;height:auto;margin: auto 30px;" alt="" class="payment-logo" />';
	}

	/**
	 * @return array List of options to display on Payment settings page.
	 */
	public function getOptions()
	{
		return array(
			array(
				'name' => sprintf('[%s][enabled]', self::ID),
				'title' => __('Is enabled?', 'jigoshop'),
				'type' => 'checkbox',
				'checked' => $this->settings['enabled'],
				'classes' => array('switch-medium'),
			),
			array(
				'name' => sprintf('[%s][title]', self::ID),
				'title' => __('Title', 'jigoshop'),
				'type' => 'text',
				'value' => $this->settings['title'],
			),			
			array(
				'name' => sprintf('[%s][email]', self::ID),
				'title' => __('Nochex Merchant ID / Email Address', 'jigoshop'),
				'tip' => __('Please enter your Nochex email address / merchant alias id; this is needed in order to take payment!', 'jigoshop'),
				'type' => 'text',
				'value' => $this->settings['email'],
			),
			array(
				'name' => sprintf('[%s][send_shipping]', self::ID),
				'title' => __('Postage', 'jigoshop'),
				'tip' => __('Postage option is to separate the postage from the total amount', 'jigoshop'),
				'type' => 'checkbox',
				'checked' => $this->settings['send_shipping'],
				'classes' => array('switch-medium'),
			),
			array(
				'name' => sprintf('[%s][test_mode]', self::ID),
				'title' => __('Enable Test Mode', 'jigoshop'),
				'tip' => 'Test Mode is used to test that your shopping cart is working. Leave disabled for live transactions.',
				'type' => 'checkbox',
				'checked' => $this->settings['test_mode'],
				'classes' => array('switch-medium'),
			),
			array(
				'name' => sprintf('[%s][hide_mode]', self::ID),
				'title' => __('Hide Billing Details', 'jigoshop'),
				'tip' => 'Hide Billing Details option, used to hide the billing details on your payment page. <span>We advise if you enable this option to place a note on your checkout page for your customers to check their billing details as they wont be able to modify them on your Nochex Payment Page</span>',
				'type' => 'checkbox',
				'checked' => $this->settings['hide_mode'],
				'classes' => array('switch-medium'),
			),
			array(
				'name' => sprintf('[%s][product_details]', self::ID),
				'title' => __('Detailed Product Information', 'jigoshop'),
				'tip' => 'Display your product details in a structured format on your Nochex Payment Page.',
				'type' => 'checkbox',
				'checked' => $this->settings['product_details'],
				'classes' => array('switch-medium'),
			),
			array(
				'name' => sprintf('[%s][callback_mode]', self::ID),
				'title' => __('Callback', 'jigoshop'),
				'tip' => 'To use the callback functionality, please contact Nochex Support to enable this on your mercnaht account otherwise this will not work!',
				'type' => 'checkbox',
				'checked' => $this->settings['callback_mode'],
				'classes' => array('switch-medium'),
			),	
			array(
				'name' => sprintf('[%s][debug_mode]', self::ID),
				'title' => __('Debug', 'jigoshop'),
				'tip' => 'Debug mode is to help test and make sure your Nochex module is working correctly and if there are any faults with this module',
				'type' => 'checkbox',
				'checked' => $this->settings['debug_mode'],
				'classes' => array('switch-medium'),
			),			
		);
	}

	/**
	 * Validates and returns properly sanitized options.
	 *
	 * @param $settings array Input options.
	 *
	 * @return array Sanitized result.
	 */
	public function validateOptions($settings)
	{
		$settings['enabled'] =  $settings['enabled'] == 'on';
		$settings['title'] = trim(htmlspecialchars(strip_tags($settings['title'])));
		$settings['email'] = trim(htmlspecialchars(strip_tags($settings['email'])));
	
		$settings['send_shipping'] = $settings['send_shipping'] == 'on';
		$settings['product_details'] = $settings['product_details'] == 'on';
		$settings['hide_mode'] = $settings['hide_mode'] == 'on';		
		$settings['debug_mode'] = $settings['debug_mode'] == 'on';		
		$settings['test_mode'] = $settings['test_mode'] == 'on';		
		$settings['callback_mode'] = $settings['callback_mode'] == 'on';
		
		return $settings;
	}

	/**
	 * Renders method fields and data in Checkout page.
	 */
	public function render()
	{
		echo $this->settings['description'];
	}

	/**
	 * @param Order $order Order to process payment for.
	 *
	 * @return bool Is processing successful?
	 */
	public function process($order)
	{
		
		$billingAddress = $order->getCustomer()->getBillingAddress();
		$shippingAddress = $order->getCustomer()->getShippingAddress();
  
		if ($this->settings['test_mode']) {
			$testMode = "100";
		} else {
			$testMode = "";
		}
		
		if ($this->settings['hide_mode']) {
			$hideMode = "true";
		} else {
			$hideMode = "";
		}
		
		$product_details = "<items>";
		
		foreach ($order->getItems() as $item) {
		
		$product = $item->getProduct();
		
		$description .= $product->getName() . ", (" . $item->getQuantity() . " x " . number_format($item->getPrice(), 2, '.', '') . ")";
		
		$product_details .= "<item><id></id><name>".$product->getName()."</name><description>".$product->getName()."</description><quantity>" . $item->getQuantity() . "</quantity><price>" . number_format($item->getPrice(), 2, '.', '') . "</price></item>";
		}
		
		$product_details .= "</items>";
		 
		if ($this->settings['product_details']) {
			$description = "Payment for " . $order->getNumber();
		} else {
			$product_details = "";
		}
		 
		if ($this->settings['send_shipping']) {
			 $totalAmount = number_format(number_format($order->getTotal(), 2, '.', '') - number_format($order->getShippingPrice(), 2, '.', ''), 2, '.', '');
			 $postage = number_format($order->getShippingPrice(), 2, '.', '');
		} else {
			 $totalAmount = number_format($order->getTotal(), 2, '.', '');
		}
		
		if ($this->settings['callback_mode']) {
		$callbackMode = "Enabled";
		}else{
		$callbackMode = "";
		}
		
		$orderID = $order->getId();		 
		
		$payForm = '<form action="'.self::LIVE_URL.'" method="post" id="nochex_payment_form">
				<input type="hidden" name="merchant_id" value="'.$this->settings['email'].'" />
				<input type="hidden" name="amount" value="'.$totalAmount.'" />
				<input type="hidden" name="postage" value="'.$postage .'" />
				<input type="hidden" name="description" value="'.$description .'" />
				<input type="hidden" name="xml_item_collection" value="'.$product_details .'" />
				<input type="hidden" name="order_id" value="'.$order->getNumber().'" />				
				<input type="hidden" name="billing_fullname" value="'.$billingAddress->getFirstName().' '.$billingAddress->getLastName().'" />
				<input type="hidden" name="billing_address" value="'.$billingAddress->getAddress().'" />
				<input type="hidden" name="billing_city" value="'.$billingAddress->getCity().'" />
				<input type="hidden" name="billing_postcode" value="'.$billingAddress->getPostcode().'" />
				<input type="hidden" name="delivery_fullname" value="'.$shippingAddress->getFirstName().' '.$shippingAddress->getLastName().'" />
				<input type="hidden" name="delivery_address" value="'.$shippingAddress->getAddress().'" />
				<input type="hidden" name="delivery_city" value="'.$shippingAddress->getCity().'" />
				<input type="hidden" name="delivery_postcode" value="'.$shippingAddress->getPostcode().'" />
				<input type="hidden" name="email_address" value="'.$billingAddress->getEmail().'" />
				<input type="hidden" name="customer_phone_number" value="'.$billingAddress->getPhone().'" />				
				<input type="hidden" name="success_url" value="'.OrderHelper::getThankYouLink($order).'" />							
				<input type="hidden" name="test_success_url" value="'.OrderHelper::getThankYouLink($order).'" />							
				<input type="hidden" name="cancel_url" value="'.OrderHelper::getCancelLink($order).'" />							
				<input type="hidden" name="callback_url" value="'.Api::getUrl(self::ID).'?acapc=1" />							
				<input type="hidden" name="test_transaction" value="'.$testMode.'" />							
				<input type="hidden" name="hide_billing_details" value="'.$hideMode.'" />							
				<input type="hidden" name="optional_1" value="'.$orderID.'" />							
				<input type="hidden" name="optional_2" value="'.$callbackMode.'" />							
				<input type="submit" class="button-alt" id="submit_nochex_payment_form" value="'.__('Pay via Nochex', 'jigoshop').'" /> 
				</form> 
			<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>	
			<script type="text/javascript">
			(function($){
			$(document).ready(function(){
				$("#submit_nochex_payment_form").click();
			})
			})(jQuery);
			</script>';
			
		if ($this->settings['debug_mode']){
		 
		 Registry::getInstance(JIGOSHOP_LOGGER)->addWarning("Nochex Payment Form /r/n" . $payForm . "/r/n");

		}
		
		echo $payForm;
			
	}

// your merchant account email address

	public function processResponse(){
	
	// Get the POST information from Nochex server
	$postvars = http_build_query($_POST);
	
		
if ($_POST["optional_2"] == "Enabled"){

	if ($this->settings['debug_mode']){
		 
		 Registry::getInstance(JIGOSHOP_LOGGER)->addWarning("CALLBACK: " . $msg . "/r/n");

	}
	
	// Set parameters for the email
	$url = "https://secure.nochex.com/callback/callback.aspx";

		$ch = curl_init(); // Initialise the curl tranfer
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_VERBOSE, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postvars); // Set POST fields
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		$response = curl_exec($ch); // Post back
		curl_close($ch);
	
	// stores the response from the Nochex server 
	$debug = "IP -> " . $_SERVER['REMOTE_ADDR'] ."\r\n\r\nPOST DATA:\r\n"; 
	foreach($_POST as $Index => $Value) 
	$debug .= "$Index -> $Value\r\n"; 
	$debug .= "\r\nRESPONSE:\r\n$response"; 		
	

	if ($_POST["transaction_status"] == "100"){
		$testStatus = "test";
	}else{
		$testStatus = "live";
	}
	 
	if ($response == "AUTHORISED") {  // searches response to see if AUTHORISED is present if it isnt a failure message is displayed
		$msg = "Callback was AUTHORISED. This was a " . $testStatus . " transaction.";// if AUTHORISED was found in the response then it was successful
	} else { 
		$msg = "Callback was not AUTHORISED.\r\n\r\n$debug";  // displays debug message  
	} 
	 /** @var \Jigoshop\Service\OrderService $service */
		/*$this->updateOrder($_POST["order_id"]);*/				 
	wp_update_post(array('ID'=>$_POST["custom"],'post_status'=>'jigoshop-processing'));	 
	
	}else{

	if ($this->settings['debug_mode1']){
		 
		 Registry::getInstance(JIGOSHOP_LOGGER)->addWarning("ORDER //// ".$_SESSION["order"]);

	}
	
	// Set parameters for the email
	$url = "https://www.nochex.com/apcnet/apc.aspx";

	// Curl code to post variables back
	$ch = curl_init(); // Initialise the curl tranfer
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_VERBOSE, true);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $postvars); // Set POST fields
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
	$response = curl_exec($ch); // Post back
	
	// stores the response from the Nochex server 
	$debug = "IP -> " . $_SERVER['REMOTE_ADDR'] ."\r\n\r\nPOST DATA:\r\n"; 
	foreach($_POST as $Index => $Value) 
	$debug .= "$Index -> $Value\r\n"; 
	$debug .= "\r\nRESPONSE:\r\n$response"; 		
		 
	if ($response == "AUTHORISED") {  // searches response to see if AUTHORISED is present if it isnt a failure message is displayed
		$msg = "APC was AUTHORISED. This was a " . $_POST["status"] . " transaction.";// if AUTHORISED was found in the response then it was successful
	} else { 
	   $msg = "APC was not AUTHORISED.\r\n\r\n$debug";  // displays debug message  
	} 

wp_update_post(array('ID'=>$_POST["custom"],'post_status'=>'jigoshop-processing'));

} 
	
}
	
}
