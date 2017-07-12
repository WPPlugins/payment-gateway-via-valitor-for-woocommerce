<?php
/*
	Plugin Name: WooCommerce Valitor Gateway
	Plugin URI: http://valitor.is
	Description: Extends WooCommerce with a <a href="http://www.valitor.is/" target="_blank">Valitor</a> gateway.
	Version: 1.8
	Author: Tactica
	Author URI: http://tactica.is

	License: GNU General Public License v3.0
	License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/
define( 'VALITOR_DIR', WP_PLUGIN_DIR . "/" . plugin_basename( dirname( __FILE__ ) ) . '/' );
define( 'VALITOR_URL', WP_PLUGIN_URL . "/" . plugin_basename( dirname( __FILE__ ) ) . '/' );
function valitor_wc_active() {
    if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
        return true;
    } else {
        return false;
    }
}

add_action( 'plugins_loaded', 'woocommerce_valitor_init', 0 );
function woocommerce_valitor_init() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        return;
    }
    //Add the gateway to woocommerce
    add_filter( 'woocommerce_payment_gateways', 'add_valitor_gateway' );
    function add_valitor_gateway( $methods ) {
        $methods[] = 'WC_Gateway_Valitor';

        return $methods;
    }

    class WC_Gateway_Valitor extends WC_Payment_Gateway {
        const VALITOR_ENDPOINT_SANDBOX = 'https://testgreidslusida.valitor.is/';
        const VALITOR_ENDPOINT_LIVE = 'https://greidslusida.valitor.is/';

        public function __construct() {
            $this->id                 = 'valitor';
            $this->icon               = VALITOR_URL . '/cards.png';
            $this->has_fields         = false;
            $this->method_title       = 'Valitor';
            $this->method_description = 'Valitor Payment Page enables merchants to sell products securely on the web with minimal integration effort';
            // Load the form fields
            $this->init_form_fields();
            $this->init_settings();
            // Get setting values
            $this->enabled          = $this->get_option( 'enabled' );
            $this->title            = $this->get_option( 'title' );
            $this->description      = $this->get_option( 'description' );
            $this->testmode         = $this->get_option( 'testmode' );
            $this->MerchantID       = $this->get_option( 'MerchantID' );
            $this->VerificationCode = $this->get_option( 'VerificationCode' );
            $this->Language         = $this->get_option( 'Language' );
            $this->successurlText   = $this->get_option( 'successurlText' );
            // Hooks
            add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
                $this,
                'process_admin_options'
            ) );
            add_action( 'woocommerce_api_wc_gateway_valitor', array( $this, 'check_valitor_response' ) );
            add_action( 'woocommerce_thankyou', array( $this, 'check_valitor_response' ) );
            if ( ! $this->is_valid_for_use() ) {
                $this->enabled = false;
            }
        }

        public function admin_options() {
            ?>
            <h3>Valitor</h3>
            <p>Pay with your credit card via Valitor.</p>
            <?php if ( $this->is_valid_for_use() ) : ?>
                <table class="form-table"><?php $this->generate_settings_html(); ?></table>
            <?php else : ?>
                <div class="inline error"><p><strong><?php _e( 'Gateway Disabled', 'woocommerce' ); ?></strong>: Current
                        Store currency is not valid for valitor gateway. Must be in ISK, USD or EUR</p></div>
                <?php
            endif;
        }

        //Check if this gateway is enabled and available in the user's country
        function is_valid_for_use() {
            if ( ! in_array( get_woocommerce_currency(), array( 'ISK', 'USD', 'EUR' ) ) ) {
                return false;
            }

            return true;
        }

        //Initialize Gateway Settings Form Fields
        function init_form_fields() {
            $this->form_fields = array(
                'enabled'          => array(
                    'title'       => 'Enable/Disable',
                    'label'       => 'Enable Valitor',
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no'
                ),
                'title'            => array(
                    'title'       => 'Title',
                    'type'        => 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default'     => 'Valitor'
                ),
                'description'      => array(
                    'title'       => 'Description',
                    'type'        => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout.',
                    'default'     => 'Pay with your credit card via Valitor.'
                ),
                'testmode'         => array(
                    'title'       => 'Valitor Test Mode',
                    'label'       => 'Enable Test Mode',
                    'type'        => 'checkbox',
                    'description' => 'Place the payment gateway in development mode.',
                    'default'     => 'no'
                ),
                'MerchantID'       => array(
                    'title'       => 'Merchant ID',
                    'type'        => 'text',
                    'description' => 'This is the ID supplied by Valitor.',
                    'default'     => '1'
                ),
                'VerificationCode' => array(
                    'title'       => 'Verification Code',
                    'type'        => 'text',
                    'description' => 'This is the Payment VerificationCode supplied by Valitor.',
                    'default'     => '12345'
                ),
                'Language'         => array(
                    'title'       => 'Language of Payment Page',
                    'type'        => 'select',
                    'description' => 'Select which Language to show on Payment Page.',
                    'default'     => 'EN',
                    'options'     => array( 'IS' => 'Icelandic', 'EN' => 'English', 'DE' => 'German', 'DA' => 'Danish' )
                ),
                'successurlText'   => array(
                    'title'       => 'Success Return Button Text',
                    'type'        => 'text',
                    'description' => 'Buyer will see button to return to previous page after a successful payment.',
                    'default'     => 'Back to Home'
                ),
            );
        }

        /**
         * @param WC_Order $order
         *
         * @return array
         */
        function get_valitor_args( $order ) {
            $successUrl      = esc_url_raw( $this->get_return_url( $order ) );
            $ipnUrl          = WC()->api_request_url( 'WC_Gateway_Valitor' );
            $authOnly        = 0;
            $ReferenceNumber = 'WC-' . ltrim( $order->get_order_number(), '#' );
            //Valitor Args
            $valitor_args = array(
                'MerchantID'                          => $this->MerchantID,
                'ReferenceNumber'                     => $ReferenceNumber,
                'Currency'                            => get_woocommerce_currency(),
                'Language'                            => $this->Language,
                'PaymentSuccessfulURL'                => $successUrl,
                'PaymentSuccessfulURLText'            => $this->successurlText,
                'PaymentSuccessfulAutomaticRedirect ' => 1,
                'PaymentSuccessfulServerSideURL'      => $ipnUrl,
                'PaymentCancelledURL'                 => esc_url_raw( $order->get_cancel_order_url() ),
                'DisplayBuyerInfo'                    => '0',
                //If set as 1 then cardholder is required to insert email,mobile number,address.
                'AuthorizationOnly'                   => $authOnly,
            );
            // Cart Contents
            $item_loop        = 1;
            $DigitalSignature = $this->VerificationCode . $authOnly;

            if ( sizeof( $order->get_items() ) > 0 ) {
                foreach ( $order->get_items() as $item ) {
                    if ( $item['qty'] ) {
                        $item_name = $item['name'];
                        $item_meta = new WC_Order_Item_Meta( $item['item_meta'] );
                        if ( $meta = $item_meta->display( true, true ) ) {
                            $item_name .= ' ( ' . $meta . ' )';
                        }
                        $calc_discount                                            = $order->get_item_subtotal( $item, true ) - $order->get_item_total( $item, true );
                        $valitor_args[ 'Product_' . $item_loop . '_Description' ] = html_entity_decode( $item_name, ENT_NOQUOTES, 'UTF-8' );
                        $valitor_args[ 'Product_' . $item_loop . '_Quantity' ]    = $item['qty'];
                        $valitor_args[ 'Product_' . $item_loop . '_Price' ]       = number_format( $order->get_item_subtotal( $item, true ),wc_get_price_decimals(), '.', ''  );
                        $valitor_args[ 'Product_' . $item_loop . '_Discount' ]    = $calc_discount;
                        $DigitalSignature .= $item['qty'];
                        $DigitalSignature .= number_format( $order->get_item_subtotal( $item, true ),wc_get_price_decimals(), '.', ''  );
                        $DigitalSignature .= $calc_discount;
                        $item_loop ++;
                    }
                }
                if ( $order->get_total_shipping() > 0 ) {
                    $valitor_args[ 'Product_' . $item_loop . '_Description' ] = 'Shipping (' . $order->get_shipping_method() . ')';
                    $valitor_args[ 'Product_' . $item_loop . '_Quantity' ]    = 1;
                    $valitor_args[ 'Product_' . $item_loop . '_Price' ]       = number_format( ($order->get_shipping_total()+$order->get_shipping_tax()), wc_get_price_decimals(), '.', ''  );
                    $valitor_args[ 'Product_' . $item_loop . '_Discount' ]    = 0;
                    $DigitalSignature .= 1;
                    $DigitalSignature .= number_format( ($order->get_shipping_total()+$order->get_shipping_tax()), wc_get_price_decimals(), '.', ''  );
                    $DigitalSignature .= 0;
                    $item_loop ++;
                }


            }
            /*if ( sizeof( $order->get_items() ) > 0 ) {
                foreach ( $order->get_items() as $item ) {
                    if ( $item['qty'] ) {
                        $item_name = $item['name'];
                        $item_meta = new WC_Order_Item_Meta( $item['item_meta'] );
                        if ( $meta = $item_meta->display( true, true ) ) {
                            $item_name .= ' ( ' . $meta . ' )';
                        }
                        $calc_discount                                            = $order->get_line_subtotal( $item, true ) - $order->get_line_total( $item, true );
                        $valitor_args[ 'Product_' . $item_loop . '_Description' ] = html_entity_decode( $item_name . ' : ' . $item['qty'] . ' stk   (verð m/vsk)', ENT_NOQUOTES, 'UTF-8' );
                        $valitor_args[ 'Product_' . $item_loop . '_Quantity' ]    = 1;
                        $valitor_args[ 'Product_' . $item_loop . '_Price' ]       = $order->get_line_subtotal( $item, true );
                        $valitor_args[ 'Product_' . $item_loop . '_Discount' ]    = $calc_discount;
                        $DigitalSignature .= 1;
                        $DigitalSignature .= $order->get_line_subtotal( $item, true );
                        $DigitalSignature .= $calc_discount;
                        $item_loop ++;
                    }
                }
                if ( $order->get_total_shipping() > 0 ) {
                    $valitor_args[ 'Product_' . $item_loop . '_Description' ] = 'Shipping (' . $order->get_shipping_method() . ')';
                    $valitor_args[ 'Product_' . $item_loop . '_Quantity' ]    = 1;
                    $valitor_args[ 'Product_' . $item_loop . '_Price' ]       = number_format( $order->get_total_shipping() + $order->get_shipping_tax(), wc_get_price_decimals(), '.', '' );
                    $valitor_args[ 'Product_' . $item_loop . '_Discount' ]    = 0;
                    $DigitalSignature .= 1;
                    $DigitalSignature .= number_format( $order->get_total_shipping() + $order->get_shipping_tax(), wc_get_price_decimals(), '.', '' );
                    $DigitalSignature .= 0;
                    $item_loop ++;
                }


            }
            */
            $DigitalSignature .= $this->MerchantID;
            $DigitalSignature .= $ReferenceNumber;
            $DigitalSignature .= $successUrl;
            $DigitalSignature .= $ipnUrl;
            $DigitalSignature .= get_woocommerce_currency();
            $valitor_args['DigitalSignature'] = md5( $DigitalSignature );

            return $valitor_args;
        }

        //Generate the valitor button link
        function generate_valitor_form( $order_id ) {
            global $woocommerce;
            if ( function_exists( 'wc_get_order' ) ) {
                $order = wc_get_order( $order_id );
            } else {
                $order = new WC_Order( $order_id );
            }
            if ( 'yes' == $this->testmode ) {
                $valitor_adr = self::VALITOR_ENDPOINT_SANDBOX;
            } else {
                $valitor_adr = self::VALITOR_ENDPOINT_LIVE;
            }
            $valitor_args       = $this->get_valitor_args( $order );
            $valitor_args_array = array();
            foreach ( $valitor_args as $key => $value ) {
                $valitor_args_array[] = '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" />';
            }
            wc_enqueue_js( '
                $.blockUI({
                    message: "Thank you for your order. We are now redirecting you to Valitor to make payment.",
                    baseZ: 99999,
                    overlayCSS: { background: "#fff", opacity: 0.6 },
                    css: {
                        padding:        "20px",
                        zindex:         "9999999",
                        textAlign:      "center",
                        color:          "#555",
                        border:         "3px solid #aaa",
                        backgroundColor:"#fff",
                        cursor:         "wait",
                        lineHeight:     "24px",
                    }
                });

                jQuery("#wc_submit_valitor_payment_form").click();
            ' );
            $html_form = '<form action="' . esc_url_raw( $valitor_adr ) . '" method="post" id="valitor_payment_form">'
                         . implode( '', $valitor_args_array )
                         . '<input type="submit" class="button" id="wc_submit_valitor_payment_form" value="' . __( 'Pay via Valitor', 'tech' ) . '" /> <a class="button cancel" href="' . $order->get_cancel_order_url() . '">' . __( 'Cancel order &amp; restore cart', 'tech' ) . '</a>'
                         . '</form>';

            return $html_form;
        }

        function process_payment( $order_id ) {
            if ( function_exists( 'wc_get_order' ) ) {
                $order = wc_get_order( $order_id );
            } else {
                $order = new WC_Order( $order_id );
            }

            return array(
                'result'   => 'success',
                'redirect' => $order->get_checkout_payment_url( true )
            );
        }

        function check_valitor_response() {
            global $woocommerce;
            $posted = ! empty( $_REQUEST ) ? $_REQUEST : false;
            if ( isset( $posted['ReferenceNumber'] ) ) {
                $mySignatureResponse      = md5( $this->VerificationCode . $posted['ReferenceNumber'] );
                $DigitalSignatureResponse = $posted['DigitalSignatureResponse'];
                $cardType                 = $posted['CardType'];
                $Date                     = $posted['Date'];
                $CardNumberMasked         = $posted['CardNumberMasked'];
                $AuthorizationNumber      = $posted['AuthorizationNumber'];
                $TransactionNumber        = $posted['TransactionNumber'];
                $orderNote                = "Card Type : " . $cardType . "<br/>";
                $orderNote .= "Card Number Masked : " . $CardNumberMasked . "<br/>";
                $orderNote .= "Date : " . $Date . "<br/>";
                $orderNote .= "Authorization Number : " . $AuthorizationNumber . "<br/>";
                $orderNote .= "Transaction Number : " . $TransactionNumber . "<br/>";
                if ( $mySignatureResponse == $DigitalSignatureResponse ) {
                    if ( ! empty( $posted['ReferenceNumber'] ) ) {
                        $order_id = (int) str_replace( 'WC-', '', $posted['ReferenceNumber'] );
                        if ( function_exists( 'wc_get_order' ) ) {
                            $order = wc_get_order( $order_id );
                        } else {
                            $order = new WC_Order( $order_id );
                        }
                        $order->add_order_note( $orderNote );
                        $order->payment_complete();
                        $woocommerce->cart->empty_cart();
                    }
                }
            }
        }

        function receipt_page( $order ) {
            echo '<p>Thank you - your order is now pending payment. We are now redirecting you to Valitor to make payment.</p>';
            echo '<div class="valitor-form">' . $this->generate_valitor_form( $order ) . '</div>';
        }
    }
}
