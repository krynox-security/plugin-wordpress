<?php
/**
 * Plugin Name:       Krynox Captcha
 * Plugin URI:        https://krynox.net
 * Description:       Privacy-first, proof-of-work CAPTCHA for WordPress — protects login, registration, lost-password and comment forms. No cookies, no puzzles.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Krynox
 * Author URI:        https://krynox.net
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       krynox-captcha
 *
 * @package KrynoxCaptcha
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'KRYNOX_CAPTCHA_VERSION', '0.1.0' );

/**
 * Krynox Captcha plugin.
 */
class Krynox_Captcha {

	const OPT = 'krynox_captcha_options';

	/**
	 * Singleton instance.
	 *
	 * @var Krynox_Captcha|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton.
	 *
	 * @return Krynox_Captcha
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire up hooks.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'settings_link' ) );

		$o = $this->options();
		if ( empty( $o['site_key'] ) || empty( $o['secret_key'] ) ) {
			return; // Not configured — render nothing on the front end.
		}

		add_filter( 'script_loader_tag', array( $this, 'module_tag' ), 10, 3 );

		if ( $o['protect_login'] ) {
			add_action( 'login_enqueue_scripts', array( $this, 'enqueue' ) );
			add_action( 'login_form', array( $this, 'field' ) );
			add_filter( 'authenticate', array( $this, 'verify_login' ), 30, 3 );
		}
		if ( $o['protect_register'] ) {
			add_action( 'login_enqueue_scripts', array( $this, 'enqueue' ) );
			add_action( 'register_form', array( $this, 'field' ) );
			add_filter( 'registration_errors', array( $this, 'verify_register' ), 10, 1 );
		}
		if ( $o['protect_lostpassword'] ) {
			add_action( 'login_enqueue_scripts', array( $this, 'enqueue' ) );
			add_action( 'lostpassword_form', array( $this, 'field' ) );
			add_action( 'lostpassword_post', array( $this, 'verify_lostpassword' ) );
		}
		if ( $o['protect_comments'] ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
			add_action( 'comment_form_after_fields', array( $this, 'field' ) );
			add_action( 'comment_form_logged_in_after', array( $this, 'field' ) );
			add_filter( 'preprocess_comment', array( $this, 'verify_comment' ) );
		}
	}

	/**
	 * Options with defaults.
	 *
	 * @return array
	 */
	public function options() {
		return wp_parse_args(
			get_option( self::OPT, array() ),
			array(
				'site_key'             => '',
				'secret_key'           => '',
				'api_host'             => 'https://api.krynox.net',
				'cdn_host'             => 'https://cdn.krynox.net',
				'protect_login'        => 1,
				'protect_register'     => 1,
				'protect_lostpassword' => 1,
				'protect_comments'     => 1,
			)
		);
	}

	/* -------------------------------------------------------------- front end */

	/**
	 * Enqueue the widget script.
	 */
	public function enqueue() {
		$o = $this->options();
		wp_enqueue_script(
			'krynox-captcha',
			esc_url( $o['cdn_host'] ) . '/widget/krynox-captcha.js',
			array(),
			KRYNOX_CAPTCHA_VERSION,
			true
		);
	}

	/**
	 * Serve the widget script as an ES module.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Script handle.
	 * @param string $src    Script src.
	 * @return string
	 */
	public function module_tag( $tag, $handle, $src ) {
		if ( 'krynox-captcha' === $handle ) {
			return '<script type="module" src="' . esc_url( $src ) . '" async defer></script>' . "\n";
		}
		return $tag;
	}

	/**
	 * Render the <krynox-captcha> element.
	 */
	public function field() {
		$o         = $this->options();
		$challenge = esc_url( $o['api_host'] ) . '/challenge?sitekey=' . rawurlencode( $o['site_key'] );
		echo '<div class="krynox-captcha-field" style="margin:0 0 1em">';
		echo '<krynox-captcha challenge="' . esc_attr( $challenge ) . '"></krynox-captcha>';
		echo '</div>';
	}

	/* ----------------------------------------------------------- verification */

	/**
	 * Read the solved token submitted by the widget.
	 *
	 * @return string
	 */
	private function token() {
		// The widget submits its solution as a hidden "krynox-captcha" field; the
		// token itself is the single-use, server-verified anti-bot proof.
		return isset( $_POST['krynox-captcha'] ) ? sanitize_text_field( wp_unslash( $_POST['krynox-captcha'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Server-to-server verify against /siteverify.
	 *
	 * Retries transient failures (network / 429 / 5xx) with a per-verify idempotency key, so a
	 * retried single-use token replays the first outcome instead of failing. Advanced integrators
	 * can hook `krynox_captcha_verified` to act on the full result (score, risk, reasons, agent,
	 * human) or to force-reject a structurally-valid solution.
	 *
	 * @param string $token Solved token.
	 * @return bool
	 */
	private function verify( $token ) {
		if ( empty( $token ) ) {
			return false;
		}
		$o       = $this->options();
		$retries = 2;
		// A token is single-use, so a retried verify carries an idempotency key.
		$key     = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) );
		$payload = wp_json_encode(
			array(
				'secret'          => $o['secret_key'],
				'response'        => $token,
				'remoteip'        => $this->client_ip(),
				'idempotency_key' => $key,
			)
		);

		$data = null;
		for ( $attempt = 0; $attempt <= $retries; $attempt++ ) {
			$res = wp_remote_post(
				esc_url_raw( $o['api_host'] ) . '/siteverify',
				array(
					'timeout' => 5,
					'headers' => array( 'content-type' => 'application/json' ),
					'body'    => $payload,
				)
			);
			if ( is_wp_error( $res ) ) {
				if ( $attempt < $retries ) {
					usleep( (int) ( min( 1.0, 0.1 * pow( 2, $attempt ) ) * 1000000 ) );
					continue;
				}
				return false;
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			if ( ( 429 === $code || $code >= 500 ) && $attempt < $retries ) {
				usleep( (int) ( min( 1.0, 0.1 * pow( 2, $attempt ) ) * 1000000 ) );
				continue;
			}
			$data = json_decode( wp_remote_retrieve_body( $res ), true );
			break;
		}

		if ( ! is_array( $data ) ) {
			return false;
		}

		$success = ! empty( $data['success'] );

		/**
		 * Filter the boolean verification outcome with the full server result available.
		 *
		 * @param bool  $success Whether the solution verified.
		 * @param array $data    The full /siteverify response (score, risk, reasons, agent, human, …).
		 */
		return (bool) apply_filters( 'krynox_captcha_verified', $success, $data );
	}

	/**
	 * The end-user's IP.
	 *
	 * @return string
	 */
	private function client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Block login when the CAPTCHA fails.
	 *
	 * @param WP_User|WP_Error|null $user     Auth result so far.
	 * @param string                $username Submitted username.
	 * @param string                $password Submitted password.
	 * @return WP_User|WP_Error|null
	 */
	public function verify_login( $user, $username = '', $password = '' ) {
		// Only enforce on the standard wp-login.php form, so application passwords /
		// XML-RPC / REST logins (which never render the widget) aren't broken.
		if ( empty( $_POST['wp-submit'] ) || empty( $username ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return $user;
		}
		if ( ! $this->verify( $this->token() ) ) {
			return new WP_Error( 'krynox_failed', __( '<strong>Error:</strong> CAPTCHA verification failed. Please try again.', 'krynox-captcha' ) );
		}
		return $user;
	}

	/**
	 * Block registration when the CAPTCHA fails.
	 *
	 * @param WP_Error $errors Registration errors.
	 * @return WP_Error
	 */
	public function verify_register( $errors ) {
		if ( ! $this->verify( $this->token() ) ) {
			$errors->add( 'krynox_failed', __( '<strong>Error:</strong> CAPTCHA verification failed. Please try again.', 'krynox-captcha' ) );
		}
		return $errors;
	}

	/**
	 * Block lost-password when the CAPTCHA fails.
	 *
	 * @param WP_Error $errors Lost-password errors.
	 */
	public function verify_lostpassword( $errors ) {
		if ( ! $this->verify( $this->token() ) ) {
			$errors->add( 'krynox_failed', __( '<strong>Error:</strong> CAPTCHA verification failed. Please try again.', 'krynox-captcha' ) );
		}
	}

	/**
	 * Block a comment when the CAPTCHA fails.
	 *
	 * @param array $commentdata Comment data.
	 * @return array
	 */
	public function verify_comment( $commentdata ) {
		if ( ! $this->verify( $this->token() ) ) {
			wp_die(
				esc_html__( 'CAPTCHA verification failed. Please go back and try again.', 'krynox-captcha' ),
				esc_html__( 'Comment blocked', 'krynox-captcha' ),
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}
		return $commentdata;
	}

	/* --------------------------------------------------------------- settings */

	/**
	 * Register the settings page.
	 */
	public function add_settings_page() {
		add_options_page( 'Krynox Captcha', 'Krynox Captcha', 'manage_options', 'krynox-captcha', array( $this, 'render_settings' ) );
	}

	/**
	 * Add a Settings link on the Plugins screen.
	 *
	 * @param array $links Action links.
	 * @return array
	 */
	public function settings_link( $links ) {
		$url = admin_url( 'options-general.php?page=krynox-captcha' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'krynox-captcha' ) . '</a>' );
		return $links;
	}

	/**
	 * Register the option + sanitizer.
	 */
	public function register_settings() {
		register_setting( 'krynox_captcha', self::OPT, array( $this, 'sanitize' ) );
	}

	/**
	 * Sanitize submitted settings.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize( $input ) {
		$out               = array();
		$out['site_key']   = isset( $input['site_key'] ) ? sanitize_text_field( $input['site_key'] ) : '';
		$out['secret_key'] = isset( $input['secret_key'] ) ? sanitize_text_field( $input['secret_key'] ) : '';
		$out['api_host']   = ! empty( $input['api_host'] ) ? esc_url_raw( $input['api_host'] ) : 'https://api.krynox.net';
		$out['cdn_host']   = ! empty( $input['cdn_host'] ) ? esc_url_raw( $input['cdn_host'] ) : 'https://cdn.krynox.net';
		foreach ( array( 'protect_login', 'protect_register', 'protect_lostpassword', 'protect_comments' ) as $k ) {
			$out[ $k ] = empty( $input[ $k ] ) ? 0 : 1;
		}
		return $out;
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings() {
		$o = $this->options();
		?>
		<div class="wrap">
			<h1>Krynox Captcha</h1>
			<p>Privacy-first, proof-of-work CAPTCHA. Get your keys at <a href="https://app.krynox.net" target="_blank" rel="noopener">app.krynox.net</a>.</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'krynox_captcha' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="krynox_site_key">Site key</label></th>
						<td><input name="<?php echo esc_attr( self::OPT ); ?>[site_key]" id="krynox_site_key" type="text" class="regular-text" value="<?php echo esc_attr( $o['site_key'] ); ?>" placeholder="kcpt_live_…"></td>
					</tr>
					<tr>
						<th scope="row"><label for="krynox_secret_key">Secret key</label></th>
						<td><input name="<?php echo esc_attr( self::OPT ); ?>[secret_key]" id="krynox_secret_key" type="password" class="regular-text" value="<?php echo esc_attr( $o['secret_key'] ); ?>" placeholder="kcps_live_…" autocomplete="off"></td>
					</tr>
					<tr>
						<th scope="row">Protect forms</th>
						<td>
							<?php
							$forms = array(
								'protect_login'        => 'Login',
								'protect_register'     => 'Registration',
								'protect_lostpassword' => 'Lost password',
								'protect_comments'     => 'Comments',
							);
							foreach ( $forms as $k => $label ) :
								?>
								<label style="display:block;margin:.2em 0">
									<input type="checkbox" name="<?php echo esc_attr( self::OPT ); ?>[<?php echo esc_attr( $k ); ?>]" value="1" <?php checked( 1, $o[ $k ] ); ?>>
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="krynox_api_host">API host</label></th>
						<td><input name="<?php echo esc_attr( self::OPT ); ?>[api_host]" id="krynox_api_host" type="url" class="regular-text" value="<?php echo esc_attr( $o['api_host'] ); ?>"><p class="description">Self-hosting? Override the data-plane URL.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="krynox_cdn_host">CDN host</label></th>
						<td><input name="<?php echo esc_attr( self::OPT ); ?>[cdn_host]" id="krynox_cdn_host" type="url" class="regular-text" value="<?php echo esc_attr( $o['cdn_host'] ); ?>"></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}

Krynox_Captcha::instance();
