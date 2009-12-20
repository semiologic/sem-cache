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
	var $group_ops = array();

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
		
		if ( false !== $result ) {
			$this->group_ops[$group][] = "add $id";
			$this->cache[$key] = $data;
		}
		
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

		$this->group_ops[$group][] = "delete $id";

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
		
		# flush and warm up posts
		$posts = $wpdb->get_results("SELECT * FROM $wpdb->posts WHERE post_status IN ('publish', 'private') OR post_type = 'attachment'");
		
		$post_ids = array();
		foreach ( $posts as $post ) {
			$post_ids[] = $post->ID;
			$this->delete($post->ID, 'posts');
			$this->delete($post->ID, 'post_meta');
			clean_object_term_cache($post->ID, 'post');
			do_action('clean_post_cache', $post->ID);
			$this->add($post->ID, $post, 'posts');
		}
		
		unset($posts);
		
		$cache = array();
		$meta_list = $wpdb->get_results("SELECT post_id, meta_key, meta_value FROM $wpdb->postmeta JOIN $wpdb->posts ON $wpdb->posts.ID = $wpdb->postmeta.post_id WHERE $wpdb->posts.post_status IN ('publish', 'private') OR $wpdb->posts.post_type = 'attachment';", ARRAY_A);
		
		foreach ( (array) $meta_list as $metarow) {
			$mpid = (int) $metarow['post_id'];
			$mkey = $metarow['meta_key'];
			$mval = $metarow['meta_value'];

			// Force subkeys to be array type:
			if ( !isset($cache[$mpid]) || !is_array($cache[$mpid]) )
				$cache[$mpid] = array();
			if ( !isset($cache[$mpid][$mkey]) || !is_array($cache[$mpid][$mkey]) )
				$cache[$mpid][$mkey] = array();

			// Add a value to the current pid/key:
			$cache[$mpid][$mkey][] = $mval;
		}
		
		foreach ( $post_ids as $post_id ) {
			if ( !isset($cache[$post_id]) )
				$cache[$post_id] = array();
			$this->set($post_id, $cache[$post_id], 'post_meta');
		}
		
		unset($cache, $meta_list);
		
		# flush and warm up terms
		$terms = $wpdb->get_results("SELECT term_id, taxonomy FROM $wpdb->term_taxonomy");
		$taxonomies = array();
		$term_ids = array();
		foreach ( (array) $terms as $term ) {
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
		
		$terms = $wpdb->get_results("SELECT t.*, tt.* FROM $wpdb->terms as t JOIN $wpdb->term_taxonomy as tt ON t.term_id = tt.term_id AND taxonomy = 'category' AND count > 0");
		foreach ( $terms as $term )
			$this->set($term->term_id, $term, $term->taxonomy);
		
		# users and options don't need to be flushed, nor do front-end items such as widgets
		
		# we can now flush get_permalink() intensive stuff
		foreach ( $post_ids as $post_id )
			sem_cache::flush_post($post_id);
		
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

		$this->group_ops[$group][] = "get $id";
		
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
		
		global $blog_id, $table_prefix;
		
		if ( function_exists('is_site_admin') ) {
			$this->blog_prefix = $blog_id;
			$this->global_prefix = '';
		} else {
			$this->blog_prefix = DB_NAME . ':' . $table_prefix;
			if ( defined('CUSTOM_USER_TABLE') && defined('CUSTOM_USER_META_TABLE') )
				$this->global_prefix = DB_NAME;
			else
				$this->global_prefix = $this->blog_prefix;
		}
	}
} # object_cache

$wp_object_cache = new object_cache;
?>