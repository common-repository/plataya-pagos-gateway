<?php
/**
 * Plugin Name: Plata Ya Crédito payments for WooCommerce
 * Plugin URI: http://www.platayacredito.com.ar/
 * Description: Integra el medio de pago Plata Ya Crédito en los medios de pago disponibles
 * Version: 1.1.9
 * Requires at least: 4.9.10
 * Tested up to: 5.9.2
 * Requires PHP: 5.6
 * Stable tag: 1.1.9
 * Author: SISTEMAS UNIFICADOS DE CREDITO DIRIGIDO SUCRED S.A.
 * Author URI: https://www.plataya.com.ar/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woocommerce-plataya-credito
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
	exit ;
}

// Make sure WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	return;
}

/**
 * Se agrega el gateway como pasarela de pagos
 * 
 * @since 1.0.0
 * @param array $gateways all available WC gateways
 * @return array $gateways all WC gateways + offline gateway
 */
function wc_woocommerce_plataya_credito_add_to_gateways( $gateways ) {
	$gateways[] = 'WC_Gateway_PlataYa_Credito';
	return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'wc_woocommerce_plataya_credito_add_to_gateways' );
/**
 * Adds plugin page links
 * 
 * @since 1.0.0
 * @param array $links all plugin links
 * @return array $links all plugin links + our custom links (i.e., "Settings")
 */
function wc_woocommerce_plataya_credito_gateway_plugin_links( $links ) {
	$plugin_links = array(
		'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=woocommerce_plataya_credito' ) . '">' . __( 'Configure', 'woocommerce-plataya-credito' ) . '</a>',
		'<a href="https://servicios.plataya.com.ar/files/Plugin_Plata_Ya_Pagos_Woocommerce_Manual_Usuario.v5.pdf" target="_blank">' . __( 'Más Información', 'woocommerce-plataya-credito' ) . '</a>'
	);
	return array_merge( $plugin_links, $links );
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_woocommerce_plataya_credito_gateway_plugin_links' );
add_action( 'plugins_loaded', 'wc_woocommerce_plataya_credito_gateway_init', 11 );

function wc_woocommerce_plataya_credito_gateway_init() {
	class WC_Gateway_PlataYa_Credito extends WC_Payment_Gateway {

		private $url_creacion_orden;
		private $url_guardado_orden;
		private $url_checkout_woocommerce_plataya_credito;
		private $url_obtener_orden;

		private $version_plugin;
				
		/**
		 * Constructor de la pasarela de pagos Plata Ya Credito
		 */
		public function __construct() {

			$this->id                 = 'woocommerce_plataya_credito';
			$this->icon               = apply_filters('woocommerce_woocommerce_plataya_credito_icon', plugins_url('plataya-pagos-gateway/images/woocommerce-plataya-credito.png', plugin_dir_path(__FILE__)));
			$this->has_fields         = false;
			$this->method_title       = __('Plata Ya Crédito', 'woocommerce-plataya-credito');
			$this->method_description = __('Permite pagos utilizando la plataforma <b><i>Plata Ya Crédito</b></i>', 'woocommerce-plataya-credito');
			$this->version_plugin     = '1.1.9';
			
			// inicializo las funciones del plugin
			$this->init_form_fields();
			$this->init_settings();
			
			// Definino variables del usuaro
			$this->title        = __('Pago en cuotas sin Tarjeta', 'woocommerce-plataya-credito');
			$this->description  = __('Plata Ya Crédito es un medio de pago innovador para financiar tus compras en internet, sin necesidad de una tarjeta de crédito o débito. Tu crédito online deberás abonarlo mensualmente en los lugares habilitados.', 'woocommerce-plataya-credito');

			$this->instructions = __('Muchas Gracias por haber elegido <B>PLATA YA CREDITO</B> como medio de financiación de tu compra.<br>' .
								  'Plata Ya Crédito  es un nuevo medio de pago para que financies todas tus compras online hasta en 12 cuotas fijas, sin necesidad de tener una tarjeta de crédito o débito', 'woocommerce-plataya-credito');

			// Evalúo si se encuentra en testing o producción
			if (strtolower($this->get_option('checkout_credential_production')) == 'si' || strtolower($this->get_option('checkout_credential_production')) == 'yes') 
			{
				$this->url_creacion_orden = esc_url('https://servicios.plataya.com.ar/api/Operation/empty');
				$this->url_guardado_orden = esc_url('https://servicios.plataya.com.ar/api/Operation/Save');
				$this->url_obtener_orden = esc_url('https://servicios.plataya.com.ar/api/Operation/');
				$this->url_checkout_woocommerce_plataya_credito = esc_url('https://pagos.plataya.com.ar/checkoutapi/');
			} 
			else 
			{
				$this->url_creacion_orden = esc_url('https://test-servicios.plataya.com.ar/api/Operation/empty');
				$this->url_guardado_orden = esc_url('https://test-servicios.plataya.com.ar/api/Operation/Save');
				$this->url_obtener_orden = esc_url('https://test-servicios.plataya.com.ar/api/Operation/');
				$this->url_checkout_woocommerce_plataya_credito = esc_url('https://pagos.plataya.com.ar/checkoutapi-test/');
			}
			
			// Configuro los ACTIONS
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );

			// Configuro Plata Ya Credito IPN
			add_action( 'woocommerce_api_wc_gateway_plataya_credito', array( $this, 'webhook' ) );
		
			/*
			echo('<pre align=right>');
			echo('$_POST<br>');
			echo('$_POST<br>');
			echo('$_POST<br>');
			echo('$_POST<br>');
			echo('$_POST<br>');
			echo('$_POST<br>');
			var_dump( $_POST );
			echo('$this->get_post_data()<br>');
			var_dump( $this->get_post_data() );
			echo('$this->settings');
			var_dump( $this->settings );
			echo('</pre>');
			*/
		}

		/**
		 * 	Inicialización del formulario de configuración de la pasarela de pagos
		 * 
		 */
		function init_form_fields() {
			global $woocommerce;

			$this->form_fields = apply_filters( 'wc_woocommerce-plataya-credito_gateway_form_fields', array(
				'checkout_credential_production' => array(
					'title' => __('Producción', 'woocommerce-plataya-credito'),
					'type' => 'select',
					'description' => __('Elija SI cuando esté listo para realizar ventas. Seleccione NO para activar el modo de pruebas', 'woocommerce-plataya-credito'),
					'default' => 'no',
					'options' => array(
						'no' => __('NO', 'woocommerce-plataya-credito'),
						'si' => __('SI', 'woocommerce-plataya-credito')
					)
					),

				'commerce_id' => array(
					'title'       => __('Código de Comercio', 'woocommerce-plataya-credito'),
					'type'        => 'text',
					'description' => __('Es el código de comercio asociado a Plata Ya Crédito<BR>Se obtiene en la adhesión del comercio al servicio. Haga click <a href="http://www.platayacredito.com.ar/comercios/" target="_blank">aquí</a> para obtener más información del proceso de ahesión<br>Utilice ZZZZ para pruebas', 'woocommerce-plataya-credito'),
					'default'     => 'ZZZZ',
					),
				
				'commerce_contact' => array(
					'title'       => __('Email de contacto', 'woocommerce-plataya-credito'),
					'type'        => 'email',
					'description' => __('Email de contacto del comercio', 'woocommerce-plataya-credito'),
					'default'     => '',
				),
				'commerce_url_ok' => array(
					'title'       => __('URL de éxito', 'woocommerce-plataya-credito'),
					'type'        => 'url',
					'description' => __('URL de éxito de la operación', 'woocommerce-plataya-credito'),
					'default'     => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . "/wc-api/wc_gateway_plataya_credito",
				),

				'commerce_url_error' => array(
					'title'       => __('URL de pago rechazado', 'woocommerce-plataya-credito'),
					'type'        => 'url',
					'description' => __('URL de pago rechazado', 'woocommerce-plataya-credito'),
					'default'     => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . "/wc-api/wc_gateway_plataya_credito",
				),

				'commerce_url_pending' => array(
					'title'       => __('URL de pago pendiente', 'woocommerce-plataya-credito'),
					'type'        => 'url',
					'description' => __('URL de pago pendiente', 'woocommerce-plataya-credito'),
					'default'     => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . "/wc-api/wc_gateway_plataya_credito",
				),
				'identity_validation_questions_audience' => array(
					'title' => __('Preguntas de Seguridad', 'woocommerce-plataya-credito'),
					'type' => 'select',
					'description' => __('Elija a quienes se le realizarán preguntas de validación de identidad', 'woocommerce-plataya-credito'),
					'default' => '1',
					'options' => array(
						'1' => __('TODO CLIENTE', 'woocommerce-plataya-credito'),
						'2' => __('NINGUN CLIENTE', 'woocommerce-plataya-credito'),
						'3' => __('SOLO NUEVOS CLIENTES', 'woocommerce-plataya-credito')
					)
					),
			) );

		}

		/**
		 * Devuelve la URL de templates
		 * 
		 */
		public static function get_templates_path() {
			return plugin_dir_path( __FILE__ ) . 'templates/';
		}
	
		/**
		 * Muestra la descripción de la pasarela en el checkout
		 */
		public function payment_fields() {

			echo wpautop(wp_kses_post($this->description));
		}

		public function process_admin_options() {
		
			//$errors_found = parent::process_admin_options();

			$errors_found = false;
			
			$this->init_settings();
			$post_data = $this->get_post_data();
			
			/*
			echo('<pre align=right>');
			echo('$_POST<br>');
			echo('$_POST<br>');
			echo('$_POST<br>');
			echo('$_POST<br>');
			echo('$_POST<br>');
			echo('$_POST<br>');
			var_dump( $_POST );
			echo('$this->get_post_data()<br>');
			var_dump( $this->get_post_data() );
			echo('$this->settings');
			var_dump( $this->settings );
			echo('</pre>');
			*/
			
			if (isset($post_data['woocommerce_woocommerce_plataya_credito_commerce_id']))
			{
				if (strlen($post_data['woocommerce_woocommerce_plataya_credito_commerce_id']) < 4)
				{
					$this->add_error( __('El código de comercio ' . esc_attr($post_data['woocommerce_woocommerce_plataya_credito_commerce_id']) . ' no parece válido. Se ha restaurado el valor original. Por favor verifiquelo.', 'woocommerce-plataya-credito'));
					$this->update_option( 'commerce_id', $this->get_option( 'commerce_id' ) );
					$errors_found = true;
				}
				else
					$this->update_option( 'commerce_id', $post_data['woocommerce_woocommerce_plataya_credito_commerce_id'] );
			}

			if (isset($post_data['woocommerce_woocommerce_plataya_credito_checkout_credential_production']))
			{
				$production_setting_valid = (strtolower( $post_data['woocommerce_woocommerce_plataya_credito_checkout_credential_production'] ) == 'si' || strtolower( $post_data['woocommerce_woocommerce_plataya_credito_checkout_credential_production'] ) == 'no');

				if (!$production_setting_valid)
				{
					$this->add_error( __('El valor del selector de producción no parece válido. Se ha restaurado el valor original. Por favor verifiquelo.', 'woocommerce-plataya-credito'));
					$this->update_option( 'checkout_credential_production', $this->get_option( 'checkout_credential_production' ) );
					$errors_found = true;
				}
				else
				{
					if (isset($post_data['woocommerce_woocommerce_plataya_credito_commerce_id']) && !$errors_found)
					{
						if ( strtolower( $post_data['woocommerce_woocommerce_plataya_credito_checkout_credential_production'] ) == 'si' && strtoupper($post_data['woocommerce_woocommerce_plataya_credito_commerce_id']) == 'ZZZZ' )
						{
							$this->add_error( __('El código de comercio ZZZZ sólo puede utilizarse para pruebas. No es válido para ventas reales. Se ha restaurado el valor original. Por favor verifiquelo.', 'woocommerce-plataya-credito'));
							$this->update_option( 'checkout_credential_production', $this->get_option( 'checkout_credential_production' ) );
							$errors_found = true;
							$production_setting_valid = false;
						}
					}
				}
				
				if ($production_setting_valid)
				{
					$this->update_option( 'checkout_credential_production', $post_data['woocommerce_woocommerce_plataya_credito_checkout_credential_production'] );
				}
				else
				{
					$errors_found = $errors_found || $production_setting_valid;
				}
			}

			if (isset($post_data['woocommerce_woocommerce_plataya_credito_commerce_url_ok']))
			{
				if (wp_validate_redirect( $post_data['woocommerce_woocommerce_plataya_credito_commerce_url_ok'] ) !=   $post_data['woocommerce_woocommerce_plataya_credito_commerce_url_ok'] )
				{
					$this->add_error( __('La URL de pago exitoso no parece válida. Se ha restaurado el valor original. Por favor revisela</i></b>.', 'woocommerce-plataya-credito'));
					$this->update_option( 'commerce_url_ok', $this->get_option( 'woocommerce_woocommerce_plataya_credito_commerce_url_ok' ) );
					$errors_found = true;
				}
				else
				{
					$this->update_option( 'commerce_url_ok', $post_data['woocommerce_woocommerce_plataya_credito_commerce_url_ok'] );
				}
			}
			
			if (isset($post_data['woocommerce_woocommerce_plataya_credito_commerce_url_pending']))
			{
				if (wp_validate_redirect( $post_data['woocommerce_woocommerce_plataya_credito_commerce_url_pending'] ) !=   $post_data['woocommerce_woocommerce_plataya_credito_commerce_url_pending'] )
				{
					$this->add_error( __('La URL de pago pendiente no parece válida. Se ha restaurado el valor original. Por favor revisela</i></b>.', 'woocommerce-plataya-credito'));
					$this->update_option( 'commerce_url_pending', $this->get_option( 'woocommerce_woocommerce_plataya_credito_commerce_url_pending' ) );
					$errors_found = true;
				}
				else
				{
					$this->update_option( 'commerce_url_pending', $post_data['woocommerce_woocommerce_plataya_credito_commerce_url_pending'] );
				}
			}
			
			if (isset($post_data['woocommerce_woocommerce_plataya_credito_commerce_url_error']))
			{
				if (wp_validate_redirect( $post_data['woocommerce_woocommerce_plataya_credito_commerce_url_error'] ) !=   $post_data['woocommerce_woocommerce_plataya_credito_commerce_url_error'] )
				{
					$this->add_error( __('La URL de pago fallido no parece válida. Se ha restaurado el valor original. Por favor revisela</i></b>.', 'woocommerce-plataya-credito'));
					$this->update_option( 'commerce_url_error', $this->get_option( 'woocommerce_woocommerce_plataya_credito_commerce_url_error' ) );
					$errors_found = true;
				}
				else
				{
					$this->update_option( 'commerce_url_error', $post_data['woocommerce_woocommerce_plataya_credito_commerce_url_error'] );
				}
			}
			if (isset($post_data['woocommerce_woocommerce_plataya_credito_commerce_contact']))
			{
				$this->update_option( 'commerce_contact', $post_data['woocommerce_woocommerce_plataya_credito_commerce_contact'] );
			}
			
			if ($errors_found)
				$this->display_errors();
			
			return !$errors_found;
		}
	
		/**
		 * Envía al usuario a la página de agradecimiento
		 */
		public function thankyou_page() {
			if ( $this->instructions ) {
				echo wpautop( wptexturize( $this->instructions ) );
			}
		}
	
		/**
		 * Procesa la orden y la envía a Plata Ya Credito
		 *
		 * @param int $order_id
		 * @return array
		 */
		public function process_payment( $order_id ) {

			if (null == $this->get_option('commerce_id') || empty($this->get_option('commerce_id')))
			{
				wc_add_notice( __('El medio de pago no se encuentra configurado correctamente Por favor revise la configuración de <b><i>Plata Ya Crédito</i></b>.', 'woocommerce-plataya-credito'), 'error' );

				return;
			}
			if (strlen($this->get_option('commerce_id')) < 4)
			{
				wc_add_notice( __('El código de comercio no parece válido. Por favor revise la configuración de <b><i>Plata Ya Crédito</i></b>.', 'woocommerce-plataya-credito'));
				return ;
			}
			
			// Obtengo la orden
			$order = wc_get_order( $order_id );
			
			// La marco como pendiente de pago con Plata Ya Credito
			$order->update_status( 'on-hold', 'Esperando Pago con Plata Ya Credito');
			
			$articulos = array();
			$id_item = 1;
			$my_item;
			
			foreach($order->get_items() as $item) {
				
				//$platform_data = $item->get_data();
				$meta_data = $item->get_meta_data();
				$vendor_id = $item->get_meta('_vendor_id');
				$user_desc = '';

				if (isset($vendor_id))
				{
					$user = get_user_by('id', $vendor_id);
					
					if (isset($user))
					{
						$vendor_code = $user->user_nicename;
						$user_desc = $user->user_login;
					}
					else
						$vendor_code = $vendor_id; 
				}
				else
				{ 
					$user = 'no user';
				}

				if (get_user_meta($vendor_id, '_vendor_page_title', true))
					$user_desc = get_user_meta($vendor_id, '_vendor_page_title', true);

				if ($user_desc != '')
					$item_desc = $item->get_name() . '<br />Vendidor por: ' . $user_desc;
				else
					$item_desc = $item->get_name();
					
				array_push($articulos, 
									array('id_item' => $id_item++,
										  'family' => '1',
										  'type' => '1',
										  'seller_code' => $item->get_product_id(),
										  'quantity' => $item->get_quantity(),
										  'unit_price' => $item->get_total() / $item->get_quantity(),
										  'vendor_id' => $vendor_id,
										  'vendor_code' => $vendor_code,										  
										  'platform_data' => json_encode($item->get_data()) . json_encode(get_user_meta($vendor_id)), 
										  'amount' => array(
															'description' => $item_desc, 
															'amount' => $item->get_total()
															)
										  )
							 );
							//get_user_meta
							//json_encode($user)
				$my_item = $item;
			}
			
			$customer = new WC_Customer($order->get_user_id());
			
			$shipping_info = $order->get_shipping_methods();
			
			if ($order->get_total_shipping() > 0)
			{

				foreach($order->get_shipping_methods() as $shipping_method) 
				{
					$vendor_id = $shipping_method->get_meta('vendor_id');
					$user_desc = '';
					
					if (isset($vendor_id))
					{
						$user = get_user_by('id', $vendor_id);
					
						if (isset($user))
						{
							$vendor_code = $user->user_nicename;
							$user_desc = $user->user_login;
						}
						else
							$vendor_code = $vendor_id; 
					}
					else
					{ 
						$user = 'no user';
					}

					if (get_user_meta($vendor_id, '_vendor_page_title', true))
						$user_desc = get_user_meta($vendor_id, '_vendor_page_title', true);

					array_push($articulos, 
										array('id_item' => $id_item++,
											  'family' => '0',
											  'type' => '0',
											  'seller_code' => 0,
											  'quantity' => 1,
											  'vendor_id' => $vendor_id,
											  'vendor_code' => $vendor_code,										  
  											  'platform_data' => json_encode($shipping_method->get_data()), 
											  'amount' => array(
																'description' => __($shipping_method->get_name() . ' ' . $user_desc, 'woocommerce-plataya-credito'), 
																'amount' => $shipping_method->get_total()
																)
										 	   )
	 							 );
				}
			    
			}
			
			//$site_info = get_site();
			
			/** Armo el Array con los datos de la orden */
			$args = json_decode($response['body'], true);

			$args['version'] = sanitize_text_field( $this->version_plugin );
			$args['mode'] = __('SEALED', 'woocommerce-plataya-credito'); 
			$args['platform'] = __('WP', 'woocommerce-plataya-credito'); 
			
			$args['shop']['id'] = sanitize_text_field( $this->get_option('commerce_id') );
			$args['shop']['contact_info'] = sanitize_email( $this->get_option('commerce_contact') );
			//$args['shop']['logo_url'] = $this->get_option('commerce_logo');
			//$args['shop']['platform_data'] = json_encode($site_info) . json_encode($site_info->get_details());

			$args['customer']['dni'] = '';
			$args['customer']['gender'] = '';
			$args['customer']['first_name'] = sanitize_text_field( $order->get_billing_first_name() );
			$args['customer']['last_name'] = sanitize_text_field( $order->get_billing_last_name() );
			$args['customer']['mail'] = sanitize_email( $order->get_billing_email() );
			$args['customer']['phone_number'] = sanitize_text_field( $order->get_billing_phone() );
			$args['customer']['platform_data'] = json_encode($customer->get_data());

			$args['total_amount']['amount'] = $order->get_total();
			//$args['global_discount']['amount'] = $this->get_option('commerce_gateway_discount');
			//$args['global_charge']['amount'] = $this->get_option('commerce_gateway_charge');

			$args['behaviour']['urls']['success'] = wp_validate_redirect( $this->get_option('commerce_url_ok') );
			$args['behaviour']['urls']['pending'] = wp_validate_redirect( $this->get_option('commerce_url_pending') );
			$args['behaviour']['urls']['failure'] = wp_validate_redirect( $this->get_option('commerce_url_error') );
			//$args['behaviour']['urls']['additional_info'] = json_encode($order->get_user());
			//$args['behaviour']['urls']['qa'] = json_encode($my_item->get_data());
			//get_meta_data()

			$args['behaviour']['urls']['feedback_mode'] = __('EMAIL', 'woocommerce-plataya-credito'); //$this->get_option('modo_feedback');
			$args['behaviour']['urls']['feedback_address'] = sanitize_email( $this->get_option('commerce_contact') );
			$args['behaviour']['identity_questions_mode'] = sanitize_text_field( $this->get_option('identity_validation_questions_audience') );
			
			$args['callback']['transaction_id'] = $order_id;
			$args['callback']['platform_data'] = json_encode($order->get_data());

			$args['items'] = $articulos;

			$response = wp_remote_post( $this->url_guardado_orden, 
										array('headers' => array('Content-Type' => 'application/json'), 
											  'body' => json_encode($args))
									 );
			
			if ( is_wp_error( $response ) ) { //decode and return
				wc_add_notice( __('Ha ocurrido un error en el llamado al checkout de <b><i>Plata Ya Crédito</i></b>.', 'woocommerce-plataya-credito'));
				return;
			}
			
			$response_arr = json_decode( wp_remote_retrieve_body( $response ), true );

			/** redirecciono a la página de checkout de Plata Ya Pagos */
			return array(
				'result' 	=> 'success',
				'redirect'	=> $this->url_checkout_woocommerce_plataya_credito . '?transaction_id=' . $response_arr['id']
			);
		}

		/**
		 * Hook que se activa cuando regresa de la operación de venta
		 */
		public function webhook() {
			$params = wp_kses_data( $_POST['params'] );
			$params = str_replace("\\", "", $params); 
			$response_original = json_decode( $params );

			$response = wp_remote_get( $this->url_obtener_orden . $response_original->transaccion_id , array());
			
			if ( is_wp_error( $response ) ) { //decode and return
				wc_add_notice( __('Error en la respuesta del checkout de <b><i>Plata Ya Crédito</i></b>.', 'woocommerce-plataya-credito'), 'error' );
				return;
			}			

			$response_arr = json_decode( wp_remote_retrieve_body( $response ) , true );
			$order_id = $response_arr['callback']['transaction_id'];
			$order = wc_get_order( $order_id ); // Obtengo la orden

			if ($response_original->status == 'failed') { // Si el ecommerce muestra que falló la operación no verifico nada más
				// Algo falló 
				$order->update_status('failed', __('Plata Ya Crédito: El Pago fue rechazado', 'woocommerce-plataya-credito')); // Seteo el estado de la operación
	
				update_option('webhook_debug', $response);

				wp_redirect($order->get_checkout_order_received_url()); // Redirecciono a página de error
			} else {
				// Evalúo el resultado de la operación
				if (strtolower($response_arr['result']['description']) == 'success' || strtolower($response_arr['result']['description']) == 'pending') { // salió todo OK
					
					$order->payment_complete(); // La marco como completa
					$order->reduce_order_stock(); // Reduzco stock
					WC()->cart->empty_cart(); // Vacío carrito
				
					update_option('webhook_debug', $response); 
					
					wp_redirect($order->get_checkout_order_received_url()); // Redirecciono a Thank you Page
				} else if (strtolower($response->status) == 'failure' || strtolower($response->status) == 'in process') { // La operación falló
					// Algo falló 
					$order->update_status('failed', __('Plata Ya Crédito: El Pago fue rechazado', 'woocommerce-plataya-credito')); // Seteo el estado de la operación
	
					update_option('webhook_debug', $response);
	
					wp_redirect($order->get_checkout_order_received_url()); // Redirecciono a página de error
				}
			}
		}
	}
}
