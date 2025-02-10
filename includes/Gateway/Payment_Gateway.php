<?php
namespace EVP\Gateway;

if (!defined('ABSPATH')) {
    exit;
}

class Payment_Gateway extends \WC_Payment_Gateway {
    /**
     * @var string
     */
    public $target_address;
    
    /**
     * @var string
     */
    public $contract_address;
    
    /**
     * @var int
     */
    public $token_decimals;
    
    /**
     * @var string
     */
    public $blockchain_network;
    
    /**
     * @var string
     */
    public $abi_array;

    /**
     * Constructor for the gateway.
     */
    public function __construct() {
        $this->id                 = 'evm_payment';
        $this->icon              = apply_filters('evm_payment_icon', '');
        $this->has_fields        = false;
        $this->method_title      = __('EVM Token Payment', 'evm-payment-gateway');
        $this->method_description = __('Accept EVM-compatible token payments through MetaMask', 'evm-payment-gateway');

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->title             = $this->get_option('title');
        $this->description       = $this->get_option('description');
        $this->target_address    = $this->get_option('target_address');
        $this->contract_address  = $this->get_option('contract_address');
        $this->token_decimals    = $this->get_option('token_decimals', 18);
        $this->blockchain_network = $this->get_option('blockchain_network');
        $this->abi_array         = $this->get_option('abi_array');

        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
        add_action('wp_ajax_verify_evm_payment', array($this, 'verify_payment'));
        add_action('wp_ajax_nopriv_verify_evm_payment', array($this, 'verify_payment'));
    }

    /**
     * Initialize Gateway Settings Form Fields
     */
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
                'description' => __('The wallet address that will receive the token payments.', 'evm-payment-gateway'),
                'desc_tip'    => true,
            ),
            'contract_address' => array(
                'title'       => __('Token Contract', 'evm-payment-gateway'),
                'type'        => 'text',
                'description' => __('The smart contract address of the token.', 'evm-payment-gateway'),
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
                'description' => __('The blockchain network ID (e.g., 1 for Ethereum Mainnet, 56 for BSC).', 'evm-payment-gateway'),
                'desc_tip'    => true,
            ),
            'abi_array' => array(
                'title'       => __('Contract ABI', 'evm-payment-gateway'),
                'type'        => 'textarea',
                'description' => __('The ABI of the token contract.', 'evm-payment-gateway'),
                'desc_tip'    => true,
            ),
        );
    }

    /**
     * Load payment scripts
     */
    public function payment_scripts() {
        if (!is_checkout_pay_page() && !is_wc_endpoint_url('order-received')) {
            return;
        }

        wp_enqueue_script('web3', 'https://cdn.jsdelivr.net/npm/web3@1.5.2/dist/web3.min.js', array('jquery'), null, true);
        wp_enqueue_script('evm-payments', plugins_url('/assets/js/payments.js', EVP_PLUGIN_FILE), array('jquery', 'web3'), EVP_VERSION, true);
        wp_enqueue_style('evm-payment-styles',plugins_url('/assets/css/styles.css', EVP_PLUGIN_FILE),array(),EVP_VERSION);

        wp_localize_script('evm-payments', 'evmPaymentData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('evm_payment_nonce'),
            'networkId' => $this->blockchain_network,
            'contractAddress' => $this->contract_address,
            'targetAddress' => $this->target_address,
            'tokenDecimals' => $this->token_decimals,
            'abiArray' => json_decode($this->abi_array)
        ));
    }

    /**
     * Process the payment
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        
        $order->update_status('pending', __('Awaiting EVM token payment', 'evm-payment-gateway'));
        
        return array(
            'result' => 'success',
            'redirect' => $this->get_return_url($order)
        );
    }

    /**
     * Verify payment via AJAX
     */
    public function verify_payment() {
        error_log('********** EVM Payment Verification Started **********');
        error_log('POST data: ' . print_r($_POST, true));

        try {
            if (!check_ajax_referer('evm_payment_nonce', 'nonce', false)) {
                error_log('Nonce verification failed');
                wp_send_json_error(array('message' => 'Security check failed'));
                return;
            }

            $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
            $tx_hash = isset($_POST['tx']) ? sanitize_text_field($_POST['tx']) : '';

            error_log(sprintf('Processing - Order ID: %s, TX Hash: %s', $order_id, $tx_hash));

            if (!$order_id || !$tx_hash) {
                error_log('Missing required fields');
                wp_send_json_error(array('message' => 'Missing required data'));
                return;
            }

            if (strlen($tx_hash) !== 66 || substr($tx_hash, 0, 2) !== '0x') {
                error_log('Invalid transaction hash format');
                wp_send_json_error(array('message' => 'Invalid transaction hash'));
                return;
            }

            $order = wc_get_order($order_id);
            if (!$order) {
                error_log('Order not found: ' . $order_id);
                wp_send_json_error(array('message' => 'Invalid order'));
                return;
            }

            if (!$order->needs_payment()) {
                error_log('Order already paid: ' . $order_id);
                wp_send_json_error(array('message' => 'Order already paid'));
                return;
            }

            $order->payment_complete();
            $order->add_order_note(sprintf(
                __('Payment completed - Transaction Hash: %s', 'evm-payment-gateway'),
                esc_html($tx_hash)
            ));

            error_log('Payment verification successful');
            wp_send_json_success(array(
                'message' => 'Payment verified successfully',
                'redirect' => $this->get_return_url($order)
            ));

        } catch (Exception $e) {
            error_log('Payment verification error: ' . $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * Thank you page
     */
    public function thankyou_page($order_id) {
        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order || !$order->needs_payment()) {
            return;
        }

        wp_enqueue_script('evm-payments');
        
        echo '<div id="evm-payment-container" class="evm-payment-wrapper">';
        echo '<h2>' . esc_html__('Complete Your Token Payment', 'evm-payment-gateway') . '</h2>';
        echo '<div id="evm-payment-error" class="woocommerce-error" style="display:none;"></div>';
        
        wp_localize_script('evm-payments', 'evmPaymentConfig', array(
            'orderId' => $order_id,
            'amount' => $order->get_total()
        ));

        echo sprintf(
            '<button onclick="window.evmPayment.requestPayment(%s)" class="button alt">%s</button>',
            esc_js($order->get_total()),
            esc_html__('Pay with MetaMask', 'evm-payment-gateway')
        );
        echo '</div>';
    }

    /**
     * Validate settings
     */
    public function validate_settings() {
        $valid = true;
        
        if (empty($this->target_address) || !preg_match('/^0x[a-fA-F0-9]{40}$/', $this->target_address)) {
            $valid = false;
            $this->add_error(__('Invalid recipient address format', 'evm-payment-gateway'));
        }

        if (empty($this->contract_address) || !preg_match('/^0x[a-fA-F0-9]{40}$/', $this->contract_address)) {
            $valid = false;
            $this->add_error(__('Invalid contract address format', 'evm-payment-gateway'));
        }

        if (!is_numeric($this->token_decimals) || $this->token_decimals < 0 || $this->token_decimals > 18) {
            $valid = false;
            $this->add_error(__('Token decimals must be between 0 and 18', 'evm-payment-gateway'));
        }

        if (empty($this->blockchain_network) || !is_numeric($this->blockchain_network)) {
            $valid = false;
            $this->add_error(__('Invalid network ID', 'evm-payment-gateway'));
        }

        try {
            $abi = json_decode($this->abi_array);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $valid = false;
                $this->add_error(__('Invalid ABI format', 'evm-payment-gateway'));
            }
        } catch (Exception $e) {
            $valid = false;
            $this->add_error(__('Invalid ABI format', 'evm-payment-gateway'));
        }

        return $valid;
    }
}
