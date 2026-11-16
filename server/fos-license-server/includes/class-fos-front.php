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
			wp_safe_redirect( add_query_arg( 'fos', 'welcome', FOS_Store::account_url() ) );
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
	 * Landing page.
	 *
	 * @return string
	 */
	public static function landing() {
		$account  = FOS_Store::account_url();
		$packages = FOS_Store::packages();
		$trial    = (int) FOS_Store::opt( 'trial_days' );
		$labels   = array(
			1  => '১ মাস',
			3  => '৩ মাস',
			6  => '৬ মাস',
			12 => '১২ মাস',
		);
		$features = array(
			array( 'smartphone', 'ফোন নম্বর যাচাই', 'শুধু সঠিক ১১ সংখ্যার বাংলাদেশি মোবাইল নম্বর দিয়ে অর্ডার। বাংলা সংখ্যা ও +88 নিজে থেকেই ঠিক হয়।' ),
			array( 'search', 'ফ্রড চেক', 'কাস্টমারের আগের অর্ডারের কতগুলো ডেলিভারি হয়েছে আর কতগুলো ফেরত এসেছে, অর্ডার কনফার্মের আগেই দেখুন।' ),
			array( 'dismiss', 'ব্লক লিস্ট', 'ফেক কাস্টমারের ফোন, IP, ডিভাইস বা ইমেইল ব্লক করুন। একই ডিভাইসের অন্য ব্রাউজারও আটকে যায়।' ),
			array( 'performance', 'অটো ব্লক ও রিপিট ব্লকার', 'অল্প সময়ে অনেক অর্ডার বা একই নম্বর থেকে বারবার অর্ডার নিজে থেকেই আটকায়।' ),
			array( 'filter', 'স্মার্ট অর্ডার ফিল্টার', 'যাদের ডেলিভারি সফলতার হার কম, তাদের অর্ডার চেকআউটেই থেমে যায়।' ),
			array( 'admin-site-alt3', 'VPN ও দেশ ব্লক', 'VPN বা বিদেশ থেকে আসা সন্দেহজনক অর্ডার বন্ধ করুন।' ),
			array( 'cart', 'ইনকমপ্লিট অর্ডার', 'যারা চেকআউটে তথ্য দিয়ে চলে গেছে, তাদের নম্বর পান। কল করে অর্ডার নিন।' ),
			array( 'visibility', 'লাইভ ভিজিটর ও সেশন রিপ্লে', 'কে এখন সাইটে আছে দেখুন, ভিজিটর কী করেছে ভিডিওর মতো দেখুন।' ),
			array( 'car', 'Steadfast ও Pathao', 'এক ক্লিকে পার্সেল পাঠান, ডেলিভারি হলে অর্ডার স্ট্যাটাস নিজে থেকে বদলায়।' ),
		);
		ob_start();
		?>
		<section class="fos-hero">
			<div class="fos-container fos-hero-grid">
				<div>
					<span class="fos-badge">WooCommerce প্লাগিন · বাংলাদেশের জন্য</span>
					<h1>ফেক অর্ডারে আর ডেলিভারি চার্জ নষ্ট নয়</h1>
					<p class="fos-lead">Fake Order Shield চেকআউটেই ফেক ও ঝুঁকিপূর্ণ অর্ডার আটকে দেয়। ফ্রড চেক, ব্লক লিস্ট, ইনকমপ্লিট অর্ডার আর কুরিয়ার, সব এক প্লাগিনে।</p>
					<div class="fos-cta">
						<a class="fos-btn fos-btn-primary" href="<?php echo esc_url( $account ); ?>"><?php echo esc_html( $trial ); ?> দিন ফ্রি ব্যবহার করুন</a>
						<a class="fos-btn fos-btn-ghost" href="#pricing">প্যাকেজ দেখুন</a>
					</div>
					<p class="fos-note">কার্ড লাগবে না · ইনস্টলের পর <?php echo esc_html( $trial ); ?> দিন সব ফিচার ফ্রি</p>
				</div>
				<div class="fos-hero-card" aria-hidden="true">
					<div class="fos-mock-head"><span></span><span></span><span></span></div>
					<div class="fos-mock-row fos-bad"><strong>অর্ডার আটকানো হয়েছে</strong><small>017XXXXXXXX · ডেলিভারি হার ২০%</small></div>
					<div class="fos-mock-row fos-good"><strong>অর্ডার গ্রহণ</strong><small>018XXXXXXXX · ডেলিভারি হার ১০০%</small></div>
					<div class="fos-mock-row fos-warn"><strong>ইনকমপ্লিট অর্ডার</strong><small>019XXXXXXXX · ৳৮৯৯ · কল করুন</small></div>
					<div class="fos-mock-stat"><span>আজ আটকানো</span><b>১২</b></div>
				</div>
			</div>
		</section>

		<section class="fos-section" id="features">
			<div class="fos-container">
				<h2 class="fos-h2">যা যা পাচ্ছেন</h2>
				<p class="fos-sub">সব ফিচার সব প্যাকেজে। কোনো লুকানো চার্জ নেই।</p>
				<div class="fos-features">
					<?php foreach ( $features as $f ) : ?>
						<div class="fos-feature">
							<span class="fos-ico dashicons dashicons-<?php echo esc_attr( $f[0] ); ?>"></span>
							<h3><?php echo esc_html( $f[1] ); ?></h3>
							<p><?php echo esc_html( $f[2] ); ?></p>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</section>

		<section class="fos-section fos-soft">
			<div class="fos-container">
				<h2 class="fos-h2">কীভাবে শুরু করবেন</h2>
				<ol class="fos-steps">
					<li><b>অ্যাকাউন্ট খুলুন</b><span>ইমেইল দিয়ে ১ মিনিটে অ্যাকাউন্ট, প্লাগিন ডাউনলোড করুন।</span></li>
					<li><b><?php echo esc_html( $trial ); ?> দিন ফ্রি</b><span>ইনস্টল করলেই সব ফিচার চালু, কোনো কী লাগবে না।</span></li>
					<li><b>প্যাকেজ নিন</b><span>bKash বা Nagad-এ পেমেন্ট করে TrxID দিন।</span></li>
					<li><b>লাইসেন্স অ্যাক্টিভেট</b><span>অনুমোদনের পর অ্যাকাউন্ট থেকে কী কপি করে প্লাগিনে পেস্ট করুন।</span></li>
				</ol>
			</div>
		</section>

		<section class="fos-section" id="pricing">
			<div class="fos-container">
				<h2 class="fos-h2">প্যাকেজ</h2>
				<p class="fos-sub">প্রতিটি লাইসেন্স একটি ডোমেইনের জন্য। লম্বা প্যাকেজে খরচ কম।</p>
				<div class="fos-prices">
					<?php
					$monthly = $packages[1];
					foreach ( $packages as $m => $price ) :
						$save = ( $monthly > 0 && $m > 1 ) ? (int) round( ( 1 - $price / ( $monthly * $m ) ) * 100 ) : 0;
						?>
						<div class="fos-price<?php echo 6 === $m ? ' is-popular' : ''; ?>">
							<?php if ( 6 === $m ) : ?><span class="fos-pop">জনপ্রিয়</span><?php endif; ?>
							<h3><?php echo esc_html( $labels[ $m ] ); ?></h3>
							<div class="fos-amount"><?php echo esc_html( self::tk( $price ) ); ?></div>
							<p class="fos-per"><?php echo esc_html( sprintf( 'মাসে %s', self::tk( round( $price / $m ) ) ) ); ?><?php echo $save > 0 ? ' · <b>' . esc_html( $save ) . '% সাশ্রয়</b>' : ''; ?></p>
							<ul><li>সব ফিচার</li><li>১টি ডোমেইন</li><li>আপডেট ও সাপোর্ট</li></ul>
							<a class="fos-btn <?php echo 6 === $m ? 'fos-btn-primary' : 'fos-btn-ghost'; ?>" href="<?php echo esc_url( add_query_arg( 'months', $m, $account ) ); ?>#buy">কিনুন</a>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</section>

		<section class="fos-section fos-soft">
			<div class="fos-container fos-faq">
				<h2 class="fos-h2">প্রশ্ন ও উত্তর</h2>
				<details><summary>ফ্রি ট্রায়ালে কী কী পাব?</summary><p>ইনস্টলের পর <?php echo esc_html( $trial ); ?> দিন সব ফিচার চালু থাকে। এরপর লাইসেন্স অ্যাক্টিভেট না করলে প্রোটেকশন বন্ধ হয়, কিন্তু আপনার সেটিংস ও ডাটা মুছে যায় না।</p></details>
				<details><summary>পেমেন্ট কীভাবে করব?</summary><p>bKash বা Nagad-এ Send Money করে অ্যাকাউন্ট পেজে নম্বর ও TrxID দিন। যাচাই করে সাধারণত কয়েক ঘণ্টার মধ্যে লাইসেন্স চালু হয়। লাইসেন্স কী আপনার অ্যাকাউন্টে দেখতে পাবেন।</p></details>
				<details><summary>এক লাইসেন্স কয়টা সাইটে চলবে?</summary><p>একটি লাইসেন্স একটি ডোমেইনের জন্য। আরেকটি সাইটে চালাতে আরেকটি প্যাকেজ নিন।</p></details>
				<details><summary>মেয়াদ শেষ হলে কী হবে?</summary><p>প্রোটেকশন বন্ধ হবে, ডাটা থাকবে। রিনিউ করলে আগের মতো সব চালু হয়ে যাবে। মেয়াদ থাকা অবস্থায় রিনিউ করলে বাকি দিনগুলো যোগ হয়।</p></details>
				<details><summary>WooCommerce লাগবে?</summary><p>হ্যাঁ, Fake Order Shield WooCommerce স্টোরের জন্য বানানো।</p></details>
			</div>
		</section>

		<section class="fos-final">
			<div class="fos-container">
				<h2>আজই ফেক অর্ডার বন্ধ করুন</h2>
				<a class="fos-btn fos-btn-light" href="<?php echo esc_url( $account ); ?>">ফ্রি শুরু করুন</a>
			</div>
		</section>
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
			echo '<div class="fos-alert fos-alert-success">' . esc_html__( 'Account created. Download the plugin below — you get a free trial as soon as you install it.', 'fos-license-server' ) . '</div>';
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
			<div class="fos-card">
				<h2>নতুন অ্যাকাউন্ট</h2>
				<form method="post" class="fos-form">
					<input type="hidden" name="fos_action" value="register">
					<?php wp_nonce_field( 'fos_register', '_fosnonce' ); ?>
					<label>নাম<input type="text" name="name" value="<?php echo $v( 'name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" required autocomplete="name"></label>
					<label>ইমেইল<input type="email" name="email" value="<?php echo $v( 'email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" required autocomplete="email"></label>
					<label>মোবাইল<input type="tel" name="phone" value="<?php echo $v( 'phone' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" placeholder="01XXXXXXXXX" autocomplete="tel"></label>
					<label>পাসওয়ার্ড<input type="password" name="password" minlength="8" required autocomplete="new-password"></label>
					<button class="fos-btn fos-btn-primary" type="submit">অ্যাকাউন্ট খুলুন</button>
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

		<div class="fos-card">
			<h2>আমার লাইসেন্স</h2>
			<?php if ( ! $rows ) : ?>
				<p class="fos-muted">এখনো কোনো প্যাকেজ নেই। প্লাগিন ইনস্টল করলে <?php echo esc_html( FOS_Store::opt( 'trial_days' ) ); ?> দিন ফ্রি চলবে, তারপর নিচে থেকে প্যাকেজ নিন।</p>
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
							<td><?php echo esc_html( $r->months . ' মাস · ' . self::tk( $r->amount ) ); ?></td>
							<td><span class="fos-pill fos-pill-<?php echo esc_attr( $st ); ?>"><?php echo esc_html( isset( $labels[ $st ] ) ? $labels[ $st ] : $st ); ?></span></td>
							<td><?php echo $r->expires_at ? esc_html( get_date_from_gmt( $r->expires_at, 'd M Y' ) ) : '—'; ?></td>
							<td><?php if ( ! $r->renewal_of && in_array( $st, array( 'active', 'expired' ), true ) ) : ?><a href="<?php echo esc_url( add_query_arg( 'renew', $r->id, FOS_Store::account_url() ) ); ?>#buy">রিনিউ</a><?php endif; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
			<?php endif; ?>
		</div>

		<div class="fos-card" id="buy">
			<h2><?php echo $renew_row ? esc_html( 'রিনিউ: ' . $renew_row->domain ) : 'প্যাকেজ কিনুন'; ?></h2>
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
				<p class="fos-muted">প্লাগিন ইনস্টল বা লাইসেন্সে সমস্যা হলে কল করুন: <?php echo esc_html( FOS_Store::opt( 'support' ) ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
