<?php
/*
Plugin Name: Semiologic Cache
Plugin URI: http://www.semiologic.com/software/sem-cache/
Description: An advanced caching module for WordPress.
Version: 2.0 beta
Author: Denis de Bernardy
Author URI: http://www.getsemiologic.com
Text Domain: sem-cache
Domain Path: /lang
*/


/*
Terms of use
------------

This software is copyright Mesoconcepts and is distributed under the terms of the Mesoconcepts license. In a nutshell, you may freely use it for any purpose, but may not redistribute it without written permission.

http://www.mesoconcepts.com/license/
**/


load_plugin_textdomain('sem-cache', false, dirname(plugin_basename(__FILE__)) . '/lang');

/**
 * sem_cache
 *
 * @package Semiologic Cache
 **/

foreach ( array(
	'sem_cache_debug',
	'SCRIPT_DEBUG',
	'sem_css_debug',
	'sem_sql_debug',
	) as $const ) {
	if ( !defined($const) )
		define($const, false);
}

foreach ( array(
	'static_cache',
	'memory_cache',
	'query_cache',
	'asset_cache',
	'gzip_cache',
	) as $const ) {
	if ( !defined($const) )
		define($const, (bool) get_option($const));
}

if ( !defined('cache_timeout') )
	define('cache_timeout', 14400);

class sem_cache {
	/**
	 * admin_menu()
	 *
	 * @return void
	 **/

	function admin_menu() {
		add_options_page(
			__('Cache', 'sem-cache'),
			__('Cache', 'sem-cache'),
			'manage_options',
			'sem-cache',
			array('sem_cache_admin', 'edit_options')
			);
	} # admin_menu()
	
	
	/**
	 * front_menu()
	 *
	 * @return void
	 **/

	function front_menu() {
		if ( !current_user_can('manage_options') )
			return;
		
		echo '<span class="am_options">'
			. '<a href="' . trailingslashit(admin_url()) . 'options-general.php?page=sem-cache">'
				. __('Cache', 'sem-cache')
				. '</a>'
			. '</span>'
			. ' ';
		
	} # front_menu()
	
	
	/**
	 * activate()
	 *
	 * @return void
	 **/

	static function activate() {
		foreach ( array(
			'static_cache',
			'memory_cache',
			'query_cache',
			'asset_cache',
			'gzip_cache',
			) as $ops )
			add_option($ops, '0');

		self::disable();
	} # activate()
	
	
	/**
	 * deactivate()
	 *
	 * @return void
	 **/

	static function deactivate() {
		self::disable();
	} # deactivate()
	
	
	/**
	 * disable()
	 *
	 * @return void
	 **/

	static function disable() {
		self::disable_static();
		self::disable_memcached();
		self::disable_assets();
		self::disable_gzip();
		
		if ( file_exists(WP_CONTENT_DIR . '/cache') )
			@cache_fs::flush(WP_CONTENT_DIR . '/cache');
		
		self::flush_objects();
	} # disable()
	
	
	/**
	 * enable_static()
	 *
	 * @return bool $success
	 **/

	function enable_static() {
		if ( !self::can_static() && !self::can_memcached() )
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
		self::flush_static();
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
		
		self::flush_assets();
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
		if ( !self::can_memcached() )
			return false;
		
		$memory_cache = (bool) get_option('memory_cache');
		$query_cache = (bool) get_option('query_cache');
		$object_cache = (bool) get_option('object_cache');
		
		if ( !$memory_cache && !$query_cache && !$object_cache )
			return self::disable_gzip();
		
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
		
		if ( file_exists(WP_CONTENT_DIR . '/advanced-cache.php')
			&& !unlink(WP_CONTENT_DIR . '/advanced-cache.php') ) {
			echo '<div class="error">'
				. '<p>'
				. sprintf(__('Error: Failed to delete %s.', 'sem-cache'), 'wp-content/advanced-cache.php')
				. '</p>'
				. '</div>' . "\n";
		}
		
		self::flush_objects();
	} # disable_memcached()
	
	
	/**
	 * can_static()
	 *
	 * @return bool $can_static
	 **/

	static function can_static() {
		static $can_static;
		if ( isset($can_static) )
			return $can_static;
		
		$can_static = !ini_get('safe_mode')
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
					$test->close();
					break;
				}
			}
		}
		
		return $can_memcached;
	} # can_memcached()
	
	
	/**
	 * can_query()
	 *
	 * @return bool $can_query
	 **/

	function can_query() {
		static $can_query;
		if ( isset($can_query) )
			return $can_query;
		
		$can_query = self::can_memcached()
			&& version_compare(phpversion(), '5.1', '>=');
		
		return $can_query;
	} # can_query()
	
	
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
		
		$can_gzip = apache_mod_loaded('mod_deflate') && apache_mod_loaded('mod_headers')
			&& is_writable(ABSPATH . '.htaccess');
		
		return $can_gzip;
	} # can_gzip()
	
	
	/**
	 * rewrite_rules()
	 *
	 * @param string $rules
	 * @return string $rules
	 **/

	static function rewrite_rules($rules) {
		if ( (bool) get_option('static_cache') ) {
			$cache_dir = WP_CONTENT_DIR . '/cache/static';
			$cache_url = parse_url(WP_CONTENT_URL . '/cache/static');
			$cache_url = $cache_url['path'];
			
			if ( function_exists('is_site_admin') && defined('VHOST') && VHOST ) {
				$cache_dir .= '/' . $_SERVER['HTTP_HOST'];
				$cache_url .= '/' . $_SERVER['HTTP_HOST'];
			}
			
			$cache_cookies = array();
			foreach ( self::get_cookies() as $cookie )
				$cache_cookies[] = "RewriteCond %{HTTP_COOKIE} !\b$cookie=";
			$cache_cookies = implode("\n", $cache_cookies);
			
			$mobile_agents = self::get_mobile_agents();
			$mobile_agents = array_map('preg_quote', $mobile_agents);
			$mobile_agents = implode('|', $mobile_agents);
			
			$extra = <<<EOS

RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond $cache_dir/%{REQUEST_URI}/index.html -f
RewriteCond %{HTTP_USER_AGENT} !^.+($mobile_agents)
$cache_cookies
RewriteCond %{QUERY_STRING} ^$
RewriteCond %{THE_REQUEST} ^GET
RewriteRule ^ $cache_url/%{REQUEST_URI}/index.html [L]

RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond $cache_dir/%{REQUEST_URI} -f
RewriteCond %{HTTP_USER_AGENT} !^.+($mobile_agents)
$cache_cookies
RewriteCond %{QUERY_STRING} ^$
RewriteCond %{THE_REQUEST} ^GET
RewriteRule ^ $cache_url/%{REQUEST_URI} [L]

EOS;
			
			# this will simply fail if mod_rewrite isn't available
			if ( preg_match("/RewriteBase.+\n*/i", $rules, $rewrite_base) ) {
				$rewrite_base = end($rewrite_base);
				$new_rewrite_base = trim($rewrite_base) . "\n\n" . trim($extra) . "\n\n";
				$rules = str_replace($rewrite_base, $new_rewrite_base, $rules);
			}
		}
		
		if ( (bool) get_option('gzip_cache') ) {
			$extra = <<<EOS

<IfModule mod_deflate.c>
# Insert filters
AddOutputFilterByType DEFLATE text/plain
AddOutputFilterByType DEFLATE text/html
AddOutputFilterByType DEFLATE text/xml
AddOutputFilterByType DEFLATE text/css
AddOutputFilterByType DEFLATE text/javascript
AddOutputFilterByType DEFLATE application/xml
AddOutputFilterByType DEFLATE application/xhtml+xml
AddOutputFilterByType DEFLATE application/rss+xml
AddOutputFilterByType DEFLATE application/javascript
AddOutputFilterByType DEFLATE application/x-javascript
AddOutputFilterByType DEFLATE application/json
AddOutputFilterByType DEFLATE application/x-httpd-php
AddOutputFilterByType DEFLATE application/x-httpd-fastphp
AddOutputFilterByType DEFLATE image/svg+xml

# Drop problematic browsers
BrowserMatch ^Mozilla/4 gzip-only-text/html
BrowserMatch ^Mozilla/4\.0[678] no-gzip
BrowserMatch \bMSI[E] !no-gzip !gzip-only-text/html

# Make sure proxies don't deliver the wrong content
Header append Vary User-Agent env=!dont-vary
</IfModule>


EOS;
			
			$rules = $extra . $rules;
		}
		
		$encoding = get_option('blog_charset');
		if ( !$encoding )
			$encoding = 'utf-8';
		
		$extra = <<<EOS

AddDefaultCharset $encoding

EOS;
		
		$rules = $extra . $rules;
		
		return $rules;
	} # rewrite_rules()
	
	
	/**
	 * cache_timeout()
	 *
	 * @param bool $force
	 * @return bool $success
	 **/

	function cache_timeout() {
		if ( !static_cache )
			return;
		cache_fs::flush('/static', cache_timeout);
		if ( !memory_cache )
			cache_fs::flush('/semi-static', cache_timeout);
	} # cache_timeout()
	
	
	/**
	 * get_cookies()
	 *
	 * @return array $cookies
	 **/

	function get_cookies() {
		$cookies = array(
			LOGGED_IN_COOKIE,
			'comment_author_' . COOKIEHASH,
			'comment_author_email_' . COOKIEHASH,
			'wp-postpass_' . COOKIEHASH,
			);
		
		return apply_filters('sem_cache_cookies', $cookies);
	} # get_cookies()
	
	
	/**
	 * get_mobile_agents()
	 *
	 * @return array $agents
	 **/

	function get_mobile_agents() {
		$agents = array(
			'iPhone',
			'iPod',
			'aspen',
			'dream',
			'android',
			'BlackBerry',
			);
		return apply_filters('sem_cache_mobile_agents', $agents);
	} # get_mobile_agents()
	
	
	/**
	 * flush_static()
	 *
	 * @param int $timeout
	 * @return void
	 **/

	function flush_static($timeout = false) {
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
	 * @param int $timeout
	 * @return void
	 **/

	function flush_assets($timeout = false) {
		cache_fs::flush('/assets/', $timeout);
	} # flush_assets()
	
	
	/**
	 * flush_objects()
	 *
	 * @return void
	 **/

	function flush_objects() {
		# do not flush anything if the session handler is memcached
		if ( ini_get('session.save_handler') === 'memcache' )
			return;
		
		$vars = array(
			'update_core',
			'update_plugins',
			'update_themes',
			'sem_update_plugins',
			'sem_update_themes',
			);
		
		$extra = array(
			'feed_220431e2eb0959fa9c7fcb07c6e22632', # sem news
			'feed_mod_220431e2eb0959fa9c7fcb07c6e22632', # sem_news timeout
			);
		foreach ( array_merge($vars, $extra) as $var ) {
			$$var = get_transient($var);
		}
		wp_cache_flush();
		foreach ( $vars as $var ) {
			if ( $$var !== false )
				set_transient($var, $$var);
		}
		if ( $feed_220431e2eb0959fa9c7fcb07c6e22632 !== false ) {
			$var = 'feed_220431e2eb0959fa9c7fcb07c6e22632';
			set_transient($var, $$var, 3600);
			$var = 'feed_mod_220431e2eb0959fa9c7fcb07c6e22632';
			set_transient($var, time(), 3600);
		}
	} # flush_objects()
	
	
	/**
	 * flush_url()
	 *
	 * @param string $url
	 * @return void
	 **/

	function flush_url($link) {
		$cache_id = md5($link);
		if ( query_cache ) {
			wp_cache_delete($cache_id, 'url2posts');
			wp_cache_delete($cache_id, 'url2posts_found');
		}
		if ( static_cache ) {
			static $permalink_structure;
			if ( !isset($permalink_structure) )
				$permalink_structure = get_option('permalink_structure');
			if ( $permalink_structure ) {
				$path = preg_replace("|[^/]+://[^/]+|", '', $link);
				# 5 min throttling in case the site is getting hammered by comments
				$timeout = current_filter() == 'wp_update_comment_count' ? 300 : false;
				cache_fs::flush('/static/' . $path, $timeout);
			}
		}
		if ( memory_cache ) {
			wp_cache_delete($cache_id, 'cached_headers');
			wp_cache_delete($cache_id, 'cached_buffers');
		} elseif ( static_cache ) {
			cache_fs::flush('/semi-static/' . $cache_id . '.meta', $timeout);
			cache_fs::flush('/semi-static/' . $cache_id . '.html', $timeout);
		}
	} # flush_url()
	
	
	/**
	 * pre_flush_post()
	 *
	 * @param int $post_id
	 * @return void
	 **/

	function pre_flush_post($post_id) {
		$post_id = (int) $post_id;
		if ( !$post_id )
			return;
		
		$post = get_post($post_id);
		if ( !$post || wp_is_post_revision($post_id) )
			return;
		
		$old = wp_cache_get($post_id, 'pre_flush_post');
		if ( $old === false )
			$old = array();
		
		$update = false;
		foreach ( array(
			'post_status',
			) as $field ) {
			if ( !isset($old[$field]) ) {
				$old[$field] = $post->$field;
				$update = true;
			}
		}
		
		if ( !isset($old['permalink']) ) {
			$old['permalink'] = apply_filters('the_permalink', get_permalink($post_id));
			$update = true;
		}
		
		if ( $post->post_type == 'post' ) {
			foreach ( array('category', 'post_tag') as $taxonomy ) {
				if ( !isset($o[$taxonomy]) ) {
					$terms = wp_get_object_terms($post_id, $taxonomy);
					$old[$taxonomy] = array();
					if ( !is_wp_error($terms) ) {
						foreach ( $terms as &$term )
							$old[$taxonomy][] = $term->term_id;
					}
					$update = true;
				}
			}
		}
		
		if ( $update )
			wp_cache_set($post_id, $old, 'pre_flush_post');
	} # pre_flush_post()
	
	
	/**
	 * flush_post_url()
	 *
	 * @param string $url
	 * @param int $timeout
	 * @return void
	 **/

	function flush_post_url($link) {
		$cache_id = md5($link);
		if ( query_cache ) {
			wp_cache_delete($cache_id, 'url2post_id');
		}
		if ( static_cache ) {
			static $permalink_structure;
			if ( !isset($permalink_structure) )
				$permalink_structure = get_option('permalink_structure');
			if ( $permalink_structure ) {
				$path = preg_replace("|[^/]+://[^/]+|", '', $link);
				# 5 min throttling in case the site is getting hammered by comments
				$timeout = current_filter() == 'wp_update_comment_count' ? 300 : false;
				cache_fs::flush('/static/' . $path, $timeout);
			}
		}
		if ( memory_cache ) {
			wp_cache_delete($cache_id, 'cached_headers');
			wp_cache_delete($cache_id, 'cached_buffers');
		} elseif ( static_cache ) {
			cache_fs::flush('/semi-static/' . $cache_id . '.meta', $timeout);
			cache_fs::flush('/semi-static/' . $cache_id . '.html', $timeout);
		}
	} # flush_post_url()
	
	
	/**
	 * flush_post()
	 *
	 * @return void
	 **/

	function flush_post($post_id) {
		static $done = array();
		
		$post_id = (int) $post_id;
		if ( !$post_id )
			return;
		
		if ( isset($done[$post_id]) )
			return;
		
		# prevent mass-flushing when the permalink structure hasn't changed
		remove_action('generate_rewrite_rules', array('sem_cache', 'flush_cache'));
		
		$done[$post_id] = true;
		
		$post = get_post($post_id);
		if ( !$post || wp_is_post_revision($post_id) )
			return;
		
		$old = wp_cache_get($post_id, 'pre_flush_post');
		
		if ( $post->post_status != 'publish' && ( !$old || $old['post_status'] != 'publish' ) )
			return;
		
		# flush the post
		self::do_flush_post($post_id);
		
		# flush the home and blog pages
		self::do_flush_home();
		
		if ( $post->post_type == 'post' && current_filter() != 'wp_update_comment_count' ) {
			# flush categories
			$cats = wp_get_object_terms($post_id, 'category');
			$cat_ids = array();
			if ( !is_wp_error($cats) ) {
				foreach ( $cats as &$cat )
					$cat_ids[] = $cat->term_id;
			}
			if ( $old )
				$cat_ids = array_merge($cat_ids, $old['category']);
			if ( defined('main_cat_id') && main_cat_id )
				$cat_ids = array_diff($cat_ids, array(main_cat_id) );
			foreach ( array_unique($cat_ids) as $cat_id )
				self::do_flush_term($cat_id, 'category');
			
			# flush tags
			$tags = wp_get_object_terms($post_id, 'post_tag');
			$tag_ids = array();
			if ( !is_wp_error($tags) ) {
				foreach ( $tags as &$tag )
					$tag_ids[] = $tag->term_id;
			}
			if ( $old )
				$tag_ids = array_merge($tag_ids, $old['post_tag']);
			foreach ( array_unique($tag_ids) as $tag_id )
				self::do_flush_term($tag_id, 'post_tag');
			
			# flush author
			$author_ids = array();
			$author_ids[] = $post->post_author;
			if ( $old )
				$author_ids[] = $old['post_author'];
			foreach ( array_unique($author_ids) as $author_id )
				self::do_flush_author($author_id);
			
			# flush archives
			$dates = array();
			$dates[] = strtotime($post->post_date);
			if ( $old )
				$dates[] = strtotime($old['post_date']);
			foreach ( array_unique($dates) as $date )
				self::do_flush_date($date);
		}
		
		# in case other plugins want to hook into this...
		do_action('flush_post', $post_id);
	} # flush_post()
	
	
	/**
	 * do_flush_post()
	 *
	 * @param int $post_id
	 * @return void
	 **/

	function do_flush_post($post_id) {
		static $done = array();
		if ( isset($done[$post_id]) )
			return;
		
		$done[$post_id] = true;
		static $permalink_structure;
		if ( !isset($permalink_structure) )
			$permalink_structure = get_option('permalink_structure');
		$old = wp_cache_get($post_id, 'pre_flush_post');
		
		$links = array();
		$links[] = apply_filters('the_permalink', get_permalink($post_id));
		$pages = preg_match("/<!--nextpage-->/", $post->post_content);
		if ( $old ) {
			$links[] = $old['permalink'];
			$pages = max($pages, preg_match("/<!--nextpage-->/", $old['post_content']));
		}
		
		foreach ( array_unique($links) as $link => $content ) {
			self::flush_post_url($link);
			
			$pages = preg_match("/<!--nextpage-->/", $content);
			for ( $i = 1; $i <= $pages; $i++ ) {
				if ( !$permalink_structure )
					$extra = $link . '&page=' . $i;
				else
					$extra = trailingslashit($link) . user_trailingslashit($i, 'single_paged');
				self::flush_post_url($extra);
			}
		}
		
		# flush the comments
		wp_cache_delete($post_id, 'cached_comments');
		wp_cache_delete($post_id, 'uncached_comments');
	} # do_flush_post()
	
	
	/**
	 * do_flush_home()
	 *
	 * @return void
	 **/

	function do_flush_home() {
		static $done = false;
		if ( $done )
			return;
		
		$done = true;
		static $permalink_structure;
		if ( !isset($permalink_structure) )
			$permalink_structure = get_option('permalink_structure');
		
		$links = array();
		$blog_link = trailingslashit(get_option('home'));
		$links[] = $blog_link;
		if ( get_option('show_on_front') != 'posts' && get_option('page_on_front') ) {
			$blog_page_id = get_option('page_for_posts');
			if ( $blog_page_id && get_post($blog_page_id) ) {
				$blog_link = apply_filters('the_permalink', get_permalink($blog_page_id));
				$links[] = $blog_link;
			}
		}
		
		foreach ( array_unique($links) as $link )
			self::flush_url($link);
		
		# flush the next two blog pages
		for ( $i = 2; $i <= 3; $i++ ) {
			if ( !$permalink_structure )
				$link = $blog_link . '&page=' . $i;
			else
				$link = trailingslashit($blog_link) . user_trailingslashit('page/' . $i, 'paged');
			self::flush_url($link);
		}
		
		# flush the blog feeds
		foreach ( array('rss2', 'atom', 'comments_rss2', 'comments_atom') as $feed ) {
			$link = get_feed_link($feed);
			self::flush_url($link);
		}
	} # do_flush_home()
	
	
	/**
	 * do_flush_term()
	 *
	 * @param int $term_id
	 * @param string $taxonomy
	 * @return void
	 **/

	function do_flush_term($term_id, $taxonomy) {
		static $done = array();
		if ( isset($done[$taxonomy][$term_id]) )
			return;
		
		$done[$taxonomy][$term_id] = true;
		
		if ( $taxonomy == 'category' )
			$link = get_category_link($tag_id);
		else
			$link = get_tag_link($tag_id);
		if ( is_wp_error($link) )
			return;
		self::flush_url($link);
		
		foreach ( array('rss2', 'atom') as $feed ) {
			if ( $taxonomy == 'category' )
				$link = get_category_feed_link($tag_id, $feed);
			else
				$link = get_tag_feed_link($tag_id, $feed);
			$link = str_replace('&amp;', '&', $link);
			self::flush_url($link);
		}
	} # do_flush_term()
	
	
	/**
	 * do_flush_author()
	 *
	 * @param int $author_id
	 * @return void
	 **/

	function do_flush_author($author_id) {
		static $done = array();
		if ( isset($done[$author_id]) )
			return;
		
		$done[$author_id] = true;
		
		$link = get_author_posts_url($author_id);
		self::flush_url($link);
		
		foreach ( array('rss2', 'atom') as $feed ) {
			$link = str_replace('&amp;', '&', get_author_feed_link($author_id, $feed));
			self::flush_url($link);
		}
	} # do_flush_author()
	
	
	/**
	 * do_flush_date()
	 *
	 * @return void
	 **/

	function do_flush_date($date) {
		static $done;
		
		$year = date('Y', $date);
		$month = date('m', $date);
		$day = date('d', $date);
		
		if ( !isset($done["$year"]) ) {
			$link = get_year_link($year);
			self::flush_url($link);
			$done["$year"] = true;
		}
		
		if ( !isset($done["$year-$month"]) ) {
			$link = get_month_link($year, $month);
			self::flush_url($link);
			$done["$year-$month"] = true;
		}
		
		if ( !isset($done["$year-$month-$day"]) ) {
			$link = get_day_link($year, $month, $day);
			self::flush_url($link);
			$done["$year-$month-$day"] = true;
		}
	} # do_flush_date()
	
	
	/**
	 * get_stats()
	 *
	 * @return string $stats
	 **/

	function get_stats() {
		$date = date('Y-m-d @ H:i:s');
		
		$time_spent = ( 1000 * timer_stop() ) . 'ms';
		$style = 'text-align: center; font-size: 8px;';
		$stats = "Served in $time_spent on $date.";

		if ( function_exists('memory_get_usage') ) {
			$memory = number_format(memory_get_usage() / ( 1024 * 1024 ), 1);
			$stats .= " Memory: {$memory}MB.";
		}
		
		global $wpdb;
		$queries = get_num_queries();
		if ( query_cache && !is_admin() ) {
			$queries += $wpdb->cache_hits;
			$stats .= " Query Cache: $wpdb->cache_hits hits / $queries.";
		} else {
			$stats .= " Queries: $queries.";
		}

		global $wp_object_cache;
		$stats .= " Object Cache: $wp_object_cache->cache_hits hits / " . ( $wp_object_cache->cache_hits + $wp_object_cache->cache_misses ) . ".";
		
		if ( ( sem_cache_debug || !is_admin() && current_user_can('manage_options') ) && !is_feed() )
			return "\n<p style='$style'>$stats</p>";
		elseif ( sem_cache_debug )
			return "\n<!-- $stats -->";
		else
			return "\n<!-- $date -->\n";
	} # get_stats()
	
	
	/**
	 * stats()
	 *
	 * @return void
	 **/
	
	function stats() {
		echo self::get_stats();
	} # stats()
	
	
	/**
	 * flush_cache()
	 *
	 * @param mixed $in
	 * @return mixed $in
	 **/

	function flush_cache($in = null) {
		foreach ( array(
			'static_cache',
			'memory_cache',
			'query_cache',
			'asset_cache',
			'gzip_cache',
			) as $ops )
			add_option($ops, '0');
		self::flush_static();
		return $in;
	} # flush_cache()
} # sem_cache

register_activation_hook(__FILE__, array('sem_cache', 'disable'));
register_deactivation_hook(__FILE__, array('sem_cache', 'disable'));

if ( !class_exists('cache_fs') )
	include dirname(__FILE__) . '/cache-fs.php';

if ( !is_admin() ) {
	if ( query_cache )
		include dirname(__FILE__) . '/query-cache.php';

	if ( asset_cache )
		include dirname(__FILE__) . '/asset-cache.php';
}

if ( static_cache ) {
	add_action('cache_timeout', array('sem_cache', 'cache_timeout'));
	if ( !wp_next_scheduled('cache_timeout') )
		wp_schedule_event(time(), 'hourly', 'cache_timeout');
	if ( sem_cache_debug && ( wp_next_scheduled('cache_timeout') - time() > cache_timeout ) )
		wp_schedule_single_event(time() + cache_timeout, 'cache_timeout');
}

if ( class_exists('static_cache') ) {
	add_filter('status_header', array('static_cache', 'status_header'), 100, 2);
	add_filter('nocache_headers', array('static_cache', 'disable'));
}

add_action('wp_footer', array('sem_cache', 'stats'), 1000000);
add_action('admin_footer', array('sem_cache', 'stats'), 1000000);

add_filter('mod_rewrite_rules', array('sem_cache', 'rewrite_rules'), 1000000);

add_action('admin_menu', array('sem_cache', 'admin_menu'));
add_action('sem_admin_menu_settings', array('sem_cache', 'front_menu'));

function sem_cache_admin() {
 	include dirname(__FILE__) . '/sem-cache-admin.php';
} # sem_cache_admin()

foreach ( array('load-settings_page_sem-cache') as $hook )
	add_action($hook, 'sem_cache_admin');

if ( static_cache || memory_cache || query_cache ) :

add_action('pre_post_update', array('sem_cache', 'pre_flush_post'));

foreach ( array(
	'save_post',
	'delete_post',
	'wp_update_comment_count',
	) as $hook ) {
	add_action($hook, array('sem_cache', 'flush_post'), 1); // before _save_post_hook()
}

foreach ( array(
	'switch_theme',
	'update_option_active_plugins',
	'update_option_show_on_front',
	'update_option_page_on_front',
	'update_option_page_for_posts',
	'update_option_sidebars_widgets',
	'update_option_sem5_options',
	'update_option_sem6_options',
	'generate_rewrite_rules',
	
	'flush_cache',
	'after_db_upgrade',
	
	'update_option_sem_seo',
	'update_option_script_manager',
	) as $hook ) {
	add_action($hook, array('sem_cache', 'flush_cache'));
}

if ( $_POST )
	add_action('load-widgets.php', array('sem_cache', 'flush_cache'));

endif;
?>