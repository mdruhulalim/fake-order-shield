<?php
/**
 * Front end: landing page, account (register / login / buy / my licenses).
 *
 * Pages with [fos_landing] or [fos_account] use the plugin's own full-width
 * template, so the site looks right on any theme.
 *
 * @package FOSLicenseServer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FOS_Front {

	/**
	 * Message shown after a form post.
	 *
	 * @var array [type, text]
	 */
	private static $msg = array();

	/**
	 * Hooks.
	 */
	public static function init() {
		add_shortcode( 'fos_landing', array( __CLASS__, 'landing' ) );
		add_shortcode( 'fos_account', array( __CLASS__, 'account' ) );
		add_filter( 'template_include', array( __CLASS__, 'template' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'admin_bar' ) );
		self::handle_post();
	}

	/**
	 * Customers do not need the admin bar.
	 *
	 * @param bool $show Show.
	 * @return bool
	 */
	public static function admin_bar( $show ) {
		return current_user_can( 'edit_posts' ) ? $show : false;
	}

	/**
	 * Use the plugin canvas for our pages.
	 *
	 * @param string $template Template.
	 * @return string
	 */
	public static function template( $template ) {
		if ( is_singular( 'page' ) ) {
			$content = (string) get_post_field( 'post_content', get_queried_object_id() );
			if ( has_shortcode( $content, 'fos_landing' ) || has_shortcode( $content, 'fos_account' ) ) {
				wp_enqueue_style( 'fos-font', 'https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
				wp_enqueue_style( 'fos-site', FOS_LS_URL . 'assets/site.css', array( 'dashicons' ), FOS_LS_VERSION );
				return FOS_LS_DIR . 'includes/canvas.php';
			}
		}
		return $template;
	}

	/**
	 * Register and order forms.
	 */
	private static function handle_post() {
		if ( empty( $_POST['fos_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		$action = sanitize_key( $_POST['fos_action'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( 'register' === $action ) {
			if ( ! isset( $_POST['_fosnonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_fosnonce'] ), 'fos_register' ) ) {
				self::$msg = array( 'error', __( 'Session expired. Please try again.', 'fos-license-server' ) );
				return;
			}
			$name  = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
			$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
			$phone = preg_replace( '/[^0-9+]/', '', sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ) );
			$pass  = (string) wp_unslash( $_POST['password'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passwords are not sanitized.
			if ( ! $name || ! is_email( $email ) ) {
				self::$msg = array( 'error', __( 'Please enter your name and a valid email.', 'fos-license-server' ) );
				return;
			}
			if ( strlen( $pass ) < 8 ) {
				self::$msg = array( 'error', __( 'Password must be at least 8 characters.', 'fos-license-server' ) );
				return;
			}
			if ( email_exists( $email ) ) {
				self::$msg = array( 'error', __( 'An account with this email already exists. Please log in.', 'fos-license-server' ) );
				return;
			}
			$user_id = wp_insert_user(
				array(
					'user_login'   => $email,
					'user_email'   => $email,
					'user_pass'    => $pass,
					'display_name' => $name,
					'first_name'   => $name,
					'role'         => 'subscriber',
				)
			);
			if ( is_wp_error( $user_id ) ) {
				self::$msg = array( 'error', $user_id->get_error_message() );
				return;
			}
			update_user_meta( $user_id, 'fos_phone', $phone );
			wp_set_current_user( $user_id );
			wp_set_auth_cookie( $user_id, true );
			$flag   = 'welcome';
			$domain = sanitize_text_field( wp_unslash( $_POST['domain'] ?? '' ) );
			if ( '' !== $domain ) {
				$t    = FOS_Store::start_trial( $user_id, $domain );
				$flag = is_wp_error( $t ) ? 'welcome' : 'trial';
			}
			wp_safe_redirect( add_query_arg( 'fos', $flag, FOS_Store::account_url() ) );
			exit;
		}

		if ( 'trial' === $action ) {
			if ( ! is_user_logged_in() || ! isset( $_POST['_fosnonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_fosnonce'] ), 'fos_trial' ) ) {
				self::$msg = array( 'error', __( 'Session expired. Please log in and try again.', 'fos-license-server' ) );
				return;
			}
			$t = FOS_Store::start_trial( get_current_user_id(), sanitize_text_field( wp_unslash( $_POST['domain'] ?? '' ) ) );
			if ( is_wp_error( $t ) ) {
				self::$msg = array( 'error', $t->get_error_message() );
				return;
			}
			wp_safe_redirect( add_query_arg( 'fos', 'trial', FOS_Store::account_url() ) );
			exit;
		}

		if ( 'order' === $action ) {
			if ( ! is_user_logged_in() || ! isset( $_POST['_fosnonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_fosnonce'] ), 'fos_order' ) ) {
				self::$msg = array( 'error', __( 'Session expired. Please log in and try again.', 'fos-license-server' ) );
				return;
			}
			$r = FOS_Store::create_order(
				array(
					'user_id'    => get_current_user_id(),
					'months'     => absint( $_POST['months'] ?? 0 ),
					'domain'     => sanitize_text_field( wp_unslash( $_POST['domain'] ?? '' ) ),
					'method'     => sanitize_key( $_POST['method'] ?? '' ),
					'sender'     => sanitize_text_field( wp_unslash( $_POST['sender'] ?? '' ) ),
					'trx_id'     => sanitize_text_field( wp_unslash( $_POST['trx_id'] ?? '' ) ),
					'renewal_of' => absint( $_POST['renewal_of'] ?? 0 ),
				)
			);
			if ( is_wp_error( $r ) ) {
				self::$msg = array( 'error', $r->get_error_message() );
				return;
			}
			wp_safe_redirect( add_query_arg( 'fos', 'ordered', FOS_Store::account_url() ) );
			exit;
		}
	}

	/**
	 * Price in Taka.
	 *
	 * @param float $n Amount.
	 * @return string
	 */
	private static function tk( $n ) {
		return '৳' . number_format_i18n( $n );
	}

	/**
	 * WhatsApp chat link for the support number (BD 01XXXXXXXXX → 8801XXXXXXXXX).
	 *
	 * @return string Empty when no number is set.
	 */
	private static function wa_link() {
		$n = preg_replace( '/[^0-9]/', '', FOS_Store::opt( 'support' ) );
		if ( '' === $n ) {
			return '';
		}
		if ( 11 === strlen( $n ) && '0' === $n[0] ) {
			$n = '88' . $n;
		}
		return 'https://wa.me/' . $n . '?text=' . rawurlencode( 'আসসালামু আলাইকুম, Fake Order Shield নিয়ে সাহায্য দরকার।' );
	}

	/**
	 * Landing page.
	 *
	 * @return string
	 */
	public static function landing() {
		$account  = FOS_Store::account_url();
		$signup   = $account . '#register';
		$packages = FOS_Store::packages();
		$trial    = (int) FOS_Store::opt( 'trial_days' );
		$support  = FOS_Store::opt( 'support' );
		$labels   = array(
			1  => '1 মাস',
			3  => '3 মাস',
			6  => '6 মাস',
			12 => '12 মাস',
		);
		$pillars  = array(
			array(
				'shield-alt',
				'আটকায়',
				'চেকআউটেই ফেক অর্ডার থামে',
				array(
					array( 'smartphone', 'ফোন নম্বর যাচাই', 'শুধু সঠিক 11 সংখ্যার বাংলাদেশি নম্বর। বাংলা সংখ্যা ও +88 নিজেই ঠিক হয়।' ),
					array( 'dismiss', 'ব্লক লিস্ট', 'ফোন, IP, ডিভাইস বা ইমেইল ব্লক। একই ডিভাইসের সব ব্রাউজার আটকায়।' ),
					array( 'performance', 'অটো ব্লক ও রিপিট ব্লকার', 'অল্প সময়ে অনেক অর্ডার বা বারবার অর্ডার নিজেই আটকায়।' ),
					array( 'admin-site-alt3', 'VPN ও দেশ ব্লক', 'VPN বা বিদেশ থেকে আসা সন্দেহজনক অর্ডার বন্ধ।' ),
				),
			),
			array(
				'search',
				'চেনায়',
				'অর্ডার কনফার্মের আগেই জানুন কে আসল',
				array(
					array( 'chart-bar', 'ফ্রড চেক', 'আগের কয়টা পার্সেল ডেলিভারি হয়েছে, কয়টা ফেরত — এক নজরে।' ),
					array( 'filter', 'স্মার্ট অর্ডার ফিল্টার', 'ডেলিভারি হার কম হলে অর্ডার চেকআউটেই থেমে যায়।' ),
					array( 'visibility', 'লাইভ ভিজিটর ও সেশন রিপ্লে', 'কে সাইটে আছে আর কী করেছে, ভিডিওর মতো দেখুন।' ),
				),
			),
			array(
				'money-alt',
				'ফেরত আনে',
				'হারানো বিক্রি আর সময় বাঁচায়',
				array(
					array( 'cart', 'ইনকমপ্লিট অর্ডার', 'চেকআউটে তথ্য দিয়ে চলে গেলেও নম্বর পান, কল করে অর্ডার নিন।' ),
					array( 'car', 'Steadfast ও Pathao', 'এক ক্লিকে পার্সেল পাঠান, স্ট্যাটাস নিজেই আপডেট হয়।' ),
					array( 'admin-settings', 'এক ক্লিকে সেটআপ', 'Light, Balanced বা Strict — একটা বেছে নিলেই সব রুল সেট।' ),
				),
			),
		);
		$monthly  = $packages[1];
		$six_pm   = $packages[6] > 0 ? round( $packages[6] / 6 ) : 0;
		$per_day  = $packages[12] > 0 ? (int) ceil( $packages[12] / 365 ) : (int) ceil( $monthly / 30 );
		ob_start();
		?>
		<section class="fos-hero2">
			<div class="fos-glow fos-glow-a"></div><div class="fos-glow fos-glow-b"></div>
			<div class="fos-container fos-hero2-grid">
				<div class="fos-hero2-copy">
					<span class="fos-chip"><span class="fos-dot"></span> বাংলাদেশি WooCommerce স্টোরের জন্য বানানো</span>
					<h1>ফেক অর্ডার ঢোকার আগেই <span class="fos-grad">থামিয়ে দিন</span></h1>
					<p class="fos-lead2">প্রতিটা ফেক অর্ডার মানে নষ্ট ডেলিভারি চার্জ, প্যাকেজিং আর সময়। Fake Order Shield চেকআউটেই সন্দেহজনক অর্ডার আটকায়, আসল কাস্টমারকে নির্বিঘ্নে অর্ডার করতে দেয়।</p>
					<div class="fos-cta">
						<a class="fos-btn fos-btn-glow" href="<?php echo esc_url( $signup ); ?>"><?php echo esc_html( $trial ); ?> দিন ফ্রি ট্রায়াল শুরু করুন <span class="dashicons dashicons-arrow-right-alt"></span></a>
						<a class="fos-btn fos-btn-outline" href="#how">কীভাবে কাজ করে</a>
					</div>
					<ul class="fos-trust">
						<li><span class="dashicons dashicons-yes-alt"></span> কোনো পেমেন্ট লাগবে না</li>
						<li><span class="dashicons dashicons-yes-alt"></span> 2 মিনিটে সেটআপ</li>
						<li><span class="dashicons dashicons-yes-alt"></span> বাংলায় সাপোর্ট</li>
					</ul>
				</div>

				<div class="fos-hero2-visual" aria-hidden="true">
					<div class="fos-app">
						<div class="fos-app-bar"><span></span><span></span><span></span><b>Fake Order Shield</b></div>
						<div class="fos-app-body">
							<div class="fos-app-stats">
								<div><small>আজ আটকানো</small><strong>12</strong></div>
								<div><small>অনলাইন এখন</small><strong>8</strong></div>
								<div><small>ইনকমপ্লিট</small><strong>5</strong></div>
							</div>
							<div class="fos-app-row is-bad"><span class="dashicons dashicons-dismiss"></span><div><b>অর্ডার আটকানো হয়েছে</b><small>017XXXXXXXX · ডেলিভারি হার 20%</small></div><em>ব্লক</em></div>
							<div class="fos-app-row is-good"><span class="dashicons dashicons-yes"></span><div><b>অর্ডার গ্রহণ</b><small>018XXXXXXXX · ডেলিভারি হার 100%</small></div><em>আসল</em></div>
							<div class="fos-app-row is-warn"><span class="dashicons dashicons-phone"></span><div><b>ইনকমপ্লিট অর্ডার</b><small>019XXXXXXXX · ৳899 কার্টে</small></div><em>কল করুন</em></div>
						</div>
					</div>
					<div class="fos-float fos-float-a"><span class="dashicons dashicons-shield-alt"></span> VPN অর্ডার আটকানো</div>
					<div class="fos-float fos-float-b"><span class="dashicons dashicons-car"></span> Steadfast-এ পাঠানো হয়েছে</div>
				</div>
			</div>
			<div class="fos-container">
				<div class="fos-works">
					<span>যার সাথে কাজ করে</span><b>WooCommerce</b><b>Steadfast</b><b>Pathao</b>
				</div>
			</div>
		</section>

		<section class="fos-section fos-pain">
			<div class="fos-container fos-pain-grid">
				<div>
					<span class="fos-eyebrow">সমস্যাটা ছোট না</span>
					<h2 class="fos-h2l">ফেক অর্ডারে আপনার মাসে কত টাকা যাচ্ছে?</h2>
					<p class="fos-muted">প্রতিটা ফেরত পার্সেলে দুই দিকের ডেলিভারি চার্জ, প্যাকেজিং আর কল করার সময় যায়। আপনার স্টোরের হিসাব দিন, আনুমানিক ক্ষতি দেখুন।</p>
					<ul class="fos-pain-list">
						<li><span class="dashicons dashicons-minus"></span> একই নম্বর থেকে বারবার ফেক অর্ডার</li>
						<li><span class="dashicons dashicons-minus"></span> পার্সেল পাঠানোর পর ফোন বন্ধ</li>
						<li><span class="dashicons dashicons-minus"></span> চেকআউটে এসে চলে যাওয়া কাস্টমার হারিয়ে যায়</li>
					</ul>
				</div>
				<div class="fos-calc" data-price="<?php echo esc_attr( $six_pm ); ?>">
					<h3>ক্ষতির হিসাব</h3>
					<label>দিনে গড়ে কয়টা অর্ডার আসে? <output data-for="orders">30</output><input type="range" min="5" max="300" step="5" value="30" data-k="orders"></label>
					<label>কত শতাংশ ফেক বা ফেরত হয়? <output data-for="pct">15%</output><input type="range" min="1" max="50" value="15" data-k="pct"></label>
					<label>প্রতি ফেক অর্ডারে খরচ (৳) <output data-for="cost">150</output><input type="range" min="50" max="400" step="10" value="150" data-k="cost"></label>
					<div class="fos-calc-out">
						<div><small>মাসে আনুমানিক ক্ষতি</small><strong data-out="loss">৳20,250</strong></div>
						<div><small>Fake Order Shield (6 মাসের প্যাকেজে)</small><strong>৳<?php echo esc_html( number_format_i18n( $six_pm ) ); ?>/মাস</strong></div>
					</div>
					<p class="fos-calc-note" data-out="note"></p>
					<a class="fos-btn fos-btn-primary fos-btn-block" href="<?php echo esc_url( $signup ); ?>">ফ্রি ট্রায়ালে নিজেই দেখুন</a>
				</div>
			</div>
		</section>

		<section class="fos-section" id="features">
			<div class="fos-container">
				<span class="fos-eyebrow fos-center">সব ফিচার, সব প্যাকেজে</span>
				<h2 class="fos-h2">আটকায়, চেনায়, ফেরত আনে</h2>
				<p class="fos-sub">একটা প্লাগিনে পুরো সুরক্ষা। আলাদা অ্যাপ, আলাদা খরচ নেই।</p>
				<div class="fos-pillars">
					<?php foreach ( $pillars as $p ) : ?>
						<div class="fos-pillar">
							<div class="fos-pillar-head"><span class="fos-ico2 dashicons dashicons-<?php echo esc_attr( $p[0] ); ?>"></span><div><h3><?php echo esc_html( $p[1] ); ?></h3><p><?php echo esc_html( $p[2] ); ?></p></div></div>
							<ul>
								<?php foreach ( $p[3] as $f ) : ?>
									<li><span class="dashicons dashicons-<?php echo esc_attr( $f[0] ); ?>"></span><div><b><?php echo esc_html( $f[1] ); ?></b><small><?php echo esc_html( $f[2] ); ?></small></div></li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</section>

		<section class="fos-section fos-dark" id="how">
			<div class="fos-container">
				<span class="fos-eyebrow fos-center fos-eyebrow-light">শুরু করা সহজ</span>
				<h2 class="fos-h2 fos-light">3 ধাপে চালু, আজকেই</h2>
				<div class="fos-steps2">
					<div><span>1</span><h3>অ্যাকাউন্ট খুলুন</h3><p>ইমেইল আর ডোমেইন দিন। সাথে সাথে <?php echo esc_html( $trial ); ?> দিনের ফ্রি ট্রায়াল কী পাবেন।</p></div>
					<div><span>2</span><h3>প্লাগিন অ্যাক্টিভেট করুন</h3><p>প্লাগিন ইনস্টল করে কী দিন, একটা প্রোটেকশন লেভেল বেছে নিন। ব্যস, চালু।</p></div>
					<div><span>3</span><h3>পছন্দ হলে প্যাকেজ নিন</h3><p>bKash বা Nagad-এ পেমেন্ট করুন। একই কী-তে চলতে থাকবে, কিছু বদলাতে হবে না।</p></div>
				</div>
				<div class="fos-center"><a class="fos-btn fos-btn-glow" href="<?php echo esc_url( $signup ); ?>">এখনই ফ্রি শুরু করুন</a></div>
			</div>
		</section>

		<section class="fos-section" id="pricing">
			<div class="fos-container">
				<span class="fos-eyebrow fos-center">সহজ দাম</span>
				<h2 class="fos-h2">দিনে মাত্র ৳<?php echo esc_html( number_format_i18n( $per_day ) ); ?> থেকে পুরো সুরক্ষা</h2>
				<p class="fos-sub">প্রতিটি লাইসেন্স একটি ডোমেইনের জন্য। লম্বা প্যাকেজে সাশ্রয় বেশি। আগে <?php echo esc_html( $trial ); ?> দিন ফ্রি চালিয়ে দেখুন।</p>
				<div class="fos-prices2">
					<?php
					foreach ( $packages as $m => $price ) :
						$save = ( $monthly > 0 && $m > 1 ) ? (int) round( ( 1 - $price / ( $monthly * $m ) ) * 100 ) : 0;
						$pop  = 6 === $m;
						?>
						<div class="fos-plan<?php echo $pop ? ' is-pop' : ''; ?>">
							<?php if ( $pop ) : ?><span class="fos-plan-tag">প্রস্তাবিত</span><?php endif; ?>
							<h3><?php echo esc_html( $labels[ $m ] ); ?></h3>
							<div class="fos-plan-price"><?php echo esc_html( self::tk( $price ) ); ?></div>
							<p class="fos-plan-per">মাসে <?php echo esc_html( self::tk( round( $price / $m ) ) ); ?></p>
							<?php if ( $save > 0 ) : ?><span class="fos-save"><?php echo esc_html( $save ); ?>% সাশ্রয়</span><?php else : ?><span class="fos-save is-empty">&nbsp;</span><?php endif; ?>
							<ul><li>সব ফিচার</li><li>1টি ডোমেইন</li><li>আপডেট ও সাপোর্ট</li></ul>
							<a class="fos-btn <?php echo $pop ? 'fos-btn-primary' : 'fos-btn-ghost'; ?> fos-btn-block" href="<?php echo esc_url( add_query_arg( 'months', $m, $account ) ); ?>#buy"><?php echo esc_html( $labels[ $m ] ); ?> নিন</a>
						</div>
					<?php endforeach; ?>
				</div>
				<p class="fos-pay-note"><span class="dashicons dashicons-lock"></span> bKash ও Nagad-এ পেমেন্ট · প্রথমে <?php echo esc_html( $trial ); ?> দিন ফ্রি, পছন্দ না হলে কিছুই দিতে হবে না</p>
			</div>
		</section>

		<section class="fos-section fos-soft">
			<div class="fos-container fos-faq">
				<h2 class="fos-h2">প্রশ্ন ও উত্তর</h2>
				<details open><summary>ফ্রি ট্রায়ালে কী কী পাব?</summary><p>অ্যাকাউন্ট খোলার সময় ডোমেইন দিলে একটি ট্রায়াল কী পাবেন। <?php echo esc_html( $trial ); ?> দিন সব ফিচার চালু থাকে, কোনো পেমেন্ট লাগে না। এরপর প্যাকেজ না নিলে প্রোটেকশন বন্ধ হয়, কিন্তু সেটিংস ও ডাটা মুছে যায় না।</p></details>
				<details><summary>আসল কাস্টমার কি আটকে যাবে?</summary><p>না, যদি সেটিংস ঠিক থাকে। Balanced লেভেল আসল কাস্টমারকে ঝামেলা ছাড়াই অর্ডার করতে দেয়। কেউ ভুলে আটকালে তাকে আপনার নম্বরে কল করার বাটন দেখানো হয়।</p></details>
				<details><summary>পেমেন্ট কীভাবে করব?</summary><p>bKash বা Nagad-এ Send Money করে অ্যাকাউন্ট পেজে নম্বর ও TrxID দিন। যাচাইয়ের পর লাইসেন্স চালু হয়, কী আপনার অ্যাকাউন্টেই দেখবেন।</p></details>
				<details><summary>এক লাইসেন্স কয়টা সাইটে চলবে?</summary><p>একটি লাইসেন্স একটি ডোমেইনের জন্য। আরেকটি সাইটে চালাতে আরেকটি প্যাকেজ নিন।</p></details>
				<details><summary>মেয়াদ শেষ হলে কী হবে?</summary><p>প্রোটেকশন বন্ধ হবে, ডাটা থাকবে। রিনিউ করলে আগের মতো সব চালু। মেয়াদ থাকতেই রিনিউ করলে বাকি দিনগুলো যোগ হয়।</p></details>
				<details><summary>সাইট কি স্লো হবে?</summary><p>না। চেক হয় শুধু অর্ডার দেওয়ার মুহূর্তে। সেশন রিপ্লে চাইলে শুধু চেকআউট পেজে চালু রাখতে পারেন।</p></details>
			</div>
		</section>

		<section class="fos-final2">
			<div class="fos-container">
				<h2>পরের ফেক অর্ডারটা আসার আগেই চালু করুন</h2>
				<p><?php echo esc_html( $trial ); ?> দিন ফ্রি · কোনো পেমেন্ট লাগবে না · 2 মিনিটে সেটআপ</p>
				<a class="fos-btn fos-btn-light" href="<?php echo esc_url( $signup ); ?>">ফ্রি ট্রায়াল শুরু করুন</a>
				<?php if ( $support ) : ?><p class="fos-final-help">ইনস্টলে সাহায্য লাগলে <a class="fos-wa" href="<?php echo esc_url( self::wa_link() ); ?>" target="_blank" rel="noopener"><span class="dashicons dashicons-whatsapp"></span> WhatsApp-এ মেসেজ করুন: <?php echo esc_html( $support ); ?></a></p><?php endif; ?>
			</div>
		</section>

		<div class="fos-sticky-cta"><a class="fos-btn fos-btn-primary fos-btn-block" href="<?php echo esc_url( $signup ); ?>"><?php echo esc_html( $trial ); ?> দিন ফ্রি ট্রায়াল</a></div>

		<script>
		(function () {
			var c = document.querySelector('.fos-calc');
			if (!c) { return; }
			var bn = function (n) { return Math.round(n).toLocaleString('en-IN'); };
			var run = function () {
				var v = {};
				c.querySelectorAll('input[data-k]').forEach(function (i) { v[i.dataset.k] = +i.value; c.querySelector('output[data-for="' + i.dataset.k + '"]').textContent = i.value + ( 'pct' === i.dataset.k ? '%' : '' ); });
				var loss = v.orders * 30 * v.pct / 100 * v.cost, price = +c.dataset.price || 0;
				c.querySelector('[data-out="loss"]').textContent = '৳' + bn(loss);
				c.querySelector('[data-out="note"]').textContent = price && loss > price ? 'অর্ধেক ফেক অর্ডার আটকালেও মাসে প্রায় ৳' + bn(loss / 2 - price) + ' বাঁচে।' : '';
			};
			c.addEventListener('input', run);
			run();
		})();
		</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * Account page.
	 *
	 * @return string
	 */
	public static function account() {
		ob_start();
		echo '<div class="fos-container fos-account">';
		if ( self::$msg ) {
			echo '<div class="fos-alert fos-alert-' . esc_attr( self::$msg[0] ) . '">' . esc_html( self::$msg[1] ) . '</div>';
		}
		$flag = isset( $_GET['fos'] ) ? sanitize_key( $_GET['fos'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'welcome' === $flag ) {
			echo '<div class="fos-alert fos-alert-success">' . esc_html__( 'Account created. Start your free trial below to get a license key.', 'fos-license-server' ) . '</div>';
		} elseif ( 'trial' === $flag ) {
			echo '<div class="fos-alert fos-alert-success">' . esc_html( sprintf( 'আপনার %s দিনের ফ্রি ট্রায়াল চালু! নিচের লাইসেন্স কী কপি করুন, প্লাগিন ডাউনলোড করে ইনস্টল করুন, তারপর Fake Order Shield → Settings → License-এ কী দিয়ে Activate করুন।', FOS_Store::opt( 'trial_days' ) ) ) . '</div>';
		} elseif ( 'ordered' === $flag ) {
			echo '<div class="fos-alert fos-alert-success">' . esc_html__( 'Order received! We will check your payment and activate your license soon. Your license key will appear here on this page.', 'fos-license-server' ) . '</div>';
		}

		if ( ! is_user_logged_in() ) {
			self::auth_forms();
		} else {
			self::dashboard();
		}
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * Register + login.
	 */
	private static function auth_forms() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- refill values after a failed post.
		$v = function ( $k ) {
			return isset( $_POST[ $k ] ) ? esc_attr( sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) ) : '';
		};
		// phpcs:enable
		?>
		<div class="fos-auth">
			<div class="fos-card" id="register">
				<h2>নতুন অ্যাকাউন্ট</h2>
				<form method="post" class="fos-form">
					<input type="hidden" name="fos_action" value="register">
					<?php wp_nonce_field( 'fos_register', '_fosnonce' ); ?>
					<label>নাম<input type="text" name="name" value="<?php echo $v( 'name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" required autocomplete="name"></label>
					<label>ইমেইল<input type="email" name="email" value="<?php echo $v( 'email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" required autocomplete="email"></label>
					<label>মোবাইল<input type="tel" name="phone" value="<?php echo $v( 'phone' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" placeholder="01XXXXXXXXX" autocomplete="tel"></label>
					<label>পাসওয়ার্ড<input type="password" name="password" minlength="8" required autocomplete="new-password"></label>
					<label>ওয়েবসাইটের ডোমেইন <small class="fos-muted">(<?php echo esc_html( FOS_Store::opt( 'trial_days' ) ); ?> দিন ফ্রি ট্রায়ালের জন্য)</small><input type="text" name="domain" value="<?php echo $v( 'domain' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" placeholder="myshop.com"></label>
					<button class="fos-btn fos-btn-primary" type="submit">অ্যাকাউন্ট খুলুন ও ফ্রি ট্রায়াল নিন</button>
				</form>
			</div>
			<div class="fos-card">
				<h2>লগইন</h2>
				<?php
				wp_login_form(
					array(
						'redirect'       => FOS_Store::account_url(),
						'label_username' => 'ইমেইল',
						'label_password' => 'পাসওয়ার্ড',
						'label_remember' => 'মনে রাখুন',
						'label_log_in'   => 'লগইন',
					)
				);
				?>
				<p><a href="<?php echo esc_url( wp_lostpassword_url( FOS_Store::account_url() ) ); ?>">পাসওয়ার্ড ভুলে গেছেন?</a></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Logged-in customer area.
	 */
	private static function dashboard() {
		$user     = wp_get_current_user();
		$rows     = FOS_Store::for_user( $user->ID );
		$packages = FOS_Store::packages();
		$download = FOS_Store::opt( 'download_url' );
		$labels   = array(
			'active'   => 'চালু',
			'expired'  => 'মেয়াদ শেষ',
			'pending'  => 'যাচাই চলছে',
			'rejected' => 'বাতিল',
			'revoked'  => 'বন্ধ',
			'applied'  => 'রিনিউ হয়েছে',
			'trial'    => 'ফ্রি ট্রায়াল',
		);
		// phpcs:disable WordPress.Security.NonceVerification
		$renew  = isset( $_GET['renew'] ) ? absint( $_GET['renew'] ) : 0;
		$months = isset( $_REQUEST['months'] ) ? absint( $_REQUEST['months'] ) : 6;
		// phpcs:enable
		$renew_row = $renew ? FOS_Store::get( $renew ) : null;
		if ( $renew_row && (int) $renew_row->user_id !== $user->ID ) {
			$renew_row = null;
		}
		?>
		<div class="fos-acc-head">
			<div><h1>স্বাগতম, <?php echo esc_html( $user->display_name ); ?></h1><p><?php echo esc_html( $user->user_email ); ?> · <a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">লগআউট</a></p></div>
			<?php if ( $download ) : ?>
				<a class="fos-btn fos-btn-primary" href="<?php echo esc_url( $download ); ?>">প্লাগিন ডাউনলোড</a>
			<?php endif; ?>
		</div>

		<?php
		$had_trial = false;
		foreach ( $rows as $r ) {
			$had_trial = $had_trial || ( 'trial' === $r->method );
		}
		if ( ! $had_trial ) :
			?>
			<div class="fos-card fos-trial-card">
				<h2><?php echo esc_html( FOS_Store::opt( 'trial_days' ) ); ?> দিন ফ্রি ট্রায়াল</h2>
				<p class="fos-muted">আপনার ওয়েবসাইটের ডোমেইন দিন, সাথে সাথে একটি ট্রায়াল লাইসেন্স কী পাবেন। সব ফিচার চালু থাকবে, কোনো পেমেন্ট লাগবে না।</p>
				<form method="post" class="fos-form fos-inline">
					<input type="hidden" name="fos_action" value="trial">
					<?php wp_nonce_field( 'fos_trial', '_fosnonce' ); ?>
					<input type="text" name="domain" placeholder="myshop.com" required aria-label="ডোমেইন">
					<button class="fos-btn fos-btn-primary" type="submit">ফ্রি ট্রায়াল শুরু করুন</button>
				</form>
			</div>
		<?php endif; ?>

		<div class="fos-card">
			<h2>আমার লাইসেন্স</h2>
			<?php if ( ! $rows ) : ?>
				<p class="fos-muted">এখনো কোনো লাইসেন্স নেই। উপরের কার্ড থেকে <?php echo esc_html( FOS_Store::opt( 'trial_days' ) ); ?> দিনের ফ্রি ট্রায়াল কী নিন, অথবা নিচে থেকে প্যাকেজ কিনুন।</p>
			<?php else : ?>
				<div class="fos-table-wrap"><table class="fos-table">
					<thead><tr><th>ডোমেইন</th><th>লাইসেন্স কী</th><th>প্যাকেজ</th><th>অবস্থা</th><th>মেয়াদ</th><th></th></tr></thead>
					<tbody>
					<?php
					foreach ( $rows as $r ) :
						$st = FOS_Store::status( $r );
						?>
						<tr>
							<td><?php echo esc_html( $r->domain ); ?></td>
							<td><?php echo ( 'active' === $st || 'expired' === $st ) && $r->license_key ? '<code class="fos-key">' . esc_html( $r->license_key ) . '</code>' : ( $r->renewal_of ? '<span class="fos-muted">রিনিউ অর্ডার</span>' : '<span class="fos-muted">অনুমোদনের পর</span>' ); ?></td>
							<td><?php echo 'trial' === $r->method ? esc_html( FOS_Store::opt( 'trial_days' ) . ' দিন · ফ্রি' ) : esc_html( $r->months . ' মাস · ' . self::tk( $r->amount ) ); ?></td>
							<td><span class="fos-pill fos-pill-<?php echo esc_attr( $st ); ?>"><?php echo esc_html( ( 'trial' === $r->method && 'active' === $st ) ? $labels['trial'] : ( isset( $labels[ $st ] ) ? $labels[ $st ] : $st ) ); ?></span></td>
							<td><?php echo $r->expires_at ? esc_html( get_date_from_gmt( $r->expires_at, 'd M Y' ) ) : '—'; ?></td>
							<td><?php if ( ! $r->renewal_of && in_array( $st, array( 'active', 'expired' ), true ) ) : ?><a href="<?php echo esc_url( add_query_arg( 'renew', $r->id, FOS_Store::account_url() ) ); ?>#buy"><?php echo 'trial' === $r->method ? 'প্যাকেজ নিন' : 'রিনিউ'; ?></a><?php endif; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
			<?php endif; ?>
		</div>

		<div class="fos-card" id="buy">
			<h2><?php echo $renew_row ? esc_html( ( 'trial' === $renew_row->method ? 'প্যাকেজ নিন: ' : 'রিনিউ: ' ) . $renew_row->domain ) : 'প্যাকেজ কিনুন'; ?></h2>
			<?php if ( $renew_row && 'trial' === $renew_row->method ) : ?>
				<p class="fos-muted">পেমেন্ট অনুমোদন হলে একই লাইসেন্স কী-তে প্যাকেজ চালু হবে, প্লাগিনে কিছু বদলাতে হবে না।</p>
			<?php endif; ?>
			<form method="post" class="fos-form">
				<input type="hidden" name="fos_action" value="order">
				<?php wp_nonce_field( 'fos_order', '_fosnonce' ); ?>
				<?php if ( $renew_row ) : ?>
					<input type="hidden" name="renewal_of" value="<?php echo esc_attr( $renew_row->id ); ?>">
				<?php endif; ?>
				<div class="fos-pack-pick">
					<?php foreach ( $packages as $m => $p ) : ?>
						<label class="fos-pack"><input type="radio" name="months" value="<?php echo esc_attr( $m ); ?>" <?php checked( $months, $m ); ?> required><span><b><?php echo esc_html( $m . ' মাস' ); ?></b><?php echo esc_html( self::tk( $p ) ); ?></span></label>
					<?php endforeach; ?>
				</div>
				<?php if ( ! $renew_row ) : ?>
					<label>আপনার ওয়েবসাইটের ডোমেইন<input type="text" name="domain" placeholder="myshop.com" required></label>
				<?php endif; ?>
				<div class="fos-pay">
					<p><b>পেমেন্ট:</b> নিচের নম্বরে <b>Send Money</b> করুন, তারপর যে নম্বর থেকে পাঠিয়েছেন আর TrxID দিন।</p>
					<ul>
						<?php if ( FOS_Store::opt( 'bkash' ) ) : ?><li>bKash: <b><?php echo esc_html( FOS_Store::opt( 'bkash' ) ); ?></b></li><?php endif; ?>
						<?php if ( FOS_Store::opt( 'nagad' ) ) : ?><li>Nagad: <b><?php echo esc_html( FOS_Store::opt( 'nagad' ) ); ?></b></li><?php endif; ?>
					</ul>
				</div>
				<div class="fos-row">
					<label>মাধ্যম<select name="method" required><option value="bkash">bKash</option><option value="nagad">Nagad</option></select></label>
					<label>যে নম্বর থেকে পাঠিয়েছেন<input type="tel" name="sender" placeholder="01XXXXXXXXX" required></label>
					<label>TrxID<input type="text" name="trx_id" placeholder="8N7A6D5C4B" required></label>
				</div>
				<button class="fos-btn fos-btn-primary" type="submit">অর্ডার করুন</button>
			</form>
			<?php if ( FOS_Store::opt( 'support' ) ) : ?>
				<p class="fos-muted">প্লাগিন ইনস্টল বা লাইসেন্সে সমস্যা হলে <a class="fos-wa" href="<?php echo esc_url( self::wa_link() ); ?>" target="_blank" rel="noopener"><span class="dashicons dashicons-whatsapp"></span> WhatsApp-এ মেসেজ করুন: <?php echo esc_html( FOS_Store::opt( 'support' ) ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
