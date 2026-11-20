<?php
/**
 * Plugin settings: storage, sanitizing and the settings screen.
 *
 * @package CpanelTrafficStats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings.
 */
class CTS_Settings {

	const OPTION = 'cts_settings';
	const GROUP  = 'cts_settings_group';
	const PAGE   = 'cts-settings';

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = $host ? preg_replace( '/^www\./i', '', $host ) : '';

		return array(
			'source'          => 'local',
			'engine'          => 'awstats',
			'domain'          => $host,
			'protocol'        => 'both',
			'home_dir'        => '',
			'cpanel_host'     => $host,
			'cpanel_port'     => 2083,
			'cpanel_user'     => '',
			'cpanel_token'    => '',
			'verify_ssl'      => 1,
			'cache_minutes'   => 60,
			'view_capability' => 'manage_options',
			'show_widget'     => 1,
		);
	}

	/**
	 * Current settings merged with defaults. The API token is returned decrypted.
	 *
	 * @return array
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		$s     = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );

		$s['cpanel_token'] = self::decrypt( $s['cpanel_token'] );
		return $s;
	}

	/**
	 * Register the setting.
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitize the submitted settings.
	 *
	 * @param array $in Raw input.
	 * @return array
	 */
	public static function sanitize( $in ) {
		$in       = is_array( $in ) ? $in : array();
		$defaults = self::defaults();
		$old      = get_option( self::OPTION, array() );
		$old      = is_array( $old ) ? $old : array();
		$out      = array();

		$out['source']   = in_array( $in['source'] ?? '', array( 'local', 'cpanel' ), true ) ? $in['source'] : 'local';
		$out['engine']   = in_array( $in['engine'] ?? '', array( 'awstats', 'webalizer', 'both' ), true ) ? $in['engine'] : 'awstats';
		$out['protocol'] = in_array( $in['protocol'] ?? '', array( 'both', 'http', 'https' ), true ) ? $in['protocol'] : 'both';

		$domain        = strtolower( trim( sanitize_text_field( $in['domain'] ?? '' ) ) );
		$domain        = preg_replace( '#^https?://#', '', $domain );
		$domain        = preg_replace( '#[/:].*$#', '', $domain );
		$out['domain'] = preg_match( '/^[a-z0-9.-]+$/', $domain ) ? $domain : $defaults['domain'];

		$home            = trim( sanitize_text_field( $in['home_dir'] ?? '' ) );
		$out['home_dir'] = '' === $home ? '' : untrailingslashit( $home );

		$host               = strtolower( trim( sanitize_text_field( $in['cpanel_host'] ?? '' ) ) );
		$host               = preg_replace( '#^https?://#', '', $host );
		$host               = preg_replace( '#[/:].*$#', '', $host );
		$out['cpanel_host'] = preg_match( '/^[a-z0-9.-]+$/', $host ) ? $host : '';

		$port               = absint( $in['cpanel_port'] ?? 2083 );
		$out['cpanel_port'] = ( $port > 0 && $port < 65536 ) ? $port : 2083;
		$out['cpanel_user'] = sanitize_user( $in['cpanel_user'] ?? '', true );

		// Keep the stored token when the field is left blank; allow clearing with the checkbox.
		$token = trim( (string) ( $in['cpanel_token'] ?? '' ) );
		if ( ! empty( $in['clear_token'] ) ) {
			$out['cpanel_token'] = '';
		} elseif ( '' === $token || 0 === strpos( $token, 'enc:' ) || 0 === strpos( $token, 'plain:' ) ) {
			// Blank (keep saved) or already encrypted (WordPress can run this callback twice).
			$out['cpanel_token'] = '' === $token ? ( $old['cpanel_token'] ?? '' ) : $token;
		} else {
			$out['cpanel_token'] = self::encrypt( preg_replace( '/[^A-Za-z0-9]/', '', $token ) );
		}

		$out['verify_ssl']    = empty( $in['verify_ssl'] ) ? 0 : 1;
		$out['show_widget']   = empty( $in['show_widget'] ) ? 0 : 1;
		$out['cache_minutes'] = min( 1440, max( 0, absint( $in['cache_minutes'] ?? 60 ) ) );

		$caps                   = array_keys( self::capability_choices() );
		$out['view_capability'] = in_array( $in['view_capability'] ?? '', $caps, true ) ? $in['view_capability'] : 'manage_options';

		CTS_Stats::clear_cache();
		return $out;
	}

	/**
	 * Who may view the stats.
	 *
	 * @return array capability => label
	 */
	public static function capability_choices() {
		return array(
			'manage_options'    => __( 'Administrators only', 'cpanel-traffic-stats' ),
			'edit_others_posts' => __( 'Editors and above', 'cpanel-traffic-stats' ),
			'edit_posts'        => __( 'Authors, contributors and above', 'cpanel-traffic-stats' ),
		);
	}

	/**
	 * Encrypt a secret for storage in the options table.
	 *
	 * @param string $plain Plain text.
	 * @return string
	 */
	public static function encrypt( $plain ) {
		if ( '' === $plain ) {
			return '';
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return 'plain:' . $plain;
		}
		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv  = random_bytes( 16 );
		$enc = openssl_encrypt( $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return 'enc:' . base64_encode( $iv . $enc ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a stored secret.
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( 0 === strpos( $stored, 'plain:' ) ) {
			return substr( $stored, 6 );
		}
		if ( 0 !== strpos( $stored, 'enc:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) < 17 ) {
			return '';
		}
		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$dec = openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
		return false === $dec ? '' : $dec;
	}

	/**
	 * Render the settings screen.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s         = self::get();
		$name      = self::OPTION;
		$has_token = '' !== $s['cpanel_token'];
		$detected  = CTS_Source::detect_home_dir();
		?>
		<div class="wrap cts-wrap">
			<h1><?php esc_html_e( 'cPanel Traffic Stats Settings', 'cpanel-traffic-stats' ); ?></h1>

			<?php settings_errors(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2><?php esc_html_e( 'What to show', 'cpanel-traffic-stats' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cts-domain"><?php esc_html_e( 'Domain', 'cpanel-traffic-stats' ); ?></label></th>
						<td>
							<input type="text" id="cts-domain" class="regular-text" name="<?php echo esc_attr( $name ); ?>[domain]" value="<?php echo esc_attr( $s['domain'] ); ?>" placeholder="example.com" />
							<p class="description"><?php esc_html_e( 'The domain as it appears in cPanel (no www, no https://).', 'cpanel-traffic-stats' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Stats program', 'cpanel-traffic-stats' ); ?></th>
						<td>
							<?php
							$engines = array(
								'awstats'   => __( 'AWStats (recommended: most detail)', 'cpanel-traffic-stats' ),
								'webalizer' => __( 'Webalizer', 'cpanel-traffic-stats' ),
								'both'      => __( 'Both', 'cpanel-traffic-stats' ),
							);
							foreach ( $engines as $val => $label ) {
								printf(
									'<label><input type="radio" name="%1$s[engine]" value="%2$s" %3$s /> %4$s</label><br />',
									esc_attr( $name ),
									esc_attr( $val ),
									checked( $s['engine'], $val, false ),
									esc_html( $label )
								);
							}
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'HTTP / HTTPS', 'cpanel-traffic-stats' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( $name ); ?>[protocol]">
								<option value="both" <?php selected( $s['protocol'], 'both' ); ?>><?php esc_html_e( 'Combine HTTP and HTTPS traffic', 'cpanel-traffic-stats' ); ?></option>
								<option value="https" <?php selected( $s['protocol'], 'https' ); ?>><?php esc_html_e( 'HTTPS only', 'cpanel-traffic-stats' ); ?></option>
								<option value="http" <?php selected( $s['protocol'], 'http' ); ?>><?php esc_html_e( 'HTTP only', 'cpanel-traffic-stats' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'cPanel keeps separate stats for secure (HTTPS) traffic. Most sites today should combine both.', 'cpanel-traffic-stats' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Where to get the stats', 'cpanel-traffic-stats' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Source', 'cpanel-traffic-stats' ); ?></th>
						<td>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[source]" value="local" <?php checked( $s['source'], 'local' ); ?> /> <?php esc_html_e( 'Read the stats files on this server (WordPress runs on the same cPanel account)', 'cpanel-traffic-stats' ); ?></label><br />
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[source]" value="cpanel" <?php checked( $s['source'], 'cpanel' ); ?> /> <?php esc_html_e( 'Connect to cPanel with an API token (works from any server)', 'cpanel-traffic-stats' ); ?></label>
						</td>
					</tr>
					<tr class="cts-local">
						<th scope="row"><label for="cts-home"><?php esc_html_e( 'cPanel home folder', 'cpanel-traffic-stats' ); ?></label></th>
						<td>
							<input type="text" id="cts-home" class="regular-text code" name="<?php echo esc_attr( $name ); ?>[home_dir]" value="<?php echo esc_attr( $s['home_dir'] ); ?>" placeholder="<?php echo esc_attr( $detected ? $detected : '/home/username' ); ?>" />
							<p class="description">
								<?php
								if ( $detected ) {
									/* translators: %s: detected folder path */
									printf( esc_html__( 'Leave blank to use the detected folder: %s', 'cpanel-traffic-stats' ), '<code>' . esc_html( $detected ) . '</code>' );
								} else {
									esc_html_e( 'Could not detect it automatically. Enter the folder that contains tmp/awstats, e.g. /home/username.', 'cpanel-traffic-stats' );
								}
								?>
							</p>
						</td>
					</tr>
					<tr class="cts-cpanel">
						<th scope="row"><label for="cts-host"><?php esc_html_e( 'cPanel server', 'cpanel-traffic-stats' ); ?></label></th>
						<td>
							<input type="text" id="cts-host" class="regular-text" name="<?php echo esc_attr( $name ); ?>[cpanel_host]" value="<?php echo esc_attr( $s['cpanel_host'] ); ?>" placeholder="server.example.com" />
							<label for="cts-port"><?php esc_html_e( 'Port', 'cpanel-traffic-stats' ); ?></label>
							<input type="number" id="cts-port" class="small-text" name="<?php echo esc_attr( $name ); ?>[cpanel_port]" value="<?php echo esc_attr( $s['cpanel_port'] ); ?>" />
							<p class="description"><?php esc_html_e( 'The address you use to log in to cPanel, without https:// (the port is usually 2083).', 'cpanel-traffic-stats' ); ?></p>
						</td>
					</tr>
					<tr class="cts-cpanel">
						<th scope="row"><label for="cts-user"><?php esc_html_e( 'cPanel username', 'cpanel-traffic-stats' ); ?></label></th>
						<td><input type="text" id="cts-user" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $name ); ?>[cpanel_user]" value="<?php echo esc_attr( $s['cpanel_user'] ); ?>" /></td>
					</tr>
					<tr class="cts-cpanel">
						<th scope="row"><label for="cts-token"><?php esc_html_e( 'API token', 'cpanel-traffic-stats' ); ?></label></th>
						<td>
							<input type="password" id="cts-token" class="regular-text code" autocomplete="new-password" name="<?php echo esc_attr( $name ); ?>[cpanel_token]" value="" placeholder="<?php echo $has_token ? esc_attr__( 'Saved (leave blank to keep)', 'cpanel-traffic-stats' ) : ''; ?>" />
							<?php if ( $has_token ) : ?>
								<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[clear_token]" value="1" /> <?php esc_html_e( 'Remove saved token', 'cpanel-traffic-stats' ); ?></label>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Create one in cPanel under Security > Manage API Tokens. The token is stored encrypted.', 'cpanel-traffic-stats' ); ?></p>
						</td>
					</tr>
					<tr class="cts-cpanel">
						<th scope="row"><?php esc_html_e( 'SSL certificate', 'cpanel-traffic-stats' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[verify_ssl]" value="1" <?php checked( $s['verify_ssl'], 1 ); ?> /> <?php esc_html_e( 'Verify the cPanel server\'s SSL certificate (turn off only for self-signed certificates)', 'cpanel-traffic-stats' ); ?></label></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Display', 'cpanel-traffic-stats' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cts-cap"><?php esc_html_e( 'Who can see the stats', 'cpanel-traffic-stats' ); ?></label></th>
						<td>
							<select id="cts-cap" name="<?php echo esc_attr( $name ); ?>[view_capability]">
								<?php foreach ( self::capability_choices() as $cap => $label ) : ?>
									<option value="<?php echo esc_attr( $cap ); ?>" <?php selected( $s['view_capability'], $cap ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Dashboard widget', 'cpanel-traffic-stats' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[show_widget]" value="1" <?php checked( $s['show_widget'], 1 ); ?> /> <?php esc_html_e( 'Show a traffic summary on the main Dashboard', 'cpanel-traffic-stats' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="cts-cache"><?php esc_html_e( 'Cache stats for', 'cpanel-traffic-stats' ); ?></label></th>
						<td>
							<input type="number" id="cts-cache" class="small-text" min="0" max="1440" name="<?php echo esc_attr( $name ); ?>[cache_minutes]" value="<?php echo esc_attr( $s['cache_minutes'] ); ?>" /> <?php esc_html_e( 'minutes', 'cpanel-traffic-stats' ); ?>
							<p class="description"><?php esc_html_e( 'cPanel usually updates stats once a day, so an hour or more is fine.', 'cpanel-traffic-stats' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Test the connection', 'cpanel-traffic-stats' ); ?></h2>
			<p><?php esc_html_e( 'Save your settings first, then check that the plugin can find your stats.', 'cpanel-traffic-stats' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cts_test" />
				<?php wp_nonce_field( 'cts_test' ); ?>
				<?php submit_button( __( 'Run test', 'cpanel-traffic-stats' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php CTS_Admin::render_test_result(); ?>
		</div>
		<?php
	}
}
