<?php
/*
Plugin Name: Semiologic Cache
Plugin URI: http://www.semiologic.com/software/sem-cache/
Description: An advanced caching module for WordPress.
Version: 2.0 beta6
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
	) as $const ) {
	if ( !defined($const) )
		define($const, get_option($const) && defined('WP_CACHE') && WP_CACHE);
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
	 * disable()
	 *
	 * @return void
	 **/

	static function disable() {
		if ( !class_exists('sem_cache_admin') )
			include dirname(__FILE__) . '/sem-cache-admin.php';
		
		sem_cache_admin::disable_static();
		sem_cache_admin::disable_memcached();
		sem_cache_admin::disable_assets();
		sem_cache_admin::disable_gzip();
		
		cache_fs::flush('/');
		
		self::flush_objects();
	} # disable()
	
	
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

<IfModule mod_headers.c>
# Make sure proxies don't deliver the wrong content
Header append Vary User-Agent env=!dont-vary
</IfModule>

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
AddOutputFilterByType DEFLATE application/x-json
AddOutputFilterByType DEFLATE application/x-httpd-php
AddOutputFilterByType DEFLATE application/x-httpd-fastphp
AddOutputFilterByType DEFLATE image/svg+xml

# Drop problematic browsers
BrowserMatch ^Mozilla/4 gzip-only-text/html
BrowserMatch ^Mozilla/4\.0[678] no-gzip

# IE5.x and IE6 get no gzip, but 7+ should
BrowserMatch \bMSIE\s[789] !no-gzip !gzip-only-text/html
# IE 6.0 after SP2 has no gzip bugs
BrowserMatch \bMSIE.+SV !no-gzip
# Opera occasionally pretends to be IE with "Mozilla/4.0"
BrowserMatch \bOpera !no-gzip
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
		wp_cache_flush();
	} # flush_objects()
	
	
	/**
	 * flush_url()
	 *
	 * @param string $url
	 * @return void
	 **/

	function flush_url($link) {
		$cache_id = md5($link);
		
		wp_cache_delete($cache_id, 'url2posts');
		wp_cache_delete($cache_id, 'url2posts_found');
		
		if ( static_cache ) {
			static $permalink_structure;
			if ( !isset($permalink_structure) )
				$permalink_structure = get_option('permalink_structure');
			# 5 min throttling in case the site is getting hammered by comments
			$timeout = !is_admin() && current_filter() == 'wp_update_comment_count' ? 300 : false;
			if ( $permalink_structure ) {
				$path = trim(preg_replace("|^[^/]+://[^/]+|", '', $link), '/');
				cache_fs::flush('/static/' . $path, $timeout, false);
			}
		}
		if ( memory_cache ) {
			wp_cache_delete($cache_id, 'cached_headers');
			wp_cache_delete($cache_id, 'cached_buffers');
		} elseif ( static_cache ) {
			cache_fs::flush('/semi-static/' . $cache_id . '.meta', $timeout, false);
			cache_fs::flush('/semi-static/' . $cache_id . '.html', $timeout, false);
		}
	} # flush_url()
	
	
	/**
	 * flush_feed_url()
	 *
	 * @param string $url
	 * @return void
	 **/

	function flush_feed_url($link) {
		$cache_id = md5($link);
		
		wp_cache_delete($cache_id, 'url2posts');
		wp_cache_delete($cache_id, 'url2posts_found');
		
		if ( memory_cache ) {
			wp_cache_delete($cache_id, 'cached_headers');
			wp_cache_delete($cache_id, 'cached_buffers');
		} elseif ( static_cache ) {
			cache_fs::flush('/semi-static/' . $cache_id . '.meta', $timeout, false);
			cache_fs::flush('/semi-static/' . $cache_id . '.html', $timeout, false);
		}
	} # flush_feed_url()
	
	
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
		
		wp_cache_delete($cache_id, 'url2post_id');
		
		if ( static_cache ) {
			static $permalink_structure;
			if ( !isset($permalink_structure) )
				$permalink_structure = get_option('permalink_structure');
			# 5 min throttling in case the site is getting hammered by comments
			$timeout = !is_admin() && current_filter() == 'wp_update_comment_count' ? 300 : false;
			if ( $permalink_structure ) {
				$path = trim(preg_replace("|^[^/]+://[^/]+|", '', $link), '/');
				cache_fs::flush('/static/' . $path, $timeout, false);
			}
		}
		if ( memory_cache ) {
			wp_cache_delete($cache_id, 'cached_headers');
			wp_cache_delete($cache_id, 'cached_buffers');
		} elseif ( static_cache ) {
			cache_fs::flush('/semi-static/' . $cache_id . '.meta', $timeout, false);
			cache_fs::flush('/semi-static/' . $cache_id . '.html', $timeout, false);
		}
	} # flush_post_url()
	
	
	/**
	 * flush_post()
	 *
	 * @param int $post_id
	 * @param mixed $new
	 * @param mixed $old
	 * @return void
	 **/

	function flush_post($post_id, $new = null, $old = null) {
		static $done = array();
		
		$post_id = (int) $post_id;
		if ( !$post_id )
			return;
		
		if ( isset($done[$post_id]) )
			return;
		
		$done[$post_id] = true;
		
		# prevent mass-flushing when the permalink structure hasn't changed
		remove_action('generate_rewrite_rules', array('sem_cache', 'flush_cache'));
		
		if ( current_filter() == 'wp_update_comment_count' && $new == $old )
			return;
		
		$post = get_post($post_id);
		if ( !$post || wp_is_post_revision($post_id) )
			return;
		
		$old = wp_cache_get($post_id, 'pre_flush_post');
		
		if ( $post->post_status != 'publish' && ( !$old || $old['post_status'] != 'publish' ) && $post->post_type != 'attachment' )
			return;
		
		# flush the post
		self::do_flush_post($post_id);
		
		# flush the home and blog pages
		self::do_flush_home();
		
		if ( $post->post_type == 'post' && in_array(current_filter(), array('save_post', 'delete_post')) ) {
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
		
		foreach ( array_unique($links) as $link ) {
			self::flush_post_url($link);
			for ( $i = 1; $i < $pages; $i++ ) {
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
			self::flush_feed_url($link);
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
			self::flush_feed_url($link);
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
			self::flush_feed_url($link);
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
		if ( get_option('query_cache') && !is_admin() ) {
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
	if ( get_option('query_cache') )
		include dirname(__FILE__) . '/query-cache.php';

	if ( get_option('asset_cache') )
		include dirname(__FILE__) . '/asset-cache.php';
}

if ( class_exists('static_cache') ) {
	add_action('cache_timeout', array('sem_cache', 'cache_timeout'));
	if ( !wp_next_scheduled('cache_timeout') )
		wp_schedule_event(time(), 'hourly', 'cache_timeout');
	if ( sem_cache_debug && ( wp_next_scheduled('cache_timeout') - time() > cache_timeout ) )
		wp_schedule_single_event(time() + cache_timeout, 'cache_timeout');
	
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

if ( static_cache || memory_cache || get_option('query_cache') ) :

add_action('pre_post_update', array('sem_cache', 'pre_flush_post'));

foreach ( array(
	'save_post',
	'delete_post',
	) as $hook ) {
	add_action($hook, array('sem_cache', 'flush_post'), 1); // before _save_post_hook()
}

add_action('wp_update_comment_count', array('sem_cache', 'flush_post'), 1, 3); // before _save_post_hook()

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