<?php
/**
 * sem_cache_admin
 *
 * @package Semiologic Cache
 **/

class sem_cache_admin {
	/**
	 * save_options()
	 *
	 * @return void
	 **/

	function save_options() {
		if ( !$_POST || !current_user_can('manage_options') )
			return;
		
		check_admin_referer('sem_cache');
		
		$timeout = false;
		switch ( $_POST['action'] ) {
		case 'clean':
			$timeout = cache_timeout;
		
		case 'flush':
			sem_cache::flush_assets($timeout);
			sem_cache::flush_static($timeout);
			if ( !get_option('memory_cache') )
				sem_cache::flush_objects();
			
			echo '<div class="updated fade">' . "\n"
				. '<p>'
					. '<strong>'
					. __('Cache Flushed.', 'sem-cache')
					. '</strong>'
				. '</p>' . "\n"
				. '</div>' . "\n";
			break;
		
		case 'off':
			self::disable_static();
			self::disable_memcached();
			self::disable_assets();
			self::disable_gzip();
			sem_cache::flush_objects();
			
			echo '<div class="updated fade">' . "\n"
				. '<p>'
					. '<strong>'
					. __('Settings saved. Cache Disabled.', 'sem-cache')
					. '</strong>'
				. '</p>' . "\n"
				. '</div>' . "\n";
			break;
		
		default:
			$can_static = self::can_static();
			$can_memcached = self::can_memcached();
			$can_query = self::can_query();
			$can_assets = self::can_assets();
			$can_gzip = self::can_gzip();
			
			if ( $_POST['action'] != 'on' ) {
				$static_cache = $can_static && isset($_POST['static_cache']);
				$memory_cache = $can_memcached && isset($_POST['memory_cache']);
				$query_cache = $can_query && isset($_POST['query_cache']);
				$object_cache = $can_memcached && ( isset($_POST['object_cache']) || isset($_POST['query_cache']) || isset($_POST['memory_cache']) );
				$asset_cache = $can_assets && isset($_POST['asset_cache']);
				$gzip_cache = $can_gzip && isset($_POST['gzip_cache']);
			} else {
				$static_cache = $can_static;
				$memory_cache = $can_memcached;
				$query_cache = $can_query;
				$object_cache = $can_memcached;
				$asset_cache = $can_assets;
				$gzip_cache = $can_gzip;
			}
			
			update_option('static_cache', (int) $static_cache);
			update_option('memory_cache', (int) $memory_cache);
			update_option('query_cache', (int) $query_cache);
			update_option('object_cache', (int) $object_cache);
			update_option('asset_cache', (int) $asset_cache);
			update_option('gzip_cache', (int) $gzip_cache);
			
			#dump($static_cache, $memory_cache, $query_cache, $object_cache, $asset_cache, $gzip_cache);
			
			self::enable_static();
			self::enable_memcached();
			self::enable_assets();
			self::enable_gzip();
			sem_cache::flush_objects();
			
			echo '<div class="updated fade">' . "\n"
				. '<p>'
					. '<strong>'
					. __('Settings saved. Cache Enabled.', 'sem-cache')
					. '</strong>'
				. '</p>' . "\n"
				. '</div>' . "\n";
			break;
		}
		
		if ( file_exists(WP_CONTENT_DIR . '/cache/wp_cache_mutex.lock') )
			cache_fs::flush('/', $timeout);
	} # save_options()
	
	
	/**
	 * edit_options()
	 *
	 * @return void
	 **/

	function edit_options() {
		echo '<div class="wrap">' . "\n"
			. '<form method="post" action="">';

		wp_nonce_field('sem_cache');
		
		screen_icon();
		
		list($files, $expired) = cache_fs::stats();
		
		$static_errors = array();
		$memory_errors = array();
		$query_errors = array();
		$object_errors = array();
		$assets_errors = array();
		$gzip_errors = array();
		$gzip_notice = array();
		
		if ( !self::can_memcached() ) {
			$error = sprintf(__('<a href="%1$s">Memcached</a> is not installed on your server, or the php extension is misconfigured, or the daemon is not running. Note that shared hosts never offer memcached; you need a dedicated server or a VPS such as those offered by <a href="%2$s">Hub</a> to take advantage of it. Also note that there are two PHP extensions, and that only <a href="%1$s">this one</a> is supported.', 'sem-cache'), 'http://www.php.net/manual/en/book.memcache.php', 'http://hub.org');
			$memory_errors[] = $error;
			$query_errors[] = $error;
			$object_errors[] = $error;
		} elseif ( !self::can_object() ) {
			$error = __('WP cannot overwrite the object-cache.php file in your wp-content folder. The file needs to be writable by the server.', 'sem-cache');
			$memory_errors[] = $error;
			$query_errors[] = $error;
			$object_errors[] = $error;
		}
		
		if ( !version_compare(phpversion(), '5.1', '>=') ) {
			$error = sprintf(__('The Query Cache requires PHP 5.1 or more. Your server is currently running PHP %s. Please contact your host and have them upgrade PHP.', 'sem-cache'), phpversion());
			$query_errors[] = $error;
		}
		
		if ( @ini_get('safe_mode') || @ini_get('open_basedir') ) {
			$error = __('Safe mode or an open_basedir restriction is enabled on your server.', 'sem-cache');
			$static_errors[] = $error;
			$assets_errors[] = $error;
		}
		
		if ( !( !get_option('permalink_structure') || is_writable(ABSPATH . '.htaccess') ) ) {
			$error = __('WP cannot overwrite your site\'s .htaccess file to insert new rewrite rules. The file needs to be writable by your server.', 'sem-cache');
			$static_errors[] = $error;
		}
		
		if ( !is_writable(ABSPATH . '.htaccess') ) {
			$error = __('WP cannot overwrite your site\'s .htaccess file to insert extra instructions. The file needs to be writable by your server.', 'sem-cache');
			$gzip_errors[] = $error;
		}
		
		if ( !( defined('WP_CACHE') && WP_CACHE || is_writable(ABSPATH . 'wp-config.php') ) ) {
			$error = __('WP cannot define a WP_CACHE constant in your site\'s wp-config.php file. It needs to be added manually, or the file needs to be writable by the server.', 'sem-cache');
			$static_errors[] = $error;
			$memory_errors[] = $error;
		}
		
		if ( !( !file_exists(WP_CONTENT_DIR . '/advanced-cache.php')
			|| is_writable(WP_CONTENT_DIR . '/advanced-cache.php') ) ) {
			$error = __('WP cannot overwrite the advanced-cache.php file in your wp-content folder. The file needs to be writable by the server.', 'sem-cache');
			$static_errors[] = $error;
			$memory_errors[] = $error;
		}
		
		if ( !( !file_exists(WP_CONTENT_DIR . '/cache') && is_writable(WP_CONTENT_DIR)
			|| is_dir(WP_CONTENT_DIR . '/cache') && is_writable(WP_CONTENT_DIR . '/cache') ) ) {
			$error = __('WP cannot create or write to the cache folder in your site\'s wp-content folder. It or the wp-content folder needs to be writable by the server.', 'sem-cache');
			$static_errors[] = $error;
			$assets_errors[] = $error;
		}
		
		if ( function_exists('apache_get_modules') ) {
			if ( !apache_mod_loaded('mod_deflate') ) {
				$error = __('mod_deflate is required in order to allow Apache to conditionally compress the files it sends. (mod_gzip is not supported because it is too resource hungry.)  Please contact your host so they configure Apache accordingly.', 'sem-cache');
				$gzip_errors[] = $error;
			}

			if ( !apache_mod_loaded('mod_headers') ) {
				$error = __('mod_headers is required in order to avoid that proxies serve gzipped items to user agents who cannot use them. Please contact your host so they configure Apache accordingly.', 'sem-cache');
				$gzip_errors[] = $error;
			}
		} else {
			# just assume it works
			$gzip_notice[] = __('gzip caching requires mod_deflate and mod_headers, but the Semiologic Cache plugin cannot determine whether they are installed on your server. Please check with your host.', 'sem-cache');
		}
		
		foreach ( array(
			'static_errors' => __('Filesystem-based static cache errors', 'sem-cache'),
			'memory_errors' => __('Memcached-based static cache errors', 'sem-cache'),
			'query_errors' => __('Query cache errors', 'sem-cache'),
			'object_errors' => __('Object cache errors', 'sem-cache'),
			'assets_errors' => __('Asset cache errors', 'sem-cache'),
			'gzip_errors' => __('Gzip cache errors', 'sem-cache'),
			'gzip_notice' => __('Gzip cache notice', 'sem-cache'),
			) as $var => $title ) {
			if ( !$$var ) {
				$$var = false;
			} else {
				$$var = '<h3>' . $title . '</h3>' . "\n"
					. '<ul class="ul-square">' . "\n"
					. '<li>' . implode("</li>\n<li>", $$var)
					. '</li>' . "\n"
					. '</ul>' . "\n";
			}
		}
		
		echo '<h2>' . __('Cache Settings', 'sem-cache') . '</h2>' . "\n";
		
		echo '<table class="form-table">' . "\n";
		
		echo '<tr>' . "\n"
			. '<th scope="row">'
			. __('Please do it for me...', 'sem-cache')
			. '</th>' . "\n"
			. '<td>'
			. '<button type="submit" name="action" value="on" class="submit button">'
					. __('Turn the cache on', 'sem-cache')
					. '</button>'
			. ' '
			. '<button type="submit" name="action" value="off" class="submit button">'
					. __('Turn the cache off', 'sem-cache')
					. '</button>'
			. ' '
			. '<button type="submit" name="action" value="flush" class="submit button">'
				. sprintf(__('Flush %d cached files', 'sem-cache'), $files)
				. '</button>'
			. ' '
			. '<button type="submit" name="action" value="clean" class="submit button">'
				. sprintf(__('Flush %d expired files', 'sem-cache'), $expired)
				. '</button>'
			. '<p>'
			. __('The first of the above three buttons will autodetect the best means to improve the performance of your site, and turn the cache on. The second one will turn the cache off. The last one will retain your settings, and stick to flushing the cache.', 'sem-cache')
			. '</p>' . "\n"
			. '</td>' . "\n"
			. '</tr>' . "\n";
		
		echo '<tr>' . "\n"
			. '<th scope="row">'
			. __('Static Cache', 'sem-cache')
			. '</th>' . "\n"
			. '<td>'
			. '<p>'
			. '<label for="static_cache">'
			. '<input type="checkbox"'
				. ' id="static_cache" name="static_cache"'
				. checked((bool) get_option('static_cache'), true, false)
				. ( $static_errors
					? ' disabled="disabled"'
					: ''
					)
				. ' />'
			. '&nbsp;'
			. __('Serve filesystem-based, static versions of my site\'s web pages.', 'sem-cache')
			. '</label>'
			. '</p>' . "\n"
			. '<p>'
			. '<label for="static_cache">'
			. '<input type="checkbox"'
				. ' id="memory_cache" name="memory_cache"'
				. checked((bool) get_option('memory_cache'), true, false)
				. ( $memory_errors
					? ' disabled="disabled"'
					: ''
					)
				. ' />'
			. '&nbsp;'
			. __('Serve memcached-based, static versions of my site\'s web pages.', 'sem-cache')
			. '</label>'
			. '</p>' . "\n"
			. '<p>'
			. __('The static cache will attempt to serve previously rendered version of the requested web pages to visitors who aren\'t logged in. The key drawback is that your visitors are not always viewing the latest version of your web pages. Lists of recent posts and recent comments, for instance, may take up to 12 hours to refresh across your site. In addition, it prevents any random elements that are introduced at the php level from working.', 'sem-cache')
			. '</p>' . "\n"
			. '<p>'
			. __('Key web pages on your site will get refreshed when you edit your posts and pages, so as to ensure they\'re reasonably fresh. Newly approved comments will trigger throttled refreshes of an even smaller subset of web pages. Statically cached web pages expire after 12 hours.', 'sem-cache')
			. '</p>' . "\n"
			. '<p>'
			. __('The benefit of the filesystem-based static cache is that your site\'s key web pages, such as the site\'s front page or individual posts, will be served without even loading PHP. This allows for maximum scalability if your site is getting hammered by excrutiating traffic.', 'sem-cache')
			. '</p>' . "\n"
			. '<p>'
			. __('The memcached-based static cache works in a similar manner, but stores cached pages in memcached rather than on the filesystem. PHP is always loaded, so it\'s a bit slower for key web pages; but it\'s much faster than using the filesystem for other web pages.', 'sem-cache')
			. '</p>' . "\n"
			. '<p>'
			. __('You\'ll usually want both turned on, in order to get the best of both worlds. The only exception is if your site is hosted on multiple servers: in this case, consider sticking to the memory-based static cache, because of the lag introduced by the filesystem\'s synchronisations from a server to the next.', 'sem-cache')
			. '</p>'
			. $static_errors
			. $memory_errors
			. '</td>' . "\n"
			. '</tr>' . "\n";
		
		echo '<tr>' . "\n"
			. '<th scope="row">'
			. __('Query Cache', 'sem-cache')
			. '</th>' . "\n"
			. '<td>'
			. '<p>'
			. '<label for="static_cache">'
			. '<input type="checkbox"'
				. ' id="query_cache" name="query_cache"'
				. checked((bool) get_option('query_cache'), true, false)
				. ( $query_errors
					? ' disabled="disabled"'
					: ''
					)
				. ' />'
			. '&nbsp;'
			. __('Cache MySQL query results in memory.', 'sem-cache')
			. '</label>'
			. '</p>' . "\n"
			. '<p>'
			. __('The query cache lets WordPress work in a fully dynamic manner, while doing its best to avoid hits to the MySQL database.', 'sem-cache')
			. '</p>' . "\n"
			. '<p>'
			. __('The query cache primarily benefits commentors and users who are logged in; in particular yourself. These users cannot benefit from a static cache, because each of web page on your site potentially contains data that is specific to them; but they fully benefit from a query cache.', 'sem-cache')
			. '</p>' . "\n"
			. '<p>'
			. __('The query cache\'s refresh policy is similar to that of the memory-based static cache: key queries are flushed whenever you edit posts or pages, or approve new comments. All of the remaining queries expire after 12 hours.', 'sem-cache')
			. '</p>' . "\n"
			. $query_errors
			. '</td>' . "\n"
			. '</tr>' . "\n";
		
		echo '<tr>' . "\n"
			. '<th scope="row">'
			. __('Object Cache', 'sem-cache')
			. '</th>' . "\n"
			. '<td>'
			. '<p>'
			. '<label for="static_cache">'
			. '<input type="checkbox"'
				. ' id="object_cache" name="object_cache"'
				. checked((bool) get_option('object_cache'), true, false)
				. ( $object_errors
					? ' disabled="disabled"'
					: ''
					)
				. ' />'
			. '&nbsp;'
			. __('Make WordPress objects persistent.', 'sem-cache')
			. '</label>'
			. '</p>' . "\n"
			. '<p>'
			. __('The object cache stores granular bits of information in memcached, and makes them available from a page to the next. This allows WordPress to load web pages without always needing to retrieve things such as options, users, or individual entries from the database.', 'sem-cache')
			. '</p>' . "\n"
			. '<p>'
			. __('The object cache\'s primary benefit is that it is always accurate: at no time will it ever serve data that is potentially outdated.', 'sem-cache')
			. '</p>' . "\n"
			. '<p>'
			. __('The object cache is automatically turned on, and cannot be disabled, if you use the memory-based static cache or the query cache.', 'sem-cache')
			. '</p>' . "\n"
			. $object_errors
			. '</td>' . "\n"
			. '</tr>' . "\n";
		
		echo '<tr>' . "\n"
			. '<th scope="row">'
			. __('Asset Cache', 'sem-cache')
			. '</th>' . "\n"
			. '<td>'
			. '<p>'
			. '<label for="asset_cache">'
			. '<input type="checkbox"'
				. ' id="asset_cache" name="asset_cache"'
				. checked((bool) get_option('asset_cache'), true, false)
				. ( $assets_errors
					? ' disabled="disabled"'
					: ''
					)
				. ' />'
			. '&nbsp;'
			. __('Enable the asset cache.', 'sem-cache')
			. '</label>'
			. '</p>' . "\n"
			. '<p>'
			. __('The asset cache speeds your site up by minimizing the number of server requests. It achieve this by concatenating your javascript and CSS files on the front end.', 'sem-cache')
			. '</p>' . "\n"
			. '<p>'
			. __('This setting should always be turned on, unless you\'re in the process of manually editing these assets.', 'sem-cache')
			. '</p>' . "\n"
			. $assets_errors
			. '</td>' . "\n"
			. '</tr>' . "\n";
		
		echo '<tr>' . "\n"
			. '<th scope="row">'
			. __('File Compression', 'sem-cache')
			. '</th>' . "\n"
			. '<td>'
			. '<p>'
			. '<label for="gzip_cache">'
			. '<input type="checkbox"'
				. ' id="gzip_cache" name="gzip_cache"'
				. checked((bool) get_option('gzip_cache'), true, false)
				. ( $gzip_errors
					? ' disabled="disabled"'
					: ''
					)
				. ' />'
			. '&nbsp;'
			. __('Enable text file compression.', 'sem-cache')
			. '</label>'
			. '</p>' . "\n"
			. '<p>'
			. __('Compressing files that are sent by your site trims the load time by as much as 70%. The file compression itself is taken care of at the Apache level, by using mod_deflate.', 'sem-cache')
			. '</p>' . "\n"
			. '<p>'
			. __('This setting should always be turned on, unless you\'re in the process of manually editing files on your site.', 'sem-cache')
			. '</p>' . "\n"
			. $gzip_errors
			. $gzip_notice
			. '</td>' . "\n"
			. '</tr>' . "\n";
		
		echo '</table>' . "\n";

		echo '<p class="submit">'
			. '<button type="submit" name="action" value="save" class="submit button">'
				. __('Save Changes', 'sem-cache')
				. '</button>'
			. '</p>' . "\n";
		
		echo '</form>' . "\n"
			. '</div>' . "\n";
	} # edit_options()
	
	
	/**
	 * can_static()
	 *
	 * @return bool $can_static
	 **/

	static function can_static() {
		static $can_static;
		if ( isset($can_static) )
			return $can_static;
		
		$can_static = !ini_get('safe_mode') && !ini_get('open_basedir')
			&& ( !get_option('permalink_structure') || is_writable(ABSPATH . '.htaccess') )
			&& ( defined('WP_CACHE') && WP_CACHE || is_writable(ABSPATH . 'wp-config.php') )
			&& ( !file_exists(WP_CONTENT_DIR . '/advanced-cache.php')
				|| is_writable(WP_CONTENT_DIR . '/advanced-cache.php') )
			&& ( !file_exists(WP_CONTENT_DIR . '/cache') && is_writable(WP_CONTENT_DIR)
				|| is_dir(WP_CONTENT_DIR . '/cache') && is_writable(WP_CONTENT_DIR . '/cache') );
		
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
				list ( $node, $port ) = explode(':', $server);
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

	function can_memory() {
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

	function can_query() {
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

	function can_object() {
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
		
		$can_assets = !@ini_get('safe_mode') && !@ini_get('open_basedir')
			&& ( !file_exists(WP_CONTENT_DIR . '/cache') && is_writable(WP_CONTENT_DIR)
				|| is_dir(WP_CONTENT_DIR . '/cache') && is_writable(WP_CONTENT_DIR . '/cache') );
		
		return $can_assets;
	} # can_assets()
	
	
	/**
	 * can_gzip()
	 *
	 * @return bool $can_gzip
	 **/

	function can_gzip() {
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

	function enable_static() {
		if ( !self::can_static() && !self::can_memory() )
			return false;
		
		$static_cache = (bool) get_option('static_cache');
		$memory_cache = (bool) get_option('memory_cache');
		
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
		
		$static_cache = $static_cache ? 'true' : 'false';
		$memory_cache = $memory_cache ? 'true' : 'false';
		$sem_cache_cookies = var_export(sem_cache::get_cookies(), true);
		$sem_mobile_agents = var_export(sem_cache::get_mobile_agents(), true);
		$sem_cache_file = dirname(__FILE__) . '/static-cache.php';
		
		$contents = <<<EOS
<?php
define('static_cache', $static_cache);
define('memory_cache', $memory_cache);

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
		remove_action('generate_rewrite_rules', array('sem_cache', 'flush_cache'));
		
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
			if ( defined('WP_CACHE') ) {
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
					"<?php" . PHP_EOL . $line . PHP_EOL,
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

	function disable_static() {
		update_option('static_cache', 0);
		update_option('memory_cache', 0);
		
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
		remove_action('generate_rewrite_rules', array('sem_cache', 'flush_cache'));
		
		global $wp_rewrite;
		$wp_rewrite->flush_rules();
		
		# Flush the cache
		sem_cache::flush_static();
	} # disable_static()
	
	
	/**
	 * enable_assets()
	 *
	 * @return bool $success
	 **/

	function enable_assets() {
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
	 * @return vodi
	 **/

	function disable_assets() {
		update_option('asset_cache', 0);
		
		sem_cache::flush_assets();
	} # disable_assets()
	
	
	/**
	 * enable_gzip()
	 *
	 * @return bool $success
	 **/

	function enable_gzip() {
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
		remove_action('generate_rewrite_rules', array('sem_cache', 'flush_cache'));
		
		global $wp_rewrite;
		$wp_rewrite->flush_rules();
		
		return true;
	} # enable_gzip()
	
	
	/**
	 * disable_gzip()
	 *
	 * @return void
	 **/

	function disable_gzip() {
		update_option('gzip_cache', 0);
		
		# Enable rewrite rules
		if ( !function_exists('save_mod_rewrite_rules') || !function_exists('get_home_path') )
			include_once ABSPATH . 'wp-admin/includes/admin.php';
		
		if ( !isset($GLOBALS['wp_rewrite']) ) $GLOBALS['wp_rewrite'] = new WP_Rewrite;
		
		# prevent mass-flushing when the permalink structure hasn't changed
		remove_action('generate_rewrite_rules', array('sem_cache', 'flush_cache'));
		
		global $wp_rewrite;
		$wp_rewrite->flush_rules();
	} # disable_gzip()
	
	
	/**
	 * enable_memcached()
	 *
	 * @return bool $success
	 **/

	function enable_memcached() {
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

	function disable_memcached() {
		update_option('memory_cache', 0);
		update_option('query_cache', 0);
		update_option('object_cache', 0);
		
		if ( file_exists(WP_CONTENT_DIR . '/object-cache.php')
			&& !unlink(WP_CONTENT_DIR . '/object-cache.php') ) {
			echo '<div class="error">'
				. '<p>'
				. sprintf(__('Error: Failed to delete %s.', 'sem-cache'), 'wp-content/advanced-cache.php')
				. '</p>'
				. '</div>' . "\n";
		}
		
		sem_cache::flush_objects();
	} # disable_memcached()
} # sem_cache_admin

add_action('settings_page_sem-cache', array('sem_cache_admin', 'save_options'), 0);
?>