<?php
namespace EVP\Gateway;

if (!defined('ABSPATH')) {
   exit;
}

class Payment_Gateway extends \WC_Payment_Gateway {
   private function log($message) {
       $log_file = EVP_PLUGIN_DIR . 'logs/payment-debug.log';
       $timestamp = current_time('Y-m-d H:i:s');
       file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
   }

   public $target_address;
   public $contract_address;
   public $token_decimals;
   public $blockchain_network;
   public $abi_array;

   public function __construct() {
       $this->id                 = 'evm_payment';
       $this->icon              = apply_filters('evm_payment_icon', '');
       $this->has_fields        = false;
       $this->method_title      = __('EVM Token Payment', 'evm-payment-gateway');
       $this->method_description = __('Accept EVM-compatible token payments through MetaMask', 'evm-payment-gateway');

       $this->init_form_fields();
       $this->init_settings();

       $this->title             = $this->get_option('title');
       $this->description       = $this->get_option('description');
       $this->target_address    = $this->get_option('target_address');
       $this->contract_address  = $this->get_option('contract_address');
       $this->token_decimals    = $this->get_option('token_decimals', 18);
       $this->blockchain_network = $this->get_option('blockchain_network');
       $this->abi_array         = $this->get_option('abi_array');

       add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
       add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
       add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
       
       add_action('wp_ajax_verify_payment', array($this, 'verify_payment'));
       add_action('wp_ajax_nopriv_verify_payment', array($this, 'verify_payment'));

       $this->log('Payment gateway initialized');
   }

   public function init_form_fields() {
       $this->form_fields = array(
           'enabled' => array(
               'title'   => __('Enable/Disable', 'evm-payment-gateway'),
               'type'    => 'checkbox',
               'label'   => __('Enable EVM Token Payments', 'evm-payment-gateway'),
               'default' => 'no'
           ),
           'title' => array(
               'title'       => __('Title', 'evm-payment-gateway'),
               'type'        => 'text',
               'description' => __('This controls the title which the user sees during checkout.', 'evm-payment-gateway'),
               'default'     => __('EVM Token Payment', 'evm-payment-gateway'),
               'desc_tip'    => true,
           ),
           'description' => array(
               'title'       => __('Description', 'evm-payment-gateway'),
               'type'        => 'textarea',
               'description' => __('Payment method description that the customer will see on your checkout.', 'evm-payment-gateway'),
               'default'     => __('Pay with your EVM-compatible wallet via MetaMask.', 'evm-payment-gateway'),
               'desc_tip'    => true,
           ),
           'target_address' => array(
               'title'       => __('Recipient Address', 'evm-payment-gateway'),
               'type'        => 'text',
               'description' => __('Your EVM wallet address', 'evm-payment-gateway'),
               'desc_tip'    => true,
           ),
           'contract_address' => array(
               'title'       => __('Token Contract', 'evm-payment-gateway'),
               'type'        => 'text',
               'description' => __('Token contract address', 'evm-payment-gateway'),
               'desc_tip'    => true,
           ),
           'token_decimals' => array(
               'title'       => __('Token Decimals', 'evm-payment-gateway'),
               'type'        => 'number',
               'description' => __('The number of decimal places for the token.', 'evm-payment-gateway'),
               'default'     => '18',
               'desc_tip'    => true,
           ),
           'blockchain_network' => array(
               'title'       => __('Network ID', 'evm-payment-gateway'),
               'type'        => 'text',
               'description' => __('The blockchain network ID (e.g., 1 for Ethereum Mainnet)', 'evm-payment-gateway'),
               'desc_tip'    => true,
           ),
           'abi_array' => array(
               'title'       => __('Contract ABI', 'evm-payment-gateway'),
               'type'        => 'textarea',
               'description' => __('The ABI of the token contract', 'evm-payment-gateway'),
               'desc_tip'    => true,
           ),
       );
   }

   public function payment_scripts() {
       if (!is_checkout_pay_page() && !is_wc_endpoint_url('order-received')) {
           return;
       }

       // Get the correct plugin URL
       $plugin_url = plugins_url('/', EVP_PLUGIN_FILE);
       $this->log('Plugin URL: ' . $plugin_url);

       wp_enqueue_script('web3', 'https://cdn.jsdelivr.net/npm/web3@1.5.2/dist/web3.min.js', array('jquery'), null, true);
       wp_register_script('evm-payments', plugins_url('/assets/js/payments.js', dirname(dirname(__FILE__))), array('jquery', 'web3'), '1.0.0', true);
       wp_localize_script('evm-payments', 'evmPaymentData', array(
           'ajaxUrl' => admin_url('admin-ajax.php'),
           'nonce' => wp_create_nonce('evm_payment_nonce'),
           'networkId' => $this->blockchain_network,
           'contractAddress' => $this->contract_address,
           'targetAddress' => $this->target_address,
           'tokenDecimals' => $this->token_decimals,
           'abiArray' => json_decode($this->abi_array)
       ));
       wp_enqueue_script('evm-payments');
       wp_enqueue_style('evm-payment-styles', plugins_url('/assets/css/styles.css', dirname(dirname(__FILE__))), array(), '1.0.0');
   }

   public function process_payment($order_id) {
       $this->log('Processing payment for order: ' . $order_id);
       
       $order = wc_get_order($order_id);
       $order->update_status('pending', __('Awaiting token payment', 'evm-payment-gateway'));
       
       return array(
           'result' => 'success',
           'redirect' => $this->get_return_url($order)
       );
   }

   public function verify_payment() {
       $this->log('========== Payment Verification Started ==========');
       $this->log('REQUEST data: ' . print_r($_REQUEST, true));
       $this->log('POST data: ' . print_r($_POST, true));

       try {
           if (!wp_verify_nonce($_POST['nonce'], 'evm_payment_nonce')) {
           $this->log('Nonce check: ' . wp_verify_nonce($_POST['nonce'], 'evm_payment_nonce'));
               throw new \Exception('Security check failed');
           }

           $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
           $tx_hash = isset($_POST['tx']) ? sanitize_text_field($_POST['tx']) : '';

           if (!$order_id || !$tx_hash) {
               throw new \Exception('Missing required data');
           }

           if (strlen($tx_hash) !== 66 || substr($tx_hash, 0, 2) !== '0x') {
               throw new \Exception('Invalid transaction hash');
           }

           $order = wc_get_order($order_id);
           if (!$order) {
               throw new \Exception('Invalid order');
           }

           if (!$order->needs_payment()) {
               throw new \Exception('Order already paid');
           }

           $order->payment_complete();
           $order->add_order_note(sprintf(
               __('Payment completed - Transaction Hash: %s', 'evm-payment-gateway'),
               esc_html($tx_hash)
           ));

           wp_send_json_success(array(
               'message' => 'Payment verified successfully',
               'redirect' => $this->get_return_url($order)
           ));

       } catch (\Exception $e) {
           $this->log('Verification error: ' . $e->getMessage());
           wp_send_json_error(array('message' => $e->getMessage()));
       }
   }

   public function thankyou_page($order_id) {
       if (!$order_id) {
           return;
       }

       $order = wc_get_order($order_id);
       if (!$order || !$order->needs_payment()) {
           return;
       }

       echo '<div id="evm-payment-container" class="evm-payment-wrapper">';
       echo '<h2>' . esc_html__('Complete Your Token Payment', 'evm-payment-gateway') . '</h2>';
       echo '<div id="evm-payment-error" class="woocommerce-error" style="display:none;"></div>';
       
       wp_localize_script('evm-payments', 'evmPaymentConfig', array(
           'orderId' => $order_id,
           'amount' => $order->get_total()
       ));

       echo '<button type="button" class="button alt" id="evm-payment-button">' . 
           esc_html__('Pay with MetaMask', 'evm-payment-gateway') . 
       '</button>';
       echo '</div>';
   }
}
