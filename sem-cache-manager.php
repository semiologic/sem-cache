<?php
/**
 * sem_cache_manager
 *
 * @package Semiologic Cache
 **/

class sem_cache_manager {
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
		$this->plugin_url  = plugins_url( '/', __FILE__ );
		$this->plugin_path = plugin_dir_path( __FILE__ );

		$this->init();
	}

	/**
	 * init()
	 *
	 * @return void
	 **/
	function init() {
	}


	/**
	 * cache_status()
	 *
	 * @return bool  true for on, false for off
	 **/

	static function caching_status_on() {
		return (bool) get_site_option('static_cache');
	}


	/**
	 * enable_caching()
	 *
	 * @return void
	 **/

	static function enable_caching() {

	           $can_static = self::can_static();
	           $can_memcached = self::can_memcached();
	           $can_query = self::can_query();
	           $can_assets = self::can_assets();
	           $can_gzip = self::can_gzip();

	           $static_cache = $can_static;
	           $memory_cache = $can_memcached;
	           $query_cache = $can_query;
	           $object_cache = $can_memcached;
	           $asset_cache = $can_assets;
	           $gzip_cache = $can_gzip;

	           update_site_option('static_cache', (int) $static_cache);
	           update_site_option('memory_cache', (int) $memory_cache);
	           update_site_option('query_cache', (int) $query_cache);
	           update_site_option('object_cache', (int) $object_cache);
	           update_site_option('asset_cache', (int) $asset_cache);
	           update_site_option('gzip_cache', (int) $gzip_cache);

	           #dump($static_cache, $memory_cache, $query_cache, $object_cache, $asset_cache, $gzip_cache);

	           self::enable_static();
	           self::enable_memcached();
	           self::enable_assets();
	           self::enable_gzip();

	           if ( !get_site_option('object_cache') && class_exists('object_cache') ) {
	               # do a hard object flush
	               wp_cache_flush();
	           }

	       }


	/**
	 * can_static()
	 *
	 * @return bool $can_static
	 **/

	static function can_static() {
		static $can_static;
		if ( isset($can_static) )
			return $can_static;

		$can_static = ( !get_option('permalink_structure') || is_writable(ABSPATH . '.htaccess') )
			&& ( defined('WP_CACHE') && WP_CACHE || is_writable(ABSPATH . 'wp-config.php') )
			&& ( !file_exists(WP_CONTENT_DIR . '/advanced-cache.php')
				|| is_writable(WP_CONTENT_DIR . '/advanced-cache.php') )
			&& ( !file_exists(WP_CONTENT_DIR . '/cache') && is_writable(WP_CONTENT_DIR)
				|| is_dir(WP_CONTENT_DIR . '/cache') && is_writable(WP_CONTENT_DIR . '/cache') )
			&& 	( !@ini_get('safe_mode') && !@ini_get('open_basedir')
				|| wp_mkdir_p(WP_CONTENT_DIR . '/cache') )
			&& !( function_exists('is_multisite') && is_multisite() );

		return $can_static;
	} # can_static()


	/**
	 * can_memcached()
	 *
	 * @return bool $can_memcached
	 **/

	static function can_memcached() {
		static $can_memcached;
		if ( isset($can_memcached) )
			return $can_memcached;

		# avoid conflicts with other memcached implementations
		global $_wp_using_ext_object_cache;
		if ( $_wp_using_ext_object_cache && !class_exists('object_cache') ) {
			$can_memcached = false;
			return $can_memcached;
		}

		if ( !class_exists('Memcache') || !method_exists('Memcache', 'addServer') ) {
			$can_memcached = false;
			return $can_memcached;
		}

		global $memcached_servers;

		if ( isset($memcached_servers) )
			$buckets = $memcached_servers;
		else
			$buckets = array('127.0.0.1');

		reset($buckets);
		if ( is_int(key($buckets)) )
			$buckets = array('default' => $buckets);

		$can_memcached = false;
		foreach ( $buckets as $bucket => $servers) {
			$test = new Memcache();
			foreach ( $servers as $server  ) {
				$server = explode(':', $server);
				if ( count($server) == 2 ) {
					list($node, $port) = $server;
				} else {
					$node = current($server);
					$port = false;
				}
				if ( !$port )
					$port = ini_get('memcache.default_port');
				$port = intval($port);
				if ( !$port )
					$port = 11211;
				$can_memcached |= @ $test->connect($node, $port);
				if ( $can_memcached ) {
					$can_memcached = true;
					$test->close();
					break;
				}
			}
		}

		return $can_memcached;
	} # can_memcached()


	/**
	 * can_memory()
	 *
	 * @return bool $can_memory
	 **/

	static function can_memory() {
		static $can_memory;
		if ( isset($can_memory) )
			return $can_memory;

		$can_memory = self::can_object()
			&& ( defined('WP_CACHE') && WP_CACHE || is_writable(ABSPATH . 'wp-config.php') )
			&& ( !file_exists(WP_CONTENT_DIR . '/advanced-cache.php')
				|| is_writable(WP_CONTENT_DIR . '/advanced-cache.php') );

		return $can_memory;
	} # can_memory()


	/**
	 * can_query()
	 *
	 * @return bool $can_query
	 **/

	static function can_query() {
		static $can_query;
		if ( isset($can_query) )
			return $can_query;

		$can_query = self::can_object()
			&& version_compare(phpversion(), '5.1', '>=');

		return $can_query;
	} # can_query()


	/**
	 * can_object()
	 *
	 * @return bool $can_object
	 **/

	static function can_object() {
		static $can_object;
		if ( isset($can_object) )
			return $can_object;

		$can_object = self::can_memcached()
			&& ( !file_exists(WP_CONTENT_DIR . '/object-cache.php')
				|| is_writable(WP_CONTENT_DIR . '/object-cache.php') );

		return $can_object;
	} # can_object()


	/**
	 * can_assets()
	 *
	 * @return bool $can_static
	 **/

	static function can_assets() {
		static $can_assets;
		if ( isset($can_assets) )
			return $can_assets;

		$can_assets = ( !file_exists(WP_CONTENT_DIR . '/cache') && is_writable(WP_CONTENT_DIR)
					|| is_dir(WP_CONTENT_DIR . '/cache') && is_writable(WP_CONTENT_DIR . '/cache') )
				&& ( !@ini_get('safe_mode') && !@ini_get('open_basedir')
					|| wp_mkdir_p(WP_CONTENT_DIR . '/cache') );

		return $can_assets;
	} # can_assets()


	/**
	 * can_gzip()
	 *
	 * @return bool $can_gzip
	 **/

	static function can_gzip() {
		static $can_gzip;
		if ( isset($can_gzip) )
			return $can_gzip;

		if ( function_exists('apache_get_modules') ) {
			$mods = apache_get_modules();
			$can_gzip = in_array('mod_deflate', $mods)
				&& in_array('mod_headers', $mods)
				&& is_writable(ABSPATH . '.htaccess');
		} else {
			# just assume it works
			$can_gzip = is_writable(ABSPATH . '.htaccess');
		}

		return $can_gzip;
	} # can_gzip()


	/**
	 * enable_static()
	 *
	 * @return bool $success
	 **/

	static function enable_static() {
		if ( !self::can_static() && !self::can_memory() )
			return false;

		$static_cache = (bool) get_option('static_cache');
		$memory_cache = (bool) get_option('memory_cache');

		wp_clear_scheduled_hook('cache_timeout');
		wp_clear_scheduled_hook('static_cache_timeout');

		# sanity check
		if ( !$static_cache && !$memory_cache )
			return self::disable_static();

		if ( !wp_mkdir_p(WP_CONTENT_DIR . '/cache') ) {
			echo '<div class="error">'
				. '<p>'
				. sprintf(__('Error: Failed to create %s.', 'sem-cache'), 'wp-content/cache')
				. '</p>'
				. '</div>' . "\n";
			return false;
		}

		$use_static_cache = $static_cache ? 'true' : 'false';
		$use_memory_cache = $memory_cache ? 'true' : 'false';
		$sem_cache_cookies = var_export(sem_cache::get_cookies(), true);
		$sem_mobile_agents = var_export(sem_cache::get_mobile_agents(), true);
		$sem_cache_file = dirname(__FILE__) . '/static-cache.php';

		$contents = <<<EOS
<?php
define('static_cache', $use_static_cache);
define('memory_cache', $use_memory_cache);

\$sem_cache_cookies = $sem_cache_cookies;

\$sem_mobile_agents = $sem_mobile_agents;

include '$sem_cache_file';
?>
EOS;

		$file = WP_CONTENT_DIR . '/advanced-cache.php';
		$perms = stat(WP_CONTENT_DIR);
		$perms = $perms['mode'] & 0000666;

		if ( !file_put_contents($file, $contents) || !chmod($file, $perms) ) {
			echo '<div class="error">'
				. '<p>'
				. sprintf(__('Error: Failed to write %s.', 'sem-cache'), 'wp-content/advanced-cache.php')
				. '</p>'
				. '</div>' . "\n";
			return false;
		}

		# Enable the static cache
		if ( !function_exists('save_mod_rewrite_rules') || !function_exists('get_home_path') )
			include_once ABSPATH . 'wp-admin/includes/admin.php';

		if ( !isset($GLOBALS['wp_rewrite']) ) $GLOBALS['wp_rewrite'] = new WP_Rewrite;

		# prevent mass-flushing when the permalink structure hasn't changed
		remove_action('generate_rewrite_rules', array('sem_cache_manager', 'flush_cache'));

		global $wp_rewrite;
		$wp_rewrite->flush_rules();

		# Enable the memory cache
		if ( $memory_cache && !self::enable_memcached() )
			return false;

		# Enable the cache
		$file = ABSPATH . 'wp-config.php';
		if ( !defined('WP_CACHE') || !WP_CACHE ) {
			$contents = file_get_contents($file);
			$line = "define('WP_CACHE', true);";
			if (  stristr($contents, "'WP_CACHE'") ) {
				$contents = preg_replace("/
					(?!(?:\/\/|\#)\s*)
					define\s*\(\s*
						(['\"])WP_CACHE\\1
						.*?;
					/x",
					$line,
					$contents);
			} else {
				$contents = preg_replace(
					"/^<\?php\s*/",
					"<?php" . PHP_EOL . PHP_EOL . $line . PHP_EOL . PHP_EOL,
					$contents);
			}

			if ( !$contents || !file_put_contents($file, $contents) ) {
				echo '<div class="error">'
					. '<p>'
					. __('Error: Failed to override the WP_CACHE define in wp-config.php.', 'sem-cache')
					. '</p>'
					. '</div>' . "\n";
				return false;
			}
		}

		return true;
	} # enable_static()


	/**
	 * disable_static()
	 *
	 * @return void
	 **/

	static function disable_static() {
		update_site_option('static_cache', 0);
		update_site_option('memory_cache', 0);

		wp_clear_scheduled_hook('cache_timeout');
		wp_clear_scheduled_hook('static_cache_timeout');

		# Prevent WP from adding new pages
		$file = ABSPATH . 'wp-config.php';
		if ( defined('WP_CACHE') ) {
			$contents = file_get_contents($file);
			$contents = preg_replace("/
				(?!(?:\/\/|\#)\s*)
				define\s*\(\s*
					(['\"])WP_CACHE\\1
					.*?;
					\s*
				/x",
				PHP_EOL,
				$contents);

			if ( !$contents || !file_put_contents($file, $contents) ) {
				echo '<div class="error">'
					. '<p>'
					. __('Error: Failed to override the WP_CACHE define in wp-config.php.', 'sem-cache')
					. '</p>'
					. '</div>' . "\n";
			}
		}

		if ( file_exists(WP_CONTENT_DIR . '/advanced-cache.php')
			&& !unlink(WP_CONTENT_DIR . '/advanced-cache.php') ) {
			echo '<div class="error">'
				. '<p>'
				. sprintf(__('Error: Failed to delete %s.', 'sem-cache'), 'wp-content/advanced-cache.php')
				. '</p>'
				. '</div>' . "\n";
		}

		# Prevent WP from serving cached pages
		if ( !function_exists('save_mod_rewrite_rules') || !function_exists('get_home_path') )
			include_once ABSPATH . 'wp-admin/includes/admin.php';

		if ( !isset($GLOBALS['wp_rewrite']) ) $GLOBALS['wp_rewrite'] = new WP_Rewrite;

		# prevent mass-flushing when the permalink structure hasn't changed
		remove_action('generate_rewrite_rules', array('sem_cache_manager', 'flush_cache'));

		global $wp_rewrite;
		$wp_rewrite->flush_rules();

		# Flush the cache
		sem_cache_manager::flush_static();
	} # disable_static()


	/**
	 * enable_assets()
	 *
	 * @return bool $success
	 **/

	static function enable_assets() {
		if ( !self::can_assets() )
			return false;

		$asset_cache = (bool) get_option('asset_cache');

		if ( !$asset_cache )
			return self::disable_assets();

		return wp_mkdir_p(WP_CONTENT_DIR . '/cache/assets');
	} # enable_assets()


	/**
	 * disable_assets()
	 *
	 * @return void
	 **/

	static function disable_assets() {
		update_site_option('asset_cache', 0);

		sem_cache_manager::flush_assets();
	} # disable_assets()


	/**
	 * enable_gzip()
	 *
	 * @return bool $success
	 **/

	static function enable_gzip() {
		if ( !self::can_gzip() )
			return false;

		$gzip_cache = (bool) get_option('gzip_cache');

		if ( !$gzip_cache )
			return self::disable_gzip();

		# Enable rewrite rules
		if ( !function_exists('save_mod_rewrite_rules') || !function_exists('get_home_path') )
			include_once ABSPATH . 'wp-admin/includes/admin.php';

		if ( !isset($GLOBALS['wp_rewrite']) ) $GLOBALS['wp_rewrite'] = new WP_Rewrite;

		# prevent mass-flushing when the permalink structure hasn't changed
		remove_action('generate_rewrite_rules', array('sem_cache_manager', 'flush_cache'));

		global $wp_rewrite;
		$wp_rewrite->flush_rules();

		return true;
	} # enable_gzip()


	/**
	 * disable_gzip()
	 *
	 * @return void
	 **/

	static function disable_gzip() {
		update_site_option('gzip_cache', 0);

		# Enable rewrite rules
		if ( !function_exists('save_mod_rewrite_rules') || !function_exists('get_home_path') )
			include_once ABSPATH . 'wp-admin/includes/admin.php';

		if ( !isset($GLOBALS['wp_rewrite']) ) $GLOBALS['wp_rewrite'] = new WP_Rewrite;

		# prevent mass-flushing when the permalink structure hasn't changed
		remove_action('generate_rewrite_rules', array('sem_cache_manager', 'flush_cache'));

		global $wp_rewrite;
		$wp_rewrite->flush_rules();
	} # disable_gzip()


	/**
	 * enable_memcached()
	 *
	 * @return bool $success
	 **/

	static function enable_memcached() {
		if ( !self::can_object() )
			return false;

		$memory_cache = (bool) get_option('memory_cache');
		$query_cache = (bool) get_option('query_cache');
		$object_cache = (bool) get_option('object_cache');

		if ( !$memory_cache && !$query_cache && !$object_cache )
			return self::disable_memcached();

		$sem_cache_file = dirname(__FILE__) . '/object-cache.php';
		$contents = <<<EOS
	<?php
	include_once '$sem_cache_file';
	?>
EOS;

		$file = WP_CONTENT_DIR . '/object-cache.php';
		$perms = stat(WP_CONTENT_DIR);
		$perms = $perms['mode'] & 0000666;

		if ( !file_put_contents($file, $contents) || !chmod($file, $perms) ) {
			echo '<div class="error">'
				. '<p>'
				. sprintf(__('Error: Failed to write %s.', 'sem-cache'), 'wp-content/object-cache.php')
				. '</p>'
				. '</div>' . "\n";
			return false;
		}

		return true;
	} # enable_memcached()


	/**
	 * disable_memcached()
	 *
	 * @return void
	 **/

	static function disable_memcached() {
		update_site_option('memory_cache', 0);
		update_site_option('query_cache', 0);
		update_site_option('object_cache', 0);

		if ( file_exists(WP_CONTENT_DIR . '/object-cache.php')
			&& !unlink(WP_CONTENT_DIR . '/object-cache.php') ) {
			echo '<div class="error">'
				. '<p>'
				. sprintf(__('Error: Failed to delete %s.', 'sem-cache'), 'wp-content/advanced-cache.php')
				. '</p>'
				. '</div>' . "\n";
		}
	} # disable_memcached()


	/**
	 * flush_cache()
	 *
	 * @param mixed $in
	 * @return mixed $in
	 **/

	static function flush_cache($in = null) {
		foreach ( array(
			'static_cache',
			'memory_cache',
			'query_cache',
			'asset_cache',
			'gzip_cache',
			) as $ops )
			add_option($ops, '0');
		self::flush_static();
		self::flush_assets();
		return $in;
	} # flush_cache()

	/**
	 * flush_static()
	 *
	 * @param bool|int $timeout
	 * @return void
	 */

	static function flush_static($timeout = false) {
		if ( static_cache )
			cache_fs::flush('/static/', $timeout);
		if ( memory_cache )
			self::flush_objects();
		elseif ( static_cache )
			cache_fs::flush('/semi-static/', $timeout);
	} # flush_static()


	/**
	 * flush_assets()
	 *
	 * @param bool|int $timeout
	 * @return void
	 */

	static function flush_assets($timeout = false) {
		cache_fs::flush('/assets/', $timeout);
	} # flush_assets()


	/**
	 * flush_objects()
	 *
	 * @return void
	 **/

	static function flush_objects() {
		wp_cache_flush();
	} # flush_objects()

}# sem_cache_manager

$sem_cache_manager = sem_cache_manager::get_instance();
