<?php
/**
 * Where the stats files come from: the local file system, or cPanel's UAPI.
 *
 * Both sources read the same files that cPanel generates:
 *   ~/tmp/awstats/awstatsMMYYYY.domain.txt         (AWStats, HTTP)
 *   ~/tmp/awstats/ssl/awstatsMMYYYY.domain.txt     (AWStats, HTTPS)
 *   ~/tmp/webalizer/webalizer.hist, usage_YYYYMM.html     (Webalizer, HTTP)
 *   ~/tmp/webalizerssl/webalizer.hist, usage_YYYYMM.html  (Webalizer, HTTPS)
 *
 * @package CpanelTrafficStats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * File source.
 */
class CTS_Source {

	/**
	 * Settings.
	 *
	 * @var array
	 */
	private $s;

	/**
	 * Cached home dir for the cPanel source.
	 *
	 * @var string|null
	 */
	private $remote_home = null;

	/**
	 * Constructor.
	 *
	 * @param array $settings Plugin settings.
	 */
	public function __construct( array $settings ) {
		$this->s = $settings;
	}

	/**
	 * Is the source configured enough to try?
	 *
	 * @return true|WP_Error
	 */
	public function ready() {
		if ( 'cpanel' === $this->s['source'] ) {
			if ( '' === $this->s['cpanel_host'] || '' === $this->s['cpanel_user'] || '' === $this->s['cpanel_token'] ) {
				return new WP_Error( 'cts_not_configured', __( 'Enter your cPanel server, username and API token in the settings.', 'cpanel-traffic-stats' ) );
			}
			return true;
		}
		if ( '' === $this->local_home() ) {
			return new WP_Error( 'cts_no_home', __( 'Could not find your cPanel home folder. Enter it in the settings (the folder that contains tmp/awstats).', 'cpanel-traffic-stats' ) );
		}
		return true;
	}

	/**
	 * List file names in a directory relative to the cPanel home folder.
	 *
	 * @param string $rel_dir e.g. "tmp/awstats".
	 * @return string[]|WP_Error File names (not paths). Empty if the folder does not exist.
	 */
	public function list_files( $rel_dir ) {
		if ( 'cpanel' === $this->s['source'] ) {
			$home = $this->remote_home();
			if ( is_wp_error( $home ) ) {
				return $home;
			}
			$data = $this->uapi(
				'Fileman',
				'list_files',
				array(
					'dir'          => $home . '/' . $rel_dir,
					'types'        => 'file',
					'include_mime' => 0,
				)
			);
			if ( is_wp_error( $data ) ) {
				// A missing folder is not an error worth showing: there is just no data.
				if ( 'cts_uapi_error' === $data->get_error_code() ) {
					return array();
				}
				return $data;
			}
			$names = array();
			foreach ( (array) $data as $row ) {
				if ( is_array( $row ) && isset( $row['file'] ) ) {
					$names[] = basename( $row['file'] );
				}
			}
			return $names;
		}

		$dir = $this->local_home() . '/' . $rel_dir;
		if ( ! self::is_dir( $dir ) ) {
			return array();
		}
		$names = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $names ) {
			/* translators: %s: folder path */
			return new WP_Error( 'cts_unreadable', sprintf( __( 'Cannot read %s. Check folder permissions.', 'cpanel-traffic-stats' ), $dir ) );
		}
		return array_values(
			array_filter(
				$names,
				static function ( $n ) use ( $dir ) {
					return '.' !== $n[0] && is_file( $dir . '/' . $n );
				}
			)
		);
	}

	/**
	 * Get a file's contents.
	 *
	 * @param string $rel_dir  Directory relative to home.
	 * @param string $filename File name.
	 * @return string|WP_Error
	 */
	public function get_file( $rel_dir, $filename ) {
		$filename = basename( $filename );

		if ( 'cpanel' === $this->s['source'] ) {
			$home = $this->remote_home();
			if ( is_wp_error( $home ) ) {
				return $home;
			}
			$data = $this->uapi(
				'Fileman',
				'get_file_content',
				array(
					'dir'  => $home . '/' . $rel_dir,
					'file' => $filename,
				)
			);
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			return isset( $data['content'] ) ? (string) $data['content'] : '';
		}

		$path = $this->local_home() . '/' . $rel_dir . '/' . $filename;
		if ( ! is_readable( $path ) ) {
			/* translators: %s: file path */
			return new WP_Error( 'cts_unreadable', sprintf( __( 'Cannot read %s.', 'cpanel-traffic-stats' ), $path ) );
		}
		$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $content ) {
			/* translators: %s: file path */
			return new WP_Error( 'cts_unreadable', sprintf( __( 'Cannot read %s.', 'cpanel-traffic-stats' ), $path ) );
		}
		return $content;
	}

	/**
	 * Human description of the source, for messages.
	 *
	 * @return string
	 */
	public function describe() {
		if ( 'cpanel' === $this->s['source'] ) {
			return sprintf( 'cPanel API (%s@%s:%d)', $this->s['cpanel_user'], $this->s['cpanel_host'], $this->s['cpanel_port'] );
		}
		/* translators: %s: folder path */
		return sprintf( __( 'local files in %s', 'cpanel-traffic-stats' ), $this->local_home() );
	}

	/**
	 * The home folder for the local source.
	 *
	 * @return string
	 */
	public function local_home() {
		if ( '' !== $this->s['home_dir'] ) {
			return untrailingslashit( $this->s['home_dir'] );
		}
		return self::detect_home_dir();
	}

	/**
	 * Try to work out the cPanel home folder from where WordPress is installed.
	 * WordPress usually lives in /home/username/public_html[/subdir].
	 *
	 * @return string Empty if not found.
	 */
	public static function detect_home_dir() {
		$dir = untrailingslashit( ABSPATH );
		for ( $i = 0; $i < 5 && strlen( $dir ) > 1; $i++ ) {
			if ( self::is_dir( $dir . '/tmp/awstats' ) || self::is_dir( $dir . '/tmp/webalizer' ) || self::is_dir( $dir . '/tmp/webalizerssl' ) ) {
				return $dir;
			}
			$dir = dirname( $dir );
		}
		return '';
	}

	/**
	 * Is the given path a directory? Silences open_basedir warnings.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private static function is_dir( $path ) {
		return @is_dir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * The account's home folder on the cPanel server.
	 *
	 * @return string|WP_Error
	 */
	private function remote_home() {
		if ( null !== $this->remote_home ) {
			return $this->remote_home;
		}
		$key  = 'cts_home_' . md5( $this->s['cpanel_host'] . '|' . $this->s['cpanel_user'] );
		$home = get_transient( $key );
		if ( is_string( $home ) && '' !== $home ) {
			$this->remote_home = $home;
			return $home;
		}

		$data = $this->uapi( 'Variables', 'get_user_information', array( 'name' => 'home' ) );
		if ( is_wp_error( $data ) && 'cts_uapi_error' !== $data->get_error_code() ) {
			return $data; // Connection or auth problem: report it.
		}
		$home = ( is_array( $data ) && ! empty( $data['home'] ) ) ? (string) $data['home'] : '/home/' . $this->s['cpanel_user'];
		$home = untrailingslashit( $home );

		set_transient( $key, $home, DAY_IN_SECONDS );
		$this->remote_home = $home;
		return $home;
	}

	/**
	 * Call a cPanel UAPI function with API token authentication.
	 *
	 * @param string $module   UAPI module.
	 * @param string $function UAPI function.
	 * @param array  $args     Query arguments.
	 * @return array|WP_Error The "data" member of the response.
	 */
	public function uapi( $module, $function, array $args = array() ) {
		$url = sprintf(
			'https://%s:%d/execute/%s/%s',
			$this->s['cpanel_host'],
			(int) $this->s['cpanel_port'],
			rawurlencode( $module ),
			rawurlencode( $function )
		);
		if ( $args ) {
			$url .= '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 30,
				'sslverify' => (bool) $this->s['verify_ssl'],
				'headers'   => array(
					'Authorization' => 'cpanel ' . $this->s['cpanel_user'] . ':' . $this->s['cpanel_token'],
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'cts_http',
				/* translators: %s: error message */
				sprintf( __( 'Could not connect to cPanel: %s', 'cpanel-traffic-stats' ), $response->get_error_message() )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $code || 403 === $code ) {
			return new WP_Error( 'cts_auth', __( 'cPanel rejected the username or API token.', 'cpanel-traffic-stats' ) );
		}
		if ( $code < 200 || $code >= 300 ) {
			/* translators: %d: HTTP status code */
			return new WP_Error( 'cts_http', sprintf( __( 'cPanel returned HTTP %d.', 'cpanel-traffic-stats' ), $code ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'cts_http', __( 'cPanel returned a response the plugin could not read. Check the server address and port.', 'cpanel-traffic-stats' ) );
		}
		// Some servers wrap UAPI output in a "result" member.
		if ( isset( $body['result'] ) && is_array( $body['result'] ) ) {
			$body = $body['result'];
		}
		if ( empty( $body['status'] ) ) {
			$errors = isset( $body['errors'] ) ? implode( ' ', (array) $body['errors'] ) : __( 'Unknown error.', 'cpanel-traffic-stats' );
			return new WP_Error( 'cts_uapi_error', $errors );
		}
		return isset( $body['data'] ) ? $body['data'] : array();
	}
}
