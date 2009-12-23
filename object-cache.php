<?php
function wp_cache_add($key, $data, $flag = '', $expire = 0) {
	global $wp_object_cache;

	return $wp_object_cache->add($key, $data, $flag, $expire);
}

function wp_cache_incr($key, $n = 1, $flag = '') {
	global $wp_object_cache;

	return $wp_object_cache->incr($key, $n, $flag);
}

function wp_cache_decr($key, $n = 1, $flag = '') {
	global $wp_object_cache;

	return $wp_object_cache->decr($key, $n, $flag);
}

function wp_cache_close() {
	global $wp_object_cache;

	return $wp_object_cache->close();
}

function wp_cache_delete($id, $flag = '') {
	global $wp_object_cache;

	return $wp_object_cache->delete($id, $flag);
}

function wp_cache_flush() {
	global $wp_object_cache;

	return $wp_object_cache->flush();
}

function wp_cache_get($id, $flag = '') {
	global $wp_object_cache;

	return $wp_object_cache->get($id, $flag);
}

function wp_cache_init() {
	global $wp_object_cache;

	if ( !is_object($wp_object_cache) )
		$wp_object_cache = new object_cache;
}

function wp_cache_replace($key, $data, $flag = '', $expire = 0) {
	global $wp_object_cache;

	return $wp_object_cache->replace($key, $data, $flag, $expire);
}

function wp_cache_set($key, $data, $flag = '', $expire = 0) {
	global $wp_object_cache;

	if ( !defined('WP_INSTALLING') )
		return $wp_object_cache->set($key, $data, $flag, $expire);
	else
		return $wp_object_cache->delete($key, $flag);
}

function wp_cache_add_global_groups( $groups ) {
	global $wp_object_cache;

	$wp_object_cache->add_global_groups($groups);
}

function wp_cache_add_non_persistent_groups( $groups ) {
	global $wp_object_cache;

	$wp_object_cache->add_non_persistent_groups($groups);
}

class object_cache {
	var $global_groups = array('users', 'userlogins', 'usermeta', 'site-options', 'site-lookup', 'blog-lookup', 'blog-details', 'rss');

	var $no_mc_groups = array('comment', 'counts');

	var $autoload_groups = array('options');

	var $cache = array();
	var $mc = array();

	var $default_expiration = 0;

	function add($id, $data, $group = 'default', $expire = 0) {
		$key = $this->key($id, $group);
		
		if ( in_array($group, $this->no_mc_groups) ) {
			$this->cache[$key] = $data;
			return true;
		} elseif ( isset($this->cache[$key]) && $this->cache[$key] !== false ) {
			return false;
		}
		
		$mc =& $this->get_mc($group);
		$expire = ($expire == 0) ? $this->default_expiration : $expire;
		
		# http://core.trac.wordpress.org/ticket/11431
		if ( $group == 'terms' && !is_numeric($id) && !$expire )
			$expire = 86400;
		
		$result = $mc->add($key, $data, false, $expire);
		
		if ( false !== $result )
			$this->cache[$key] = $data;
		
		return $result;
	}

	function add_global_groups($groups) {
		if ( ! is_array($groups) )
			$groups = (array) $groups;

		$this->global_groups = array_merge($this->global_groups, $groups);
		$this->global_groups = array_unique($this->global_groups);
	}

	function add_non_persistent_groups($groups) {
		if ( ! is_array($groups) )
			$groups = (array) $groups;

		$this->no_mc_groups = array_merge($this->no_mc_groups, $groups);
		$this->no_mc_groups = array_unique($this->no_mc_groups);
	}

	function incr($id, $n, $group) {
		$key = $this->key($id, $group);
		$mc =& $this->get_mc($group);

		return $mc->increment($key, $n);
	}

	function decr($id, $n, $group) {
		$key = $this->key($id, $group);
		$mc =& $this->get_mc($group);

		return $mc->decrement($key, $n);
	}

	function close() {

		foreach ( $this->mc as $bucket => $mc )
			$mc->close();
	}

	function delete($id, $group = 'default') {
		$key = $this->key($id, $group);

		if ( in_array($group, $this->no_mc_groups) ) {
			unset($this->cache[$key]);
			return true;
		}

		$mc =& $this->get_mc($group);

		$result = $mc->delete($key);

		if ( false !== $result )
			unset($this->cache[$key]);

		return $result; 
	}

	function flush() {
		static $done = false;
		if ( $done )
			return false;
		
		# can't flush if WP isn't loaded
		if ( !function_exists('get_option') )
			return false;
		
		$done = true;
		global $wpdb;
		
		# flush posts
		$posts = $wpdb->get_results("SELECT ID, post_title, post_name, post_date, post_type, post_status, post_author FROM $wpdb->posts WHERE post_status IN ('publish', 'private') OR post_type = 'attachment'");
		
		# force a widget flush
		if ( $post = current($posts) )
			do_action('save_post', $post->ID, $post);
		
		$post_ids = array();
		foreach ( $posts as $post ) {
			$post_ids[] = $post->ID;
			$this->delete($post->ID, 'posts');
			$this->delete($post->ID, 'post_meta');
			clean_object_term_cache($post->ID, 'post');
			do_action('clean_post_cache', $post->ID);
			# fill a temporary bucket so as to handle permalinks
			$key = $this->key($post->ID, 'posts');
			$this->cache[$key] = $post;
			
			if ( $post->post_type == 'post' ) {
				sem_cache::do_flush_author($post->post_author);
				sem_cache::do_flush_date($post->post_date);
			}
		}
		
		unset($posts);
		
		# flush get_permalink() intensive stuff before flushing terms
		foreach ( $post_ids as $post_id )
			sem_cache::do_flush_post($post_id);
		
		# flush home
		sem_cache::do_flush_home();
		
		# flush terms
		$terms = $wpdb->get_results("SELECT term_id, taxonomy FROM $wpdb->term_taxonomy WHERE count > 0");
		$taxonomies = array();
		$term_ids = array();
		foreach ( $terms as $term )
			sem_cache::do_flush_term($term->term_id, $term->taxonomy);
		foreach ( $terms as $term ) {
			$taxonomies[] = $term->taxonomy;
			$term_ids[] = $term->term_id;
			$this->delete($term->term_id, $term->taxonomy);
		}
		$taxonomies = array_unique($taxonomies);
		
		foreach ( $taxonomies as $taxonomy ) {
			$this->delete('all_ids', $taxonomy);
			$this->delete('get', $taxonomy);
			delete_option("{$taxonomy}_children");
			do_action('clean_term_cache', $term_ids, $taxonomy);
		}
		
		$this->set('last_changed', time(), 'terms');
		
		unset($terms);
		
		# flush users
		$user_ids = $wpdb->get_col("SELECT ID FROM $wpdb->users");
		foreach ( $user_ids as $user_id )
			$this->delete($user_id, 'users');
		
		unset($user_ids);
		
		# backup key transients
		$transients = array(
			'update_core',
			'update_plugins',
			'update_themes',
			'sem_memberships',
			'sem_update_plugins',
			'sem_update_themes',
			);
		
		$extra = array(
			'feed_220431e2eb0959fa9c7fcb07c6e22632', # sem news
			'feed_mod_220431e2eb0959fa9c7fcb07c6e22632', # sem_news timeout
			);
		
		foreach ( array_merge($transients, $extra) as $var )
			$$var = get_transient($var);
		
		# flush options
		$options = $wpdb->get_col("SELECT option_name FROM $wpdb->options");
		foreach ( $options as $option ) {
			if ( !in_array($option, array_merge($transients, $extra)) ) {
				if ( preg_match("/^_transient_/", $option) )
					delete_option($option);
				else
					$this->delete($option, 'options');
			}
		}
		$this->delete('notoptions', 'options');
		
		unset($options);
		
		# restore key transients
		foreach ( $transients as $var ) {
			if ( $$var !== false )
				set_transient($var, $$var);
		}
		
		if ( $feed_220431e2eb0959fa9c7fcb07c6e22632 !== false ) {
			$var = 'feed_220431e2eb0959fa9c7fcb07c6e22632';
			set_transient($var, $$var, 3600);
			$var = 'feed_mod_220431e2eb0959fa9c7fcb07c6e22632';
			set_transient($var, time(), 3600);
		}
		
		return true;
	}

	function get($id, $group = 'default') {
		$key = $this->key($id, $group);
		
		$mc =& $this->get_mc($group);
		
		if ( isset($this->cache[$key]) ) {
			$value = $this->cache[$key];
			$this->cache_hits++;
		} elseif ( in_array($group, $this->no_mc_groups) ) {
			$value = false;
		} else {
			$value = $mc->get($key);
			if ( $value !== false || ( $id == 'notoptions' && $group == 'options') )
				$this->cache_hits++;
			else
				$this->cache_misses++;
		}

		if ( is_null($value) )
			$value = false;
		$this->cache[$key] = $value;
		
		if ( 'checkthedatabaseplease' === $value )
			$value = false;
		
		return $value;
	}

	function key($key, $group) {	
		if ( empty($group) )
			$group = 'default';

		if ( false !== array_search($group, $this->global_groups) )
			$prefix = $this->global_prefix;
		else
			$prefix = $this->blog_prefix;

		return preg_replace('/\s+/', '', "$prefix$group:$key");
	}

	function replace($id, $data, $group = 'default', $expire = 0) {
		$key = $this->key($id, $group);
		if ( in_array($group, $this->no_mc_groups) ) {
			$this->cache[$key] = $data;
			return true;
		}
		$expire = ($expire == 0) ? $this->default_expiration : $expire;
		$mc =& $this->get_mc($group);
		$result = $mc->replace($key, $data, false, $expire);
		if ( false !== $result )
			$this->cache[$key] = $data;
		return $result;
	}

	function set($id, $data, $group = 'default', $expire = 0) {
		$key = $this->key($id, $group);
		if ( isset($this->cache[$key]) && ('checkthedatabaseplease' === $this->cache[$key]) )
			return false;
		$this->cache[$key] = $data;

		if ( in_array($group, $this->no_mc_groups) )
			return true;

		$expire = ($expire == 0) ? $this->default_expiration : $expire;
		$mc =& $this->get_mc($group);
		$result = $mc->set($key, $data, false, $expire);

		return $result;
	}

	function &get_mc($group) {
		if ( isset($this->mc[$group]) )
			return $this->mc[$group];
		return $this->mc['default'];
	}

	function failure_callback($host, $port) {
		//error_log("Connection failure for $host:$port\n", 3, '/tmp/memcached.txt');
	}

	function object_cache() {
		global $memcached_servers;

		if ( isset($memcached_servers) )
			$buckets = $memcached_servers;
		else
			$buckets = array('127.0.0.1');
		
		reset($buckets);
		if ( is_int(key($buckets)) )
			$buckets = array('default' => $buckets);
		
		foreach ( $buckets as $bucket => $servers) {
			$this->mc[$bucket] = new Memcache();
			foreach ( $servers as $server  ) {
				list ( $node, $port ) = explode(':', $server);
				if ( !$port )
					$port = ini_get('memcache.default_port');
				$port = intval($port);
				if ( !$port )
					$port = 11211;
				$this->mc[$bucket]->addServer($node, $port, true, 1, 1, 15, true, array($this, 'failure_callback'));
				$this->mc[$bucket]->setCompressThreshold(20000, 0.2);
			}
		}
		
		global $table_prefix;
		$this->blog_prefix = DB_NAME . ':' . $table_prefix;
		if ( function_exists('is_site_admin') || defined('CUSTOM_USER_TABLE') && defined('CUSTOM_USER_META_TABLE') )
			$this->global_prefix = DB_NAME;
		else
			$this->global_prefix = $this->blog_prefix;
	}
} # object_cache

$wp_object_cache = new object_cache;
?>