<?php
/**
 * sem_cache_admin
 *
 * @package Semiologic Cache
 **/

class sem_cache_admin {
	/**
	 * Plugin instance.
	 *
	 * @see get_instance()
	 * @type object
	 */
	protected static $instance = null;

	/**
	 * URL to this plugin's directory.
	 *
	 * @type string
	 */
	public $plugin_url = '';

	/**
	 * Path to this plugin's directory.
	 *
	 * @type string
	 */
	public $plugin_path = '';

	/**
	 * Access this plugin’s working instance
	 *
	 * @wp-hook plugins_loaded
	 * @return  object of this class
	 */
	public static function get_instance() {
		null === self::$instance and self::$instance = new self;

		return self::$instance;
	}


	/**
	 * Constructor.
	 *
	 *
	 */

	public function __construct() {
		$this->plugin_url    = plugins_url( '/', __FILE__ );
		$this->plugin_path   = plugin_dir_path( __FILE__ );

		$this->init();
	}


	/**
	 * init()
	 *
	 * @return void
	 **/
	function init() {
		if ( !class_exists('sem_cache_manager') )
			include $this->plugin_path . '/sem-cache-manager.php';

		// more stuff: register actions and filters
		add_action('settings_page_sem-cache', array($this, 'save_options'), 0);
	}

	/**
	 * save_options()
	 *
	 * @return void
	 **/

	static function save_options() {
		if ( ! $_POST || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( function_exists( 'is_super_admin' ) && ! is_super_admin() ) {
			return;
		}

		check_admin_referer( 'sem_cache' );

		$timeout = false;
		switch ( $_POST['action'] ) {
			case 'clean':
				$timeout = cache_timeout;

			case 'flush':
				if ( function_exists( 'is_multisite' ) && is_multisite() ) {
					echo '<div class="error">' . "\n"
					     . '<p>'
					     . '<strong>'
					     . __( 'On multisite installations, the cache can only be bulk-flushed manually.', 'sem-cache' )
					     . '</strong>'
					     . '</p>'
					     . '</div>' . "\n";
					break;
				}

				if ( ! $timeout ) {
					cache_fs::flush( '/assets/' );
				}
				cache_fs::flush( '/static/', $timeout );
				cache_fs::flush( '/semi-static/', $timeout );
				wp_cache_flush();
				remove_action( 'flush_cache', array( 'sem_cache', 'flush_cache' ) );
				do_action( 'flush_cache' );

				echo '<div class="updated fade">' . "\n"
				     . '<p>'
				     . '<strong>'
				     . __( 'Cache Flushed.', 'sem-cache' )
				     . '</strong>'
				     . '</p>' . "\n"
				     . '</div>' . "\n";
				break;

			case 'off':
				sem_cache_manager::disable_static();
				sem_cache_manager::disable_memcached();
				sem_cache_manager::disable_assets();
				sem_cache_manager::disable_gzip();

				echo '<div class="updated fade">' . "\n"
				     . '<p>'
				     . '<strong>'
				     . __( 'Settings saved. Cache Disabled.', 'sem-cache' )
				     . '</strong>'
				     . '</p>' . "\n"
				     . '</div>' . "\n";
				break;

			default:
				$can_static    = sem_cache_manager::can_static();
				$can_memcached = sem_cache_manager::can_memcached();
				$can_query     = sem_cache_manager::can_query();
				$can_assets    = sem_cache_manager::can_assets();
				$can_gzip      = sem_cache_manager::can_gzip();

				if ( $_POST['action'] != 'on' ) {
					$static_cache = $can_static && isset( $_POST['static_cache'] );
					$memory_cache = $can_memcached && isset( $_POST['memory_cache'] );
					$query_cache  = $can_query && isset( $_POST['query_cache'] );
					$object_cache = $can_memcached && ( isset( $_POST['object_cache'] ) || isset( $_POST['query_cache'] ) || isset( $_POST['memory_cache'] ) );
					$asset_cache  = $can_assets && isset( $_POST['asset_cache'] );
					$gzip_cache   = $can_gzip && isset( $_POST['gzip_cache'] );
				} else {
					$static_cache = $can_static;
					$memory_cache = $can_memcached;
					$query_cache  = $can_query;
					$object_cache = $can_memcached;
					$asset_cache  = $can_assets;
					$gzip_cache   = $can_gzip;
				}

//			$static_static &= !( function_exists('is_multisite') && is_multisite() );

				update_site_option( 'static_cache', (int) $static_cache );
				update_site_option( 'memory_cache', (int) $memory_cache );
				update_site_option( 'query_cache', (int) $query_cache );
				update_site_option( 'object_cache', (int) $object_cache );
				update_site_option( 'asset_cache', (int) $asset_cache );
				update_site_option( 'gzip_cache', (int) $gzip_cache );

				#dump($static_cache, $memory_cache, $query_cache, $object_cache, $asset_cache, $gzip_cache);

                // process excluded pages
                $exclude_pages = stripslashes( $_POST['exclude_pages'] );
                $pages = preg_split( "/[\s,]+/", $exclude_pages );
                $exclude_pages = array();

                foreach( $pages as $num => $page ) {
                    $page = parse_url( $page, PHP_URL_PATH );
                    if ( $page !== FALSE ) {
                        if ( $page[0] != '/')
                            $page = '/' . $page;
                        if ( !in_array($page, $exclude_pages ) )
                            $exclude_pages[] = $page;
                    }
                }

                $exclude_pages = implode( ' ', $exclude_pages );
                update_site_option( 'sem_cache_excluded_pages', $exclude_pages );

				sem_cache_manager::enable_static();
				sem_cache_manager::enable_memcached();
				sem_cache_manager::enable_assets();
				sem_cache_manager::enable_gzip();

				echo '<div class="updated fade">' . "\n"
				     . '<p>'
				     . '<strong>'
				     . __( 'Settings saved. Cache Enabled.', 'sem-cache' )
				     . '</strong>'
				     . '</p>' . "\n"
				     . '</div>' . "\n";

				if ( ! get_site_option( 'object_cache' ) && class_exists( 'object_cache' ) ) {
					# do a hard object flush
					wp_cache_flush();
				}

				break;
		}

		if ( file_exists( WP_CONTENT_DIR . '/cache/wp_cache_mutex.lock' ) ) {
			cache_fs::flush( '/' );
		}
	} # save_options()

	/**
	 * edit_options()
	 *
	 * @return void
	 **/

	static function edit_options() {
		echo '<div class="wrap">' . "\n"
		     . '<form method="post" action="">';

		wp_nonce_field( 'sem_cache' );

		list( $files, $expired ) = cache_fs::stats( '/', cache_timeout );

		$static_errors = array();
		$memory_errors = array();
		$query_errors  = array();
		$object_errors = array();
		$assets_errors = array();
		$gzip_errors   = array();
		$gzip_notice   = array();

		$exclude_pages = get_site_option( 'sem_cache_excluded_pages', '' );

		$disable_style = " style='opacity: 0.7;cursor:auto'";

		if ( ! sem_cache_manager::can_memcached() ) {
			$error           = sprintf( __( '<a href="%1$s">Memcache</a> is not installed on your server, or the php extension is misconfigured, or the daemon is not running. Note that shared hosts never offer memcache; you need a dedicated server or a VPS to take advantage of it. Also note that there are two PHP extensions, and that only <a href="%1$s">this one</a> (Memcache not Memcached) is supported.',
				'sem-cache' ), 'http://www.php.net/manual/en/book.memcache.php' );
			$memory_errors[] = $error;
			$query_errors[]  = $error;
			$object_errors[] = $error;
		} elseif ( ! sem_cache_manager::can_object() ) {
			$error = __( 'WP cannot overwrite the object-cache.php file in your wp-content folder. The file needs to be writable by the server.',
				'sem-cache' );
			$memory_errors[] = $error;
			$query_errors[]  = $error;
			$object_errors[] = $error;
		}

		if ( ! version_compare( phpversion(), '5.1', '>=' ) ) {
			$error = sprintf( __( 'The Query Cache requires PHP 5.1 or more. Your server is currently running PHP %s. Please contact your host and have them upgrade PHP.',
				'sem-cache' ), phpversion() );
			$query_errors[] = $error;
		}

		if ( ( @ini_get( 'safe_mode' ) || @ini_get( 'open_basedir' ) ) && ! wp_mkdir_p( WP_CONTENT_DIR . '/cache' ) ) {
			$error = __( 'Safe mode or an open_basedir restriction is enabled on your server.', 'sem-cache' );
			$static_errors[] = $error;
			$assets_errors[] = $error;
		}

		if ( ! ( ! get_option( 'permalink_structure' ) || is_writable( ABSPATH . '.htaccess' ) ) && ! ( function_exists( 'is_multisite' ) && is_multisite() ) ) {
			$error = __( 'WP cannot overwrite your site\'s .htaccess file to insert new rewrite rules. The file needs to be writable by your server.',
				'sem-cache' );
			$static_errors[] = $error;
		}

		if ( ! is_writable( ABSPATH . '.htaccess' ) ) {
			$error = __( 'WP cannot overwrite your site\'s .htaccess file to insert extra instructions. The file needs to be writable by your server.',
				'sem-cache' );
			$gzip_errors[] = $error;
		}

		if ( ! ( defined( 'WP_CACHE' ) && WP_CACHE || is_writable( ABSPATH . 'wp-config.php' ) ) ) {
			$error = __( 'WP cannot define a WP_CACHE constant in your site\'s wp-config.php file. It needs to be added manually, or the file needs to be writable by the server.',
				'sem-cache' );
			$static_errors[] = $error;
			$memory_errors[] = $error;
		}

		if ( ! ( ! file_exists( WP_CONTENT_DIR . '/advanced-cache.php' )
		         || is_writable( WP_CONTENT_DIR . '/advanced-cache.php' ) )
		) {
			$error = __( 'WP cannot overwrite the advanced-cache.php file in your wp-content folder. The file needs to be writable by the server.',
				'sem-cache' );
			$static_errors[] = $error;
			$memory_errors[] = $error;
		}

		if ( ! ( ! file_exists( WP_CONTENT_DIR . '/cache' ) && is_writable( WP_CONTENT_DIR )
		         || is_dir( WP_CONTENT_DIR . '/cache' ) && is_writable( WP_CONTENT_DIR . '/cache' ) )
		) {
			$error = __( 'WP cannot create or write to the cache folder in your site\'s wp-content folder. It or the wp-content folder needs to be writable by the server.',
				'sem-cache' );
			$static_errors[] = $error;
			$assets_errors[] = $error;
		}

		if ( function_exists( 'apache_get_modules' ) ) {
			if ( ! apache_mod_loaded( 'mod_deflate' ) ) {
				$error = __( 'mod_deflate is required in order to allow Apache to conditionally compress the files it sends. (mod_gzip is not supported because it is too resource hungry.)  '
                    . 'Please contact your host so they configure Apache accordingly.',
					'sem-cache' );
				$gzip_errors[] = $error;
			}

			if ( ! apache_mod_loaded( 'mod_headers' ) ) {
				$error = __( 'mod_headers is required in order to avoid that proxies serve gzipped items to user agents who cannot use them. '
                    . 'Please contact your host so they configure Apache accordingly.',
					'sem-cache' );
				$gzip_errors[] = $error;
			}
		} else {
			# just assume it works
			$gzip_notice[] = __( 'gzip caching requires mod_deflate and mod_headers, but the Semiologic Cache plugin cannot determine whether they are installed on your server. '
                . 'Please check with your host.',
				'sem-cache' );
		}

		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$static_errors = array(
				__( 'The filesystem-based cache cannot be enabled on multisite installations.', 'sem-cache' ),
			);
		}

		foreach (
			array(
				'static_errors' => __( 'Filesystem-based static cache errors', 'sem-cache' ),
				'memory_errors' => __( 'Memcache-based static cache errors', 'sem-cache' ),
				'query_errors'  => __( 'Query cache errors', 'sem-cache' ),
				'object_errors' => __( 'Object cache errors', 'sem-cache' ),
				'assets_errors' => __( 'Asset cache errors', 'sem-cache' ),
				'gzip_errors'   => __( 'Gzip cache errors', 'sem-cache' ),
				'gzip_notice'   => __( 'Gzip cache notice', 'sem-cache' ),
			) as $var => $title
		) {
			if ( ! $$var ) {
				$$var = false;
			} else {
				$$var = '<h3>' . $title . '</h3>' . "\n"
                . '<ul class="ul-square">' . "\n"
                . '<li>' . implode( "</li>\n<li>", $$var )
                . '</li>' . "\n"
                . '</ul>' . "\n";
			}
		}

		echo '<h2>' . __( 'Cache Settings', 'sem-cache' ) . '</h2>' . "\n";

		echo '<table class="form-table">' . "\n";

		echo '<tr>' . "\n"
		     . '<th scope="row">'
		     . __( 'Quick and Easy', 'sem-cache' )
		     . '</th>' . "\n"
		     . '<td>'
		     . '<button type="submit" name="action" value="on" class="submit button">'
		     . __( 'Turn the cache on', 'sem-cache' )
		     . '</button>'
		     . ' '
		     . '<button type="submit" name="action" value="off" class="submit button">'
		     . __( 'Turn the cache off', 'sem-cache' )
		     . '</button>'
		     . ' '
		     . '<button type="submit" name="action" value="flush" class="submit button">'
		     . sprintf( __( 'Flush %d cached files', 'sem-cache' ), $files )
		     . '</button>'
		     . '<p>'
		     . __( 'The first of the above three buttons will autodetect the best means to improve the performance of your site, and turn the cache on. '
             . 'The second one will turn the cache off while the last one will retain your settings and strictly flushes the cache.',
				'sem-cache' )
		     . '</p>' . "\n"
		     . '</td>' . "\n"
		     . '</tr>' . "\n";

		echo '<tr>' . "\n"
		     . '<th scope="row">'
		     . __( 'Static Cache', 'sem-cache' )
		     . '</th>' . "\n"
		     . '<td>'
		     . '<p>'
		     . '<label' . ( $static_errors ? $disable_style : '' ) . '>'
		     . '<input type="checkbox"'
		     . ' id="static_cache" name="static_cache"'
		     . checked( (bool) get_site_option( 'static_cache' ), true, false )
		     . ( $static_errors
				? ' disabled="disabled"'
				: ''
		     )
		     . ' />'
		     . '&nbsp;'
		     . __( 'Serve filesystem-based, static versions of my site\'s web pages.', 'sem-cache' )
		     . '</label>'
		     . '</p>' . "\n"
		     . '<p>'
            . '<label' . ( $memory_errors ? $disable_style : '' ) . '>'
		     . '<input type="checkbox"'
		     . ' id="memory_cache" name="memory_cache"'
		     . checked( (bool) get_site_option( 'memory_cache' ), true, false )
		     . ( $memory_errors
				? ' disabled="disabled"'
				: ''
		     )
		     . ' />'
		     . '&nbsp;'
		     . __( 'Serve memcache-based, static versions of my site\'s web pages.', 'sem-cache' )
		     . '</label>'
		     . '</p>' . "\n"
		     . '<p>'
		     . __( 'The static cache will attempt to serve previously rendered version of the requested web pages to visitors who aren\'t logged in. '
             . 'The key drawback is that your visitors are not always viewing the latest version of your web pages. Key web pages on your site will get refreshed when you edit your posts and pages, '
             . 'so as to ensure they\'re reasonably fresh. Statically cached web pages expire after 24 hours.',
				'sem-cache' )
		     . '</p>' . "\n"
		     . '<p>'
		     . __( 'The benefit of the filesystem-based static cache is that your site\'s key web pages, such as the site\'s front page or individual posts, will be served without even loading PHP. '
             . 'This allows for maximum scalability of your site and provides for the fastest page content rendering.',
				'sem-cache' )
		     . '</p>' . "\n"
		     . '<p>'
		     . __( 'The memcache-based static cache works in a similar manner, but stores cached pages in memcache (memory) rather than on the filesystem (stored on disk). '
             . 'PHP is always loaded, so it\'s a bit slower for key web pages; but it\'s much faster than using the filesystem for other web pages.',
				'sem-cache' )
		     . '</p>' . "\n"
		     . '<p>'
		     . __( 'You\'ll usually want both turned on, in order to get the best of both worlds.',
				'sem-cache' )
		     . '</p>'
		     . $static_errors
		     . $memory_errors
		     . '</td>' . "\n"
		     . '</tr>' . "\n";

        echo '<tr>' . "\n"
            . '<th scope="row">'
            . __( 'Pages to Exclude', 'sem-cache' )
            . '</th>' . "\n"
            . '<td>'
            . '<label>'
            . __( 'Pages that should be excluded from processing:', 'sem-cache' )
            . '<textarea name="exclude_pages" cols="58" rows="4" class="widefat">'
            . esc_html( $exclude_pages )
            . '</textarea>' . "\n"
            . __( 'Pages should be separated by a comma, space or carriage return. Only the relative path should be entered and any scheme (http:\\ or https:\\'
            . 'or the root domain will be stripped off.', 'sem-cache' )
            . '</label>&nbsp;&nbsp;'
            . '<i>' .__( 'Example: /about-us/, /contact/, .', 'sem-cache' ) . '</i>'
            . '<br />' . "\n"
            . '</td>'
            . '</tr>' . "\n";

		echo '<tr>' . "\n"
		     . '<th scope="row">'
		     . __( 'Query Cache', 'sem-cache' )
		     . '</th>' . "\n"
		     . '<td>'
		     . '<p>'
            . '<label' . ( $query_errors ? $disable_style : '' ) . '>'
		     . '<input type="checkbox"'
		     . ' id="query_cache" name="query_cache"'
		     . checked( (bool) get_site_option( 'query_cache' ), true, false )
		     . ( $query_errors
				? ' disabled="disabled"'
				: ''
		     )
		     . ' />'
		     . '&nbsp;'
		     . __( 'Cache MySQL query results in memory.', 'sem-cache' )
		     . '</label>'
		     . '</p>' . "\n"
		     . '<p>'
		     . __( 'The query cache lets WordPress work in a fully dynamic manner, while doing its best to avoid hits to the MySQL database.',
				'sem-cache' )
		     . '</p>' . "\n"
		     . '<p>'
		     . __( 'The query cache primarily benefits commentors and users who are logged in; in particular yourself. These users cannot benefit from a static cache, because each web page '
             . 'on your site potentially contains data that is specific to them; but they fully benefit from a query cache.',
				'sem-cache' )
		     . '</p>' . "\n"
		     . '<p>'
		     . __( 'The query cache\'s refresh policy is similar to that of the memory-based static cache: key queries are flushed whenever you edit posts or pages, or approve new comments. '
             . 'All of the remaining queries expire after 24 hours.',
				'sem-cache' )
		     . '</p>' . "\n"
		     . $query_errors
		     . '</td>' . "\n"
		     . '</tr>' . "\n";

		echo '<tr>' . "\n"
		     . '<th scope="row">'
		     . __( 'Object Cache', 'sem-cache' )
		     . '</th>' . "\n"
		     . '<td>'
		     . '<p>'
            . '<label' . ( $object_errors ? $disable_style : '' ) . '>'
		     . '<input type="checkbox"'
		     . ' id="object_cache" name="object_cache"'
		     . checked( (bool) get_site_option( 'object_cache' ), true, false )
		     . ( $object_errors
				? ' disabled="disabled"'
				: ''
		     )
		     . ' />'
		     . '&nbsp;'
		     . __( 'Make WordPress objects persistent.', 'sem-cache' )
            . '<label' . ( $object_errors ? $disable_style : '' ) . '>'
		     . '</p>' . "\n"
		     . '<p>'
		     . __( 'The object cache stores granular bits of information in memcache, and makes them available from a page to the next. This allows WordPress to load web pages without '
             . 'always needing to retrieve things such as options, users, or individual entries from the database.',
				'sem-cache' )
		     . '</p>' . "\n"
		     . '<p>'
		     . __( 'The object cache\'s primary benefit is that it is always accurate: at no time will it ever serve data that is potentially outdated.',
				'sem-cache' )
		     . '</p>' . "\n"
		     . '<p>'
		     . __( 'The object cache is automatically turned on, and cannot be disabled, when you use the memory-based static cache or the query cache.',
				'sem-cache' )
		     . '</p>' . "\n"
		     . $object_errors
		     . '</td>' . "\n"
		     . '</tr>' . "\n";

		echo '<tr>' . "\n"
		     . '<th scope="row">'
		     . __( 'Asset Cache', 'sem-cache' )
		     . '</th>' . "\n"
		     . '<td>'
		     . '<p>'
            . '<label' . ( $assets_errors ? $disable_style : '' ) . '>'
		     . '<input type="checkbox"'
		     . ' id="asset_cache" name="asset_cache"'
		     . checked( (bool) get_site_option( 'asset_cache' ), true, false )
		     . ( $assets_errors
				? ' disabled="disabled"'
				: ''
		     )
		     . ' />'
		     . '&nbsp;'
		     . __( 'Enable the asset cache.', 'sem-cache' )
		     . '</label>'
		     . '</p>' . "\n"
		     . '<p>'
		     . __( 'The asset cache speeds your site up by minimizing the number of server requests. It achieves this by concatenating your javascript and CSS files on the front end '
             . 'and reducing the file size by eliminating extranous whitespace and other code size reduction techniques.',
				'sem-cache' )
		     . '</p>' . "\n"
		     . '<p>'
		     . __( 'This setting should always be turned on, unless you\'re in the process of manually editing these assets.',
				'sem-cache' )
		     . '</p>' . "\n"
		     . $assets_errors
		     . '</td>' . "\n"
		     . '</tr>' . "\n";

		echo '<tr>' . "\n"
		     . '<th scope="row">'
		     . __( 'File Compression', 'sem-cache' )
		     . '</th>' . "\n"
		     . '<td>'
		     . '<p>'
            . '<label' . ( $gzip_errors ? $disable_style : '' ) . '>'
		     . '<input type="checkbox"'
		     . ' id="gzip_cache" name="gzip_cache"'
		     . checked( (bool) get_site_option( 'gzip_cache' ), true, false )
		     . ( $gzip_errors
				? ' disabled="disabled"'
				: ''
		     )
		     . ' />'
		     . '&nbsp;'
		     . __( 'Enable text file compression.', 'sem-cache' )
		     . '</label>'
		     . '</p>' . "\n"
		     . '<p>'
		     . __( 'Compressing files that are sent by your site trims the load time by as much as 70%. The file compression itself is taken care of at the Apache level, by using mod_deflate.',
				'sem-cache' )
		     . '</p>' . "\n"
		     . '<p>'
		     . __( 'This setting should always be turned on, unless you\'re in the process of manually editing files on your site.',
				'sem-cache' )
		     . '</p>' . "\n"
		     . $gzip_errors
		     . $gzip_notice
		     . '</td>' . "\n"
		     . '</tr>' . "\n";

		echo '</table>' . "\n";

		echo '<p class="submit">'
		     . '<button type="submit" name="action" value="save" class="submit button">'
		     . __( 'Save Changes', 'sem-cache' )
		     . '</button>'
		     . '</p>' . "\n";

		echo '</form>' . "\n"
		     . '</div>' . "\n";
	} # edit_options()

} # sem_cache_admin

$sem_cache_admin = sem_cache_admin::get_instance();

