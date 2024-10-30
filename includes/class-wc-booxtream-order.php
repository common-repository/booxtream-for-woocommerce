<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'WC_BooXtream_Order' ) ) :

	/**
	 * Class WC_BooXtream_Order
	 */
	class WC_BooXtream_Order {

		protected $processed = false;

		/**
		 * @param WC_BooXtream_Integration $settings
		 */
		public function __construct( WC_BooXtream_Integration $settings ) {
			$this->settings = $settings;

			// runs when an order is committed
			add_action( 'woocommerce_order_add_product', array( $this, 'add_order_item_meta' ), 1, 5 );

			// runs when order status changes
			if ( $this->settings->onstatus === 'wc-completed' ) {
				add_action( 'woocommerce_order_status_completed', array( $this, 'process_items' ) );
			} else {
				// default to "Processing"
				add_action( 'woocommerce_order_status_processing', array( $this, 'process_items' ) );
			}

			add_action( 'woocommerce_order_item_meta_start', array( $this, 'handle_item_meta_display' ), 0, 4 );
		}

		public function handle_item_meta_display( $item_id, $item, $order, $plain_text = false ) {
			global $wp_rewrite;

			$links = array();
			$epub  = wc_get_order_item_meta( $item_id, '_bx_epub_full_link' );
			$mobi  = wc_get_order_item_meta( $item_id, '_bx_mobi_full_link' );

			if ( strlen( $epub ) > 0 ) {
				$links['epub'] = $epub;
			}
			if ( strlen( $mobi ) > 0 ) {
				$links['mobi'] = $mobi;
			}

			if ( count( $links ) > 0 ) {
				echo '<p>';
				foreach ( $links as $type => $link ) {

					$downloadlink = $wp_rewrite->root . $link;
					$linktext     = 'Download ' . $type;

					echo '<a href="' . $downloadlink . '">' . $linktext . '</a><br />';
				}
				echo '</p>';
			}
		}

		/**
		 * @param $order_id
		 * @param $item_id
		 * @param $product
		 * @param $qty
		 * @param $args
		 *
		 * @todo $qty and $args?
		 */
		public function add_order_item_meta( $order_id, $item_id, $product, $qty, $args ) {

			// get BooXtream settings
			$request_data = $this->get_product_request_data( $product->id, $order_id );

			// add BooXtream settings to item
			foreach ( $request_data as $key => $value ) {
				wc_add_order_item_meta( $item_id, $key, $value );
			}

		}

		/**
		 * @param $order
		 * @param $item_id
		 *
		 * @return mixed
		 */
		public function get_order_item_meta( $order, $item_id ) {
			$data = $order->get_item_meta( $item_id );

			return $data;
		}

		/**
		 * @param $order_id
		 */
		public function handle_status_change( $order_id ) {
			$statuses = wc_get_order_statuses();
			// if we don't get a wc-prefixed status.
			$status = 'wc-' === substr( $new_status, 0, 3 ) ? substr( $new_status, 3 ) : $new_status;
			if ( isset( $statuses[ 'wc-' . $status ] ) && 'wc-' . $status === $this->settings->onstatus ) {
				$this->process_items( $order_id );
			}
		}

		/**
		 * @param $order_id
		 */
		public function process_items( $order_id ) {
			if ( $this->processed ) {
				return;
			}

			$order = new WC_Order( $order_id );
			$items = $order->get_items( array( 'line_item' ) );

			// check general BooXtream conditions
			$accountkey = $this->settings->get_accountkey();
			if ( ! is_null( $accountkey ) ) {
				foreach ( $items as $item_id => $item ) {
					$downloadlinks = wc_get_order_item_meta( $item_id, '_bx_downloadlinks', true );
					/* The double check is for backward compatibility */
					if ( ! is_array( $downloadlinks ) && (
							'yes' === get_post_meta( $item['product_id'], '_booxtreamable', true ) ||
							'yes' === get_post_meta( $item['product_id'], '_bx_booxtreamable', true )
						)
					) {
						$this->request_downloadlinks( $item['product_id'], $order_id,
							$item_id ); // use this for actual data
					}
				}
			}
			$this->processed = true;
		}

		/**
		 * @param $product_id
		 * @param $order_id
		 *
		 * @return array
		 */
		private function get_product_request_data( $product_id, $order_id ) {
			$data = array();


			$data['_bx_filename']      = get_post_meta( $product_id, '_bx_filename', true );
			$data['_bx_language']      = get_post_meta( $product_id, '_bx_language', true );
			$data['_bx_outputepub']    = get_post_meta( $product_id, '_bx_outputepub', true );
			$data['_bx_outputmobi']    = get_post_meta( $product_id, '_bx_outputmobi', true );
			$data['_bx_downloadlimit'] = get_post_meta( $product_id, '_bx_downloadlimit', true );
			$data['_bx_expirydays']    = get_post_meta( $product_id, '_bx_expirydays', true );

			// check if downloadlimit, expirydays, language are set; if not, take global settings
			if ( $data['_bx_downloadlimit'] == 0 ) {
				$data['_bx_downloadlimit'] = ( int ) $this->settings->downloadlimit;
			}

			if ( $data['_bx_expirydays'] == 0 ) {
				$data['_bx_expirydays'] = ( int ) $this->settings->expirydays;
			}
			if ( $data['_bx_language'] == '' ) {
				$data['_bx_language'] = $this->settings->language;
			}

			$data['_bx_referenceid']   = get_post_meta( $product_id, '_bx_referenceid', true );
			$data['_bx_exlibrisfile']  = get_post_meta( $product_id, '_bx_exlibrisfile', true );
			$data['_bx_exlibrisfont']  = get_post_meta( $product_id, '_bx_exlibrisfont', true );
			$data['_bx_chapterfooter'] = get_post_meta( $product_id, '_bx_chapterfooter', true );
			$data['_bx_disclaimer']    = get_post_meta( $product_id, '_bx_disclaimer', true );
			$data['_bx_showdate']      = get_post_meta( $product_id, '_bx_showdate', true );

			// add customer data
			$order                            = new WC_Order( $order_id );
			$data['_bx_customername']         = $order->billing_first_name . ' ' . $order->billing_last_name;
			$data['_bx_customeremailaddress'] = $order->billing_email;

			return $data;
		}

		private function create_internal_links( $item_id, $epub, $mobi ) {
			global $wp_rewrite;

			if ( get_option( 'permalink_structure' ) ) {
				$links = array();
				if ( $epub ) {
					$download_id = $this->createShortCode();
					$link        = 'bx/' . $item_id . $download_id;
					wc_update_order_item_meta( $item_id, '_bx_epub_link', $download_id );
					$links['epub'] = site_url( $link );
				}
				if ( $mobi ) {
					$download_id = $this->createShortCode();
					$link        = 'bx/' . $item_id . $download_id;
					wc_update_order_item_meta( $item_id, '_bx_mobi_link', $download_id );
					$links['mobi'] = site_url( $link );
				}
			} else {
				// no permalink_struct yet
				$links = array();
				if ( $epub ) {
					$download_id = $this->createShortCode();
					$link        = 'index.php?bx-download=1&bx-item-id=' . $item_id . '&bx-download-id=' . $download_id;
					wc_update_order_item_meta( $item_id, '_bx_epub_link', $download_id );
					$links['epub'] = site_url( $link );
				}
				if ( $mobi ) {
					$download_id = $this->createShortCode();
					$link        = 'index.php?bx-download=1&bx-item-id=' . $item_id . '&bx-download-id=' . $download_id;
					wc_update_order_item_meta( $item_id, '_bx_mobi_link', $download_id );
					$links['mobi'] = site_url( $link );
				}
			}

			foreach ( $links as $type => $link ) {
				wc_update_order_item_meta( $item_id, '_bx_' . $type . '_full_link', $link );
			}

		}


		/**
		 * uses 'b' as a marker
		 *
		 * @param int $length
		 *
		 * @return string
		 */
		private function createShortCode( $length = 6 ) {
			$chars = 'acdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

			return 'b' . substr( str_shuffle( $chars ), 0, $length - 1 );
		}


		/**
		 * @param $product_id
		 * @param $order_id
		 *
		 * @return void
		 */
		private function request_downloadlinks( $product_id, $order_id, $item_id ) {
			global $wp_rewrite;

			// assemble requestdata
			$requestdata = $this->get_product_request_data( $product_id, $order_id );

			// create the links that refer to this website
			$epub = 'yes' === $requestdata['_bx_outputepub'] ? true : false;
			$mobi = 'yes' === $requestdata['_bx_outputmobi'] ? true : false;
			$this->create_internal_links( $item_id, $epub, $mobi );

			// get what we need for the request
			$url = WC_BooXtream::storedfilesurl . $requestdata['_bx_filename'] . '.async';

			$accountkey = $this->settings->accountkey;
			$loginname  = $this->settings->accounts[ $accountkey ] ['loginname'];
			$args       = array(
				'method'      => 'POST',
				'redirection' => 3,
				'user-agent'  => 'booxtreamrequest',
				'httpversion' => '1.1',
				'headers'     => array(
					'Authorization' => 'Basic ' . base64_encode( $loginname . ':' . $accountkey )
				)
			);
			$parameters = array(
				'referenceid'          => $this->settings->referenceprefix . $order_id,
				'languagecode'         => $requestdata['_bx_language'],
				'expirydays'           => $requestdata['_bx_expirydays'],
				'downloadlimit'        => $requestdata['_bx_downloadlimit'],
				'customeremailaddress' => $requestdata['_bx_customeremailaddress'],
				'customername'         => $requestdata['_bx_customername'],
				'disclaimer'           => 'yes' === $requestdata['_bx_disclaimer'] ? 1 : 0,
				'exlibris'             => 0,
				'chapterfooter'        => 'yes' === $requestdata['_bx_chapterfooter'] ? 1 : 0,
				'showdate'             => 'yes' === $requestdata['_bx_showdate'] ? 1 : 0,
				'epub'                 => 'yes' === $requestdata['_bx_outputepub'] ? 1 : 0,
				'kf8mobi'              => 'yes' === $requestdata['_bx_outputmobi'] ? 1 : 0,
				'exlibrisfont'         => $requestdata['_bx_exlibrisfont'],
			);
			if ( '' != $requestdata['_bx_exlibrisfile'] ) {
				$parameters['exlibris']     = 1;
				$parameters['exlibrisfile'] = $requestdata['_bx_exlibrisfile'];
			}
			// create callback url
			$nonce = bin2hex(random_bytes(64));
			wc_update_order_item_meta( $item_id, '_bx_nonce', $nonce );

			$callback = site_url( $wp_rewrite->root . 'wc-api/booxtream_callback?order_id=' . $order_id .'&item_id=' . $item_id . '&_bx_nonce=' . $nonce);
			$parameters['callbackurl'] = $callback;

			// do the actual request
			$request = new WC_BooXtream_Request();
			$request->handle_request( $url, $args, $parameters, $order_id );

			return;
		}

	}

endif;
