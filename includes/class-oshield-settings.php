<?php
/**
 * Settings schema, defaults and storage.
 *
 * All settings live in one option: oshield_settings.
 *
 * @package OrderShield
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OShield_Settings {

	const OPTION = 'oshield_settings';

	/**
	 * Cached values.
	 *
	 * @var array|null
	 */
	private static $values = null;

	/**
	 * Tabs => sections => fields.
	 *
	 * Field keys: id, type, label, desc, default, options, placeholder, class.
	 * UI-only keys (ignored by storage):
	 *   master    – this checkbox switches the whole section on/off; other fields hide when off.
	 *   recommend – recommended value, shown as a one-click chip.
	 *   warn      – array( op, value, message ): live warning when the value is risky.
	 *               op is one of '>', '<', '==', 'empty'.
	 *   show_if   – "key=value" or "key!=value", for select-driven fields.
	 * Tab keys: label, icon (dashicon), intro.
	 *
	 * @return array
	 */
	public static function schema() {
		$wc_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		$no_change   = array( '' => __( '— Do not change —', 'order-shield' ) ) + $wc_statuses;

		return array(
			'general'    => array(
				'label'    => __( 'General', 'order-shield' ),
				'icon'     => 'dashicons-admin-generic',
				'intro'    => __( 'Orders screen columns, your support number and the chatbot API.', 'order-shield' ),
				'sections' => array(
					array(
						'title'  => __( 'Orders screen', 'order-shield' ),
						'fields' => array(
							array( 'id' => 'report_column', 'type' => 'checkbox', 'label' => __( 'Fraud report column', 'order-shield' ), 'desc' => __( 'Show the delivery success ratio of each customer in the WooCommerce Orders list.', 'order-shield' ), 'default' => '1' ),
							array( 'id' => 'report_autoload', 'type' => 'checkbox', 'label' => __( 'Load reports automatically', 'order-shield' ), 'desc' => __( 'Load the ratio for every row when the Orders page opens. Turn off to load only when you click "Check".', 'order-shield' ), 'default' => '1' ),
							array( 'id' => 'history_column', 'type' => 'checkbox', 'label' => __( 'Customer history column', 'order-shield' ), 'desc' => __( 'Show how many earlier orders the same phone number has placed in your store.', 'order-shield' ), 'default' => '1' ),
						),
					),
					array(
						'title'  => __( 'Customer messages', 'order-shield' ),
						'fields' => array(
							array( 'id' => 'support_number', 'type' => 'text', 'label' => __( 'Support phone number', 'order-shield' ), 'desc' => __( 'Shown as a "Call" button whenever a customer is blocked, so real customers can still reach you.', 'order-shield' ), 'default' => '', 'placeholder' => '01XXXXXXXXX', 'warn' => array( 'empty', '', __( 'Without a number, blocked customers see "call us" but have no number to call. Real buyers who get blocked by mistake are lost.', 'order-shield' ) ) ),
						),
					),
					array(
						'title'  => __( 'Chatbot / REST API', 'order-shield' ),
						'fields' => array(
							array( 'id' => 'bot_api_key', 'type' => 'apikey', 'label' => __( 'API key', 'order-shield' ), 'desc' => __( 'Lets a chatbot (Messenger, WhatsApp, etc.) look up orders by phone: GET /wp-json/order-shield/v1/orders?phone=01XXXXXXXXX with header X-API-Key. Leave empty to disable.', 'order-shield' ), 'default' => '' ),
						),
					),
					array(
						'title'  => __( 'Uninstall', 'order-shield' ),
						'fields' => array(
							array( 'id' => 'delete_on_uninstall', 'type' => 'checkbox', 'label' => __( 'Delete all data', 'order-shield' ), 'desc' => __( 'Remove Order Shield tables and settings when the plugin is deleted.', 'order-shield' ), 'default' => '0' ),
						),
					),
				),
			),
			'checkout'   => array(
				'label'    => __( 'Checkout Protection', 'order-shield' ),
				'icon'     => 'dashicons-shield-alt',
				'intro'    => __( 'Rules that run when a customer presses "Place order". Not sure what to pick? Use a protection level above.', 'order-shield' ),
				'presets'  => true,
				'sections' => array(
					array(
						'title'  => __( 'Phone number validation', 'order-shield' ),
						'fields' => array(
							array( 'id' => 'phone_validation', 'type' => 'checkbox', 'master' => true, 'label' => __( 'Validate phone', 'order-shield' ), 'desc' => __( 'Only accept valid Bangladeshi mobile numbers (01XXXXXXXXX). Bangla digits and +88 are converted automatically.', 'order-shield' ), 'default' => '1' ),
							array( 'id' => 'phone_message', 'type' => 'text', 'label' => __( 'Error message', 'order-shield' ), 'default' => 'অনুগ্রহ করে আপনার সঠিক ১১ সংখ্যার মোবাইল নম্বর দিন।', 'class' => 'large-text' ),
						),
					),
					array(
						'title'  => __( 'Block list', 'order-shield' ),
						'desc'   => __( 'Blocked phone numbers, IP addresses, devices and emails cannot place orders. Manage them under Order Shield → Block List.', 'order-shield' ),
						'fields' => array(
							array( 'id' => 'blocklist_enabled', 'type' => 'checkbox', 'master' => true, 'label' => __( 'Enforce block list', 'order-shield' ), 'desc' => __( 'Stop blocked customers at checkout.', 'order-shield' ), 'default' => '1' ),
							array(
								'id'      => 'device_match',
								'type'    => 'select',
								'label'   => __( 'Device block covers', 'order-shield' ),
								'desc'    => __( 'When you block a device, its other browsers (Chrome, Firefox, Facebook browser…) are blocked too. "Anywhere" is stricter but can also stop other people who own the exact same phone or computer model.', 'order-shield' ),
								'default' => 'network',
								'options' => array(
									'network' => __( 'On the same internet connection (recommended)', 'order-shield' ),
									'any'     => __( 'Anywhere', 'order-shield' ),
								),
								'recommend' => 'network',
							),
							array( 'id' => 'block_message', 'type' => 'textarea', 'label' => __( 'Message for blocked customers', 'order-shield' ), 'default' => 'দুঃখিত, সন্দেহজনক কার্যকলাপের কারণে আপনার অর্ডার গ্রহণ করা যাচ্ছে না। প্রয়োজনে আমাদের কল করুন।' ),
						),
					),
					array(
						'title'  => __( 'Auto block', 'order-shield' ),
						'fields' => array(
							array( 'id' => 'auto_block', 'type' => 'checkbox', 'master' => true, 'label' => __( 'Auto block', 'order-shield' ), 'desc' => __( 'Automatically block a phone, IP and device that places too many orders in a short time.', 'order-shield' ), 'default' => '0' ),
							array( 'id' => 'auto_block_limit', 'type' => 'number', 'label' => __( 'Max orders', 'order-shield' ), 'desc' => __( 'Block after this many orders…', 'order-shield' ), 'default' => '3', 'min' => 1, 'recommend' => '3', 'warn' => array( '<', 3, __( 'People in the same house or office share one internet connection. With 2, the second person can get blocked too.', 'order-shield' ) ) ),
							array( 'id' => 'auto_block_hours', 'type' => 'number', 'label' => __( 'Within (hours)', 'order-shield' ), 'default' => '24', 'min' => 1, 'recommend' => '24' ),
							array( 'id' => 'auto_block_days', 'type' => 'number', 'label' => __( 'Block for (days)', 'order-shield' ), 'desc' => __( '0 = permanently.', 'order-shield' ), 'default' => '7', 'min' => 0, 'recommend' => '7', 'warn' => array( '==', 0, __( 'Permanent auto blocks never expire. A real customer caught by mistake stays blocked until you remove them by hand.', 'order-shield' ) ) ),
						),
					),
					array(
						'title'  => __( 'Repeat order blocker', 'order-shield' ),
						'fields' => array(
							array( 'id' => 'repeat_block', 'type' => 'checkbox', 'master' => true, 'label' => __( 'Block repeat orders', 'order-shield' ), 'desc' => __( 'Stop the same phone number from ordering again within the time limit.', 'order-shield' ), 'default' => '0' ),
							array( 'id' => 'repeat_hours', 'type' => 'number', 'label' => __( 'Time limit (hours)', 'order-shield' ), 'desc' => __( '24 = 1 day, 72 = 3 days, 168 = 1 week.', 'order-shield' ), 'default' => '24', 'min' => 1, 'recommend' => '24', 'warn' => array( '>', 72, __( 'More than 3 days: your regular customers who come back to buy again will be blocked too.', 'order-shield' ) ) ),
							array( 'id' => 'repeat_message', 'type' => 'textarea', 'label' => __( 'Message', 'order-shield' ), 'desc' => __( 'Placeholders: {hours} = hours left, {order_id} = previous order.', 'order-shield' ), 'default' => 'আপনি ইতিমধ্যে একটি অর্ডার করেছেন (#{order_id})। নতুন অর্ডার করতে {hours} ঘণ্টা অপেক্ষা করুন অথবা আমাদের কল করুন।' ),
						),
					),
				),
			),
			'fraud'      => array(
				'label'    => __( 'Fraud Check', 'order-shield' ),
				'icon'     => 'dashicons-search',
				'intro'    => __( 'How the delivery success ratio is worked out, and blocking customers whose ratio is too low.', 'order-shield' ),
				'sections' => array(
					array(
						'title'  => __( 'Where the delivery ratio comes from', 'order-shield' ),
						'desc'   => __( 'Store history works out of the box from your own WooCommerce orders. To see a customer\'s record across all couriers (Steadfast, Pathao, RedX…), add a courier-ratio API from a provider you subscribe to.', 'order-shield' ),
						'fields' => array(
							array(
								'id'      => 'fraud_source',
								'type'    => 'select',
								'label'   => __( 'Data source', 'order-shield' ),
								'default' => 'local',
								'options' => array(
									'local' => __( 'Store history only (free, no API)', 'order-shield' ),
									'api'   => __( 'Courier ratio API only', 'order-shield' ),
									'both'  => __( 'Both (API first, store history as backup)', 'order-shield' ),
								),
							),
							array( 'id' => 'fraud_api_url', 'type' => 'text', 'show_if' => 'fraud_source!=local', 'label' => __( 'API URL', 'order-shield' ), 'desc' => __( 'Use {phone} where the number goes, e.g. https://provider.example/api/check?phone={phone}. For POST, the phone is also sent in the body.', 'order-shield' ), 'default' => '', 'class' => 'large-text code' ),
							array(
								'id'      => 'fraud_api_method',
								'type'    => 'select',
								'show_if' => 'fraud_source!=local',
								'label'   => __( 'Request method', 'order-shield' ),
								'default' => 'GET',
								'options' => array( 'GET' => 'GET', 'POST' => 'POST' ),
							),
							array( 'id' => 'fraud_api_headers', 'type' => 'textarea', 'show_if' => 'fraud_source!=local', 'label' => __( 'Request headers', 'order-shield' ), 'desc' => __( 'One per line, e.g. Authorization: Bearer YOUR_API_KEY', 'order-shield' ), 'default' => '', 'class' => 'large-text code' ),
							array( 'id' => 'fraud_cache_hours', 'type' => 'number', 'show_if' => 'fraud_source!=local', 'label' => __( 'Cache results (hours)', 'order-shield' ), 'desc' => __( 'Saves API calls. 0 = always fetch fresh.', 'order-shield' ), 'default' => '24', 'min' => 0 ),
						),
					),
					array(
						'title'  => __( 'Store history rules', 'order-shield' ),
						'fields' => array(
							array( 'id' => 'success_statuses', 'type' => 'statuses', 'label' => __( 'Count as delivered', 'order-shield' ), 'default' => array( 'wc-completed' ) ),
							array( 'id' => 'cancel_statuses', 'type' => 'statuses', 'label' => __( 'Count as cancelled / returned', 'order-shield' ), 'default' => array( 'wc-cancelled', 'wc-failed', 'wc-refunded', 'wc-returned' ) ),
						),
					),
					array(
						'title'  => __( 'Smart order filter', 'order-shield' ),
						'desc'   => __( 'Block the order at checkout when a customer\'s delivery success ratio is too low.', 'order-shield' ),
						'fields' => array(
							array( 'id' => 'smart_filter', 'type' => 'checkbox', 'master' => true, 'label' => __( 'Enable smart filter', 'order-shield' ), 'desc' => __( 'Block customers who often cancel or return parcels.', 'order-shield' ), 'default' => '0' ),
							array( 'id' => 'smart_threshold', 'type' => 'number', 'label' => __( 'Minimum success ratio (%)', 'order-shield' ), 'desc' => __( 'Example: 60 blocks a customer who received fewer than 6 of their last 10 orders.', 'order-shield' ), 'default' => '70', 'min' => 0, 'max' => 100, 'recommend' => '60', 'warn' => array( '>', 80, __( 'Above 80% is very strict: one returned parcel can be enough to block a good customer.', 'order-shield' ) ) ),
							array( 'id' => 'smart_min_orders', 'type' => 'number', 'label' => __( 'Only judge after (orders)', 'order-shield' ), 'desc' => __( 'Customers with fewer finished orders than this are treated as new and allowed.', 'order-shield' ), 'default' => '2', 'min' => 1, 'recommend' => '2', 'warn' => array( '<', 2, __( 'With 1, a single cancelled order is enough to block someone.', 'order-shield' ) ) ),
							array( 'id' => 'smart_message', 'type' => 'textarea', 'label' => __( 'Block message', 'order-shield' ), 'default' => 'দুঃখিত, এই নাম্বার দিয়ে আমরা অর্ডারটি গ্রহণ করতে পারছি না। অর্ডার কনফার্ম করতে আমাদের কল করুন।' ),
						),
					),
				),
			),
			'incomplete' => array(
				'label'    => __( 'Incomplete Orders', 'order-shield' ),
				'icon'     => 'dashicons-cart',
				'intro'    => __( 'Save customers who leave the checkout without ordering, so you can call them back.', 'order-shield' ),
				'sections' => array(
					array(
						'title'  => __( 'Capture', 'order-shield' ),
						'desc'   => __( 'Saves the details of customers who fill in the checkout form but leave without ordering, so you can call them and recover the sale.', 'order-shield' ),
						'fields' => array(
							array( 'id' => 'incomplete_enabled', 'type' => 'checkbox', 'master' => true, 'label' => __( 'Capture incomplete orders', 'order-shield' ), 'default' => '0' ),
							array( 'id' => 'incomplete_skip_existing', 'type' => 'checkbox', 'label' => __( 'Skip existing customers', 'order-shield' ), 'desc' => __( 'Do not capture phone numbers that already have an order.', 'order-shield' ), 'default' => '0' ),
							array( 'id' => 'incomplete_cooldown', 'type' => 'number', 'label' => __( 'Cooldown after an order (minutes)', 'order-shield' ), 'desc' => __( 'Right after a customer orders, do not capture them again for this long. 0 = off.', 'order-shield' ), 'default' => '30', 'min' => 0, 'recommend' => '30' ),
							array( 'id' => 'incomplete_retention', 'type' => 'number', 'label' => __( 'Keep for (days)', 'order-shield' ), 'default' => '30', 'min' => 1 ),
							array(
								'id'      => 'incomplete_digest',
								'type'    => 'select',
								'label'   => __( 'Email summary', 'order-shield' ),
								'default' => 'off',
								'options' => array(
									'off'   => __( 'Off', 'order-shield' ),
									'daily' => __( 'Daily', 'order-shield' ),
								),
							),
							array( 'id' => 'incomplete_digest_to', 'type' => 'text', 'show_if' => 'incomplete_digest=daily', 'label' => __( 'Send summary to', 'order-shield' ), 'desc' => __( 'Leave empty to use the site admin email.', 'order-shield' ), 'default' => '' ),
						),
					),
				),
			),
			'live'       => array(
				'label'    => __( 'Live Visitors', 'order-shield' ),
				'icon'     => 'dashicons-visibility',
				'intro'    => __( 'Watch who is on your site and replay what they did. Recordings take database space, so keep them short.', 'order-shield' ),
				'sections' => array(
					array(
						'title'  => __( 'Live visitors', 'order-shield' ),
						'desc'   => __( 'See who is on your website right now, which page they are on and how many pages they viewed.', 'order-shield' ),
						'fields' => array(
							array( 'id' => 'live_enabled', 'type' => 'checkbox', 'master' => true, 'label' => __( 'Track visitors', 'order-shield' ), 'default' => '1' ),
							array( 'id' => 'live_exclude_admins', 'type' => 'checkbox', 'label' => __( 'Ignore shop staff', 'order-shield' ), 'desc' => __( 'Do not track logged-in administrators and shop managers.', 'order-shield' ), 'default' => '1' ),
							array( 'id' => 'live_retention', 'type' => 'number', 'label' => __( 'Keep visitor history for (days)', 'order-shield' ), 'default' => '7', 'min' => 1, 'recommend' => '7', 'warn' => array( '>', 30, __( 'Long history makes the database big and the site slower.', 'order-shield' ) ) ),
						),
					),
					array(
						'title'  => __( 'Session replay', 'order-shield' ),
						'desc'   => __( 'Record what visitors do and watch it back like a video, from Live Visitors or Incomplete Orders.', 'order-shield' ),
						'fields' => array(
							array( 'id' => 'replay_enabled', 'type' => 'checkbox', 'master' => true, 'label' => __( 'Record sessions', 'order-shield' ), 'default' => '1' ),
							array(
								'id'      => 'replay_scope',
								'type'    => 'select',
								'label'   => __( 'Record on', 'order-shield' ),
								'default' => 'all',
								'options' => array(
									'all'      => __( 'Every page (whole visit)', 'order-shield' ),
									'checkout' => __( 'Checkout page only (uses less storage)', 'order-shield' ),
								),
								'recommend' => 'checkout',
							),
							array( 'id' => 'replay_retention', 'type' => 'number', 'label' => __( 'Keep recordings for (days)', 'order-shield' ), 'desc' => __( 'Recordings use database space; older ones are deleted automatically. Recordings of customers who left at checkout are kept as long as the incomplete order.', 'order-shield' ), 'default' => '3', 'min' => 1, 'recommend' => '3', 'warn' => array( '>', 7, __( 'Recordings are large. Keeping them more than a week can fill the database quickly.', 'order-shield' ) ) ),
							array( 'id' => 'replay_max_seconds', 'type' => 'number', 'label' => __( 'Max recording per page (seconds)', 'order-shield' ), 'default' => '300', 'min' => 30, 'recommend' => '300', 'warn' => array( '>', 600, __( 'Long recordings use a lot of database space.', 'order-shield' ) ) ),
							array(
								'id'      => 'replay_mask',
								'type'    => 'select',
								'label'   => __( 'Hide typed text', 'order-shield' ),
								'default' => 'sensitive',
								'options' => array(
									'sensitive' => __( 'Hide passwords and card fields only', 'order-shield' ),
									'all'       => __( 'Hide everything typed', 'order-shield' ),
								),
							),
						),
					),
				),
			),
			'courier'    => array(
				'label'    => __( 'Courier', 'order-shield' ),
				'icon'     => 'dashicons-car',
				'intro'    => __( 'Send orders to Steadfast or Pathao and update order status automatically. If another plugin already sends your parcels, keep these off to avoid sending twice.', 'order-shield' ),
				'sections' => array(
					array(
						'title'  => 'Steadfast',
						'fields' => array(
							array( 'id' => 'steadfast_enabled', 'type' => 'checkbox', 'master' => true, 'label' => __( 'Enable Steadfast', 'order-shield' ), 'default' => '0' ),
							array( 'id' => 'steadfast_api_key', 'type' => 'text', 'label' => __( 'API key', 'order-shield' ), 'default' => '', 'class' => 'regular-text code' ),
							array( 'id' => 'steadfast_secret_key', 'type' => 'password', 'label' => __( 'Secret key', 'order-shield' ), 'default' => '' ),
							array( 'id' => 'steadfast_webhook_token', 'type' => 'webhook', 'courier' => 'steadfast', 'label' => __( 'Webhook', 'order-shield' ), 'desc' => __( 'In Steadfast → API → Webhook, paste this Callback URL and Auth Token to get live delivery updates.', 'order-shield' ), 'default' => '' ),
						),
					),
					array(
						'title'  => 'Pathao',
						'fields' => array(
							array( 'id' => 'pathao_enabled', 'type' => 'checkbox', 'master' => true, 'label' => __( 'Enable Pathao', 'order-shield' ), 'default' => '0' ),
							array(
								'id'      => 'pathao_env',
								'type'    => 'select',
								'label'   => __( 'Environment', 'order-shield' ),
								'default' => 'live',
								'options' => array(
									'live'    => __( 'Live', 'order-shield' ),
									'sandbox' => __( 'Sandbox (testing)', 'order-shield' ),
								),
							),
							array( 'id' => 'pathao_client_id', 'type' => 'text', 'label' => __( 'Client ID', 'order-shield' ), 'default' => '', 'class' => 'regular-text code' ),
							array( 'id' => 'pathao_client_secret', 'type' => 'password', 'label' => __( 'Client secret', 'order-shield' ), 'default' => '' ),
							array( 'id' => 'pathao_username', 'type' => 'text', 'label' => __( 'Merchant email', 'order-shield' ), 'default' => '' ),
							array( 'id' => 'pathao_password', 'type' => 'password', 'label' => __( 'Merchant password', 'order-shield' ), 'default' => '' ),
							array( 'id' => 'pathao_store_id', 'type' => 'text', 'label' => __( 'Store ID', 'order-shield' ), 'desc' => __( 'Leave empty to use your first Pathao store.', 'order-shield' ), 'default' => '' ),
							array( 'id' => 'pathao_weight', 'type' => 'text', 'label' => __( 'Default weight (kg)', 'order-shield' ), 'default' => '0.5' ),
							array( 'id' => 'pathao_webhook_secret', 'type' => 'webhook', 'courier' => 'pathao', 'label' => __( 'Webhook', 'order-shield' ), 'desc' => __( 'In Pathao Merchant → Developers API → Webhook, paste this URL and secret.', 'order-shield' ), 'default' => '' ),
						),
					),
					array(
						'title'  => __( 'When the courier updates a parcel', 'order-shield' ),
						'desc'   => __( 'Change the WooCommerce order status automatically from webhook updates.', 'order-shield' ),
						'fields' => array(
							array( 'id' => 'courier_status_sent', 'type' => 'select', 'label' => __( 'Sent to courier', 'order-shield' ), 'default' => 'wc-in-transit', 'options' => $no_change ),
							array( 'id' => 'courier_status_delivered', 'type' => 'select', 'label' => __( 'Delivered', 'order-shield' ), 'default' => 'wc-completed', 'options' => $no_change ),
							array( 'id' => 'courier_status_partial', 'type' => 'select', 'label' => __( 'Partially delivered', 'order-shield' ), 'default' => 'wc-completed', 'options' => $no_change ),
							array( 'id' => 'courier_status_cancelled', 'type' => 'select', 'label' => __( 'Cancelled / returned', 'order-shield' ), 'default' => 'wc-returned', 'options' => $no_change ),
						),
					),
				),
			),
			'vpn'        => array(
				'label'    => __( 'VPN & Country', 'order-shield' ),
				'icon'     => 'dashicons-admin-site-alt3',
				'intro'    => __( 'Stop orders that come through a VPN or from outside the countries you deliver to.', 'order-shield' ),
				'sections' => array(
					array(
						'title'  => __( 'VPN / proxy block', 'order-shield' ),
						'desc'   => __( 'Fake orders often come through VPNs. Detection uses proxycheck.io (free plan: 1,000 checks/day without a key; add a free key for more). If the check fails, the customer is allowed.', 'order-shield' ),
						'fields' => array(
							array( 'id' => 'vpn_block', 'type' => 'checkbox', 'master' => true, 'label' => __( 'Block VPN and proxy users', 'order-shield' ), 'default' => '0' ),
							array( 'id' => 'proxycheck_key', 'type' => 'text', 'label' => __( 'proxycheck.io API key', 'order-shield' ), 'desc' => __( 'Optional.', 'order-shield' ), 'default' => '', 'class' => 'regular-text code' ),
						),
					),
					array(
						'title'  => __( 'Country restriction', 'order-shield' ),
						'fields' => array(
							array( 'id' => 'country_restrict', 'type' => 'checkbox', 'master' => true, 'label' => __( 'Allow only some countries', 'order-shield' ), 'default' => '0' ),
							array( 'id' => 'allowed_countries', 'type' => 'text', 'label' => __( 'Allowed countries', 'order-shield' ), 'desc' => __( 'Two-letter codes separated by commas, e.g. BD', 'order-shield' ), 'default' => 'BD' ),
						),
					),
					array(
						'title'  => __( 'Where to check', 'order-shield' ),
						'fields' => array(
							array(
								'id'      => 'vpn_scope',
								'type'    => 'select',
								'label'   => __( 'Apply to', 'order-shield' ),
								'default' => 'checkout',
								'options' => array(
									'checkout' => __( 'Placing an order only', 'order-shield' ),
									'site'     => __( 'Whole website', 'order-shield' ),
								),
								'recommend' => 'checkout',
							),
							array( 'id' => 'vpn_message', 'type' => 'textarea', 'label' => __( 'Message', 'order-shield' ), 'default' => 'VPN বা প্রক্সি ব্যবহার করে অর্ডার করা যাবে না। অনুগ্রহ করে VPN বন্ধ করে আবার চেষ্টা করুন।' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Flat map of field id => field definition.
	 *
	 * @return array
	 */
	public static function fields() {
		static $fields = null;
		if ( null === $fields ) {
			$fields = array();
			foreach ( self::schema() as $tab_id => $tab ) {
				foreach ( $tab['sections'] as $section ) {
					foreach ( $section['fields'] as $field ) {
						$field['tab']           = $tab_id;
						$fields[ $field['id'] ] = $field;
					}
				}
			}
		}
		return $fields;
	}

	/**
	 * All values merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$values ) {
			$stored = get_option( self::OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();
			$values = array();
			foreach ( self::fields() as $id => $field ) {
				$values[ $id ] = array_key_exists( $id, $stored ) ? $stored[ $id ] : ( isset( $field['default'] ) ? $field['default'] : '' );
			}
			// Keep internal keys that are not part of the schema.
			foreach ( $stored as $k => $v ) {
				if ( ! isset( $values[ $k ] ) ) {
					$values[ $k ] = $v;
				}
			}
			self::$values = $values;
		}
		return self::$values;
	}

	/**
	 * Get one value.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : '';
	}

	/**
	 * Update one or many values programmatically.
	 *
	 * @param array $changes key => value.
	 */
	public static function update( $changes ) {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		update_option( self::OPTION, array_merge( $stored, $changes ) );
		self::$values = null;
	}

	/**
	 * Sanitize submitted values of a single tab and merge with stored values.
	 *
	 * @param string $tab   Tab id.
	 * @param array  $input Raw input.
	 * @return array Full settings array.
	 */
	public static function sanitize_tab( $tab, $input ) {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		foreach ( self::fields() as $id => $field ) {
			if ( $field['tab'] !== $tab ) {
				continue;
			}
			$raw = isset( $input[ $id ] ) ? $input[ $id ] : null;

			switch ( $field['type'] ) {
				case 'checkbox':
					$value = ! empty( $raw ) ? '1' : '0';
					break;
				case 'number':
					$value = is_numeric( $raw ) ? (string) ( 0 + $raw ) : (string) $field['default'];
					if ( isset( $field['min'] ) && (float) $value < $field['min'] ) {
						$value = (string) $field['min'];
					}
					if ( isset( $field['max'] ) && (float) $value > $field['max'] ) {
						$value = (string) $field['max'];
					}
					break;
				case 'select':
					$value = ( null !== $raw && array_key_exists( (string) $raw, $field['options'] ) ) ? (string) $raw : (string) $field['default'];
					break;
				case 'statuses':
					$value = array_values( array_filter( array_map( 'sanitize_key', (array) $raw ) ) );
					break;
				case 'status_templates':
					$value = array();
					foreach ( (array) $raw as $slug => $tpl ) {
						$value[ sanitize_key( $slug ) ] = sanitize_textarea_field( wp_unslash( $tpl ) );
					}
					break;
				case 'textarea':
					$value = sanitize_textarea_field( wp_unslash( (string) $raw ) );
					break;
				case 'password':
					// Keep the stored secret when the box is left blank.
					$raw   = trim( wp_unslash( (string) $raw ) );
					$value = ( '' === $raw && isset( $stored[ $id ] ) ) ? $stored[ $id ] : sanitize_text_field( $raw );
					break;
				default:
					if ( false !== strpos( $id, '_url' ) ) {
						// URL templates keep {placeholders} and %XX encoding, so only strip tags/whitespace.
						$value = trim( wp_strip_all_tags( wp_unslash( (string) $raw ) ) );
					} else {
						$value = sanitize_text_field( wp_unslash( (string) $raw ) );
					}
			}
			$stored[ $id ] = $value;
		}

		return $stored;
	}

	/**
	 * Ready-made protection levels for checkout rules.
	 *
	 * @return array id => [label, desc, values]
	 */
	public static function presets() {
		return array(
			'light'    => array(
				'label'  => __( 'Light', 'order-shield' ),
				'desc'   => __( 'Only checks the phone number and your block list. Never stops a real customer by mistake.', 'order-shield' ),
				'values' => array(
					'phone_validation'  => '1',
					'blocklist_enabled' => '1',
					'auto_block'        => '0',
					'repeat_block'      => '0',
					'smart_filter'      => '0',
					'vpn_block'         => '0',
					'country_restrict'  => '0',
				),
			),
			'balanced' => array(
				'label'  => __( 'Balanced', 'order-shield' ),
				'desc'   => __( 'Recommended for most shops. Stops repeat and bulk fake orders, allows returning customers after a day.', 'order-shield' ),
				'values' => array(
					'phone_validation'  => '1',
					'blocklist_enabled' => '1',
					'auto_block'        => '1',
					'auto_block_limit'  => '3',
					'auto_block_hours'  => '24',
					'auto_block_days'   => '7',
					'repeat_block'      => '1',
					'repeat_hours'      => '24',
					'smart_filter'      => '1',
					'smart_threshold'   => '60',
					'smart_min_orders'  => '2',
					'vpn_block'         => '0',
					'country_restrict'  => '0',
				),
			),
			'strict'   => array(
				'label'  => __( 'Strict', 'order-shield' ),
				'desc'   => __( 'For shops with many fake orders. Also blocks VPN and foreign visitors. Some real customers may need to call you.', 'order-shield' ),
				'values' => array(
					'phone_validation'  => '1',
					'blocklist_enabled' => '1',
					'auto_block'        => '1',
					'auto_block_limit'  => '2',
					'auto_block_hours'  => '24',
					'auto_block_days'   => '30',
					'repeat_block'      => '1',
					'repeat_hours'      => '72',
					'smart_filter'      => '1',
					'smart_threshold'   => '70',
					'smart_min_orders'  => '2',
					'vpn_block'         => '1',
					'country_restrict'  => '1',
					'allowed_countries' => 'BD',
				),
			),
		);
	}

	/**
	 * Which preset matches the current settings, or '' for custom.
	 *
	 * @return string
	 */
	public static function current_preset() {
		foreach ( self::presets() as $id => $preset ) {
			$match = true;
			foreach ( $preset['values'] as $k => $v ) {
				if ( (string) self::get( $k ) !== (string) $v ) {
					$match = false;
					break;
				}
			}
			if ( $match ) {
				return $id;
			}
		}
		return '';
	}

	/**
	 * Apply a preset.
	 *
	 * @param string $id Preset id.
	 * @return bool
	 */
	public static function apply_preset( $id ) {
		$presets = self::presets();
		if ( ! isset( $presets[ $id ] ) ) {
			return false;
		}
		self::update( $presets[ $id ]['values'] );
		return true;
	}

	/**
	 * Settings that are switched on but set in a risky way.
	 * Used by the dashboard "Needs attention" box.
	 *
	 * @return array List of [message, tab].
	 */
	public static function advice() {
		$out      = array();
		$blocking = oshield_on( 'blocklist_enabled' ) || oshield_on( 'auto_block' ) || oshield_on( 'repeat_block' ) || oshield_on( 'smart_filter' ) || oshield_on( 'vpn_block' ) || oshield_on( 'country_restrict' );
		if ( $blocking && '' === trim( (string) self::get( 'support_number' ) ) ) {
			$out[] = array( __( 'Add a support phone number. Blocked customers are told to call you, but no number is shown.', 'order-shield' ), 'general' );
		}
		if ( oshield_on( 'repeat_block' ) && (float) self::get( 'repeat_hours' ) > 72 ) {
			/* translators: %d hours */
			$out[] = array( sprintf( __( 'Repeat order blocker is set to %d hours. Returning customers will be blocked for that long — 24 hours is usually enough.', 'order-shield' ), (int) self::get( 'repeat_hours' ) ), 'checkout' );
		}
		if ( oshield_on( 'auto_block' ) && (float) self::get( 'auto_block_limit' ) < 3 ) {
			$out[] = array( __( 'Auto block triggers after only 2 orders. Family members on the same WiFi can be blocked — 3 is safer.', 'order-shield' ), 'checkout' );
		}
		if ( oshield_on( 'smart_filter' ) && (float) self::get( 'smart_threshold' ) > 80 ) {
			$out[] = array( __( 'Smart filter needs more than 80% delivery success. One returned parcel can block a good customer.', 'order-shield' ), 'fraud' );
		}
		if ( oshield_on( 'replay_enabled' ) && 'all' === self::get( 'replay_scope' ) ) {
			$out[] = array( __( 'Session replay records every page. "Checkout page only" saves a lot of database space.', 'order-shield' ), 'live' );
		}
		return $out;
	}

	/**
	 * Save a tab.
	 *
	 * @param string $tab   Tab.
	 * @param array  $input Raw input.
	 */
	public static function save_tab( $tab, $input ) {
		update_option( self::OPTION, self::sanitize_tab( $tab, $input ) );
		self::$values = null;
	}
}
