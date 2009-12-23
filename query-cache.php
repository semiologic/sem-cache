<?php
/**
 * query_cache
 *
 * @package Semiologic Cache
 **/

class query_cache {
	public $cache_hits = 0;
	
	protected static $wpdb;
	protected static $request;
	protected static $found;
	protected static $cache_id;
	
	
	/**
	 * __construct()
	 *
	 * @return void
	 **/
	
	function __construct($wpdb) {
		self::$wpdb = $wpdb;
		if ( !isset(self::$wpdb->queries) )
			self::$wpdb->queries = array();
		$host = get_option('home');
		if ( preg_match("|^([^/]+://[^/]+)/|", $host, $_host) )
			$host = end($_host);
		self::$cache_id = $host . preg_replace("/#.*/", '', $_SERVER['REQUEST_URI']);
		self::$cache_id = md5(self::$cache_id);
	} # __construct()
	
	
	/**
	 * __isset()
	 *
	 * @param string $var
	 * @return boolean $isset
	 **/
	
	function __isset($var) {
		return isset(self::$wpdb->$var);
	} # __isset()
	
	
	/**
	 * __unset()
	 *
	 * @param string $var
	 * @return boolean $isset
	 **/
	
	function __unset($var) {
		unset(self::$wpdb->$var);
	} # __unset()
	
	
	/**
	 * __get()
	 *
	 * @param string $name
	 * @return mixed $value
	 **/
	
	function __get($var) {
		return self::$wpdb->$var;
	} # __get()
	
	
	/**
	 * __set()
	 *
	 * @param string $var
	 * @param string $value
	 * @return mixed $value
	 **/

	function __set($var, $value) {
		return self::$wpdb->$var = $value;
	} # __set()
	
	
	/**
	 * __call()
	 *
	 * @param string $method
	 * @param array $args
	 * @return mixed $out
	 **/
	
	function __call($method, $args) {
		return call_user_func_array(array(self::$wpdb, $method), $args);
	} # __call()
	
	
	/**
	 * get_var()
	 *
	 * @param string $query
	 * @param int $x which col
	 * @param int $y which row
	 * @return mixed $var
	 **/

	function get_var($query = null, $x = 0, $y = 0) {
		if ( !$query || $x || $y )
			return self::$wpdb->get_var($query, $x, $y);
		
		global $wpdb;
		global $wp_the_query;
		$var = false;
		
		if ( self::$found !== false && $query == 'SELECT FOUND_ROWS()' ) {
			$var = self::$found;
			$this->cache_hits++;
		} elseif ( $wp_the_query->is_page && preg_match("/^SELECT ID FROM $wpdb->posts WHERE post_parent = (\d+) AND post_type = 'page' LIMIT 1$/", $query, $post_id) ) {
			$post_id = end($post_id);
			$var = $this->get_body_class($post_id);
		} elseif ( preg_match("/SELECT `post_parent` FROM $wpdb->posts WHERE ID = (\d+) LIMIT 1/", $query, $post_id) ) {
			$post_id = end($post_id);
			$var = $this->get_post_parent($post_id);
		}
		
		return $var !== false ? $var : self::$wpdb->get_var($query);
	} # get_var()
	
	
	/**
	 * get_body_class()
	 *
	 * @param int $post_id
	 * @return int $is_parent
	 **/

	function get_body_class($post_id) {
		$var = false;
		$post_id = (int) $post_id;
		$post = wp_cache_get($post_id, 'posts');
		if ( $post !== false ) {
			if ( isset($post->is_parent) ) {
				$var = $post->is_parent;
				$this->cache_hits++;
			} else {
				$var = (int) self::$wpdb->get_var($query);
				$post->is_parent = $var;
				wp_cache_replace($post->ID, $post, 'posts');
			}
		}
		return $var;
	} # get_body_class()
	
	
	/**
	 * get_post_parent()
	 *
	 * @param int $post_id
	 * @return int $parent_id
	 **/

	function get_post_parent($post_id) {
		$var = false;
		$post_id = (int) $post_id;
		$post = wp_cache_get($post_id, 'posts');
		if ( $post !== false ) {
			$var = $post->post_parent;
			$this->cache_hits++;
			# http://core.trac.wordpress.org/ticket/10381
			if ( !isset($post->ancestors) && $var && $var != $post->ID )
				wp_cache_delete($post_id, 'posts');
		}
		return $var;
	} # get_post_parent()
	
	
	/**
	 * get_row()
	 *
	 * @param string $query
	 * @param string $output format
	 * @param int $y which row
	 * @return mixed $row
	 **/

	function get_row($query = null, $output = OBJECT, $y = 0) {
		if ( !$query || $output != OBJECT || $y  )
			return self::$wpdb->get_row($query, $output, $y);
		
		global $wpdb;
		$row = false;
		
		if ( preg_match("/^SELECT ID, post_name, post_parent FROM $wpdb->posts WHERE ID = (\d+) and post_type='page'$/", $query, $post_id) ) {
			# fetch parent page in get_page_by_path()
			$post_id = (int) end($post_id);
			$row = wp_cache_get($post_id, 'posts');
			if ( $row !== false )
				$this->cache_hits++;
		}
		
		return $row !== false ? $row : self::$wpdb->get_row($query);
	} # get_row()
	
	
	/**
	 * get_results()
	 *
	 * @param string $query
	 * @param string $output format
	 * @return array $results
	 **/

	function get_results($query = null, $output = OBJECT) {
		if ( !$query || $output != OBJECT )
			return self::$wpdb->get_results($query, $output);
		
		global $wpdb;
		global $wp_the_query;
		$results = false;
		
		if ( self::$request && $query == self::$request ) {
			$results = $this->get_posts($query);
		} elseif ( $wp_the_query->is_page && !$wp_the_query->in_the_loop && preg_match("/^SELECT ID, post_name, post_parent FROM $wpdb->posts WHERE post_name = '[^']+' AND \(post_type = 'page' OR post_type = 'attachment'\)$/", $query) ) {
			$results = $this->get_page_by_path($query);
		} elseif ( $wp_the_query->in_the_loop && preg_match("/^SELECT \* FROM $wpdb->comments WHERE comment_post_ID = (\d+) AND comment_approved = '1' ORDER BY comment_date_gmt ASC ?$/", $query, $post_id) ) {
			$post_id = end($post_id);
			$results = $this->get_cached_comments($post_id);
		} elseif ( $wp_the_query->in_the_loop && preg_match("/^SELECT \* FROM $wpdb->comments WHERE comment_post_ID = (\d+) AND \( ?comment_approved = '1' OR \( .+? AND comment_approved = '0' \) \)  ?ORDER BY comment_date_gmt$/", $query, $post_id) ) {
			$post_id = end($post_id);
			$results = $this->get_uncached_comments($post_id);
		}
		
		return $results !== false ? $results : self::$wpdb->get_results($query);
	} # get_results()
	
	
	/**
	 * get_cached_comments()
	 *
	 * @param int $post_id
	 * @return array $comments
	 **/

	function get_cached_comments($post_id) {
		$post_id = (int) $post_id;
		$results = wp_cache_get($post_id, 'cached_comments');
		
		if ( $results !== false ) {
			$this->cache_hits++;
		} else {
			global $wpdb;
			$results = self::$wpdb->get_results("SELECT * FROM $wpdb->comments WHERE comment_post_ID = $post_id AND comment_approved = '1' ORDER BY comment_date_gmt ASC");
			wp_cache_set($post_id, $results, 'cached_comments');
		}
		
		return $results;
	} # get_cached_comments()
	
	
	/**
	 * get_uncached_comments()
	 *
	 * @param int $post_id
	 * @return array $comments
	 **/

	function get_uncached_comments($post_id) {
		$post_id = (int) $post_id;
		$no_cache = wp_cache_get($post_id, 'uncached_comments');
		
		if ( $no_cache === false ) {
			global $wpdb;
			$no_cache = (int) self::$wpdb->get_var("SELECT EXISTS( SELECT 1 FROM $wpdb->comments WHERE comment_post_ID = $post_id AND comment_approved = '0' )");
			wp_cache_add($post_id, $no_cache, 'uncached_comments');
		}
		
		return $no_cache ? false : $this->get_cached_comments($post_id);
	} # get_uncached_comments()
	
	
	/**
	 * get_page_by_path()
	 *
	 * @param string $query
	 * @return array $results
	 **/

	function get_page_by_path($query) {
		$results = false;
		
		$post_id = wp_cache_get(self::$cache_id, 'url2post_id');
		if ( $post_id === 0 ) {
			$results = array();
			$this->cache_hits++;
		} elseif ( $post_id ) {
			$post = wp_cache_get($post_id, 'posts');
			if ( $post !== false ) {
				$results = array($post);
				$this->cache_hits++;
			}
		}
		
		return $results;
	} # get_page_path()
	
	
	/**
	 * get_posts()
	 *
	 * @param string $query
	 * @return array $results
	 **/

	function get_posts($query) {
		global $wpdb;
		global $wp_query;
		global $wp_the_query;
		$results = false;
		
		if ( $wp_query !== $wp_the_query || $query != self::$request ) {
			# do nothing: it's not reliably cacheable
			return $results;
		} elseif ( $wp_query->is_singular ) {
			$post_id = $wp_query->get_queried_object_id();
			if ( !$post_id )
				$post_id = wp_cache_get(self::$cache_id, 'url2post_id');
			if ( $post_id === 0 ) {
				$results = array();
				$this->cache_hits++;
			} elseif ( $post_id ) {
				$post = wp_cache_get($post_id, 'posts');
				if ( $post !== false ) {
					$results = array($post);
					$this->cache_hits++;
				}
			}
		} elseif( $wp_query->is_preview || isset($_GET['trashed']) ) {
			# bail: we don't want to cache this stuff
		} elseif ( strpos($query, "'private'") !== false ) {
			# bail: queries that return private posts can't efficiently be cached
		} else {
			$posts = wp_cache_get(self::$cache_id, 'url2posts');
			$found = wp_cache_get(self::$cache_id, 'url2posts_found');
			if ( $posts !== false && $found !== false ) {
				$results = $posts;
				self::$found = $found;
				$this->cache_hits++;
			} elseif ( $wp_query->is_home || $wp_query->is_category || $wp_query->is_tag || $wp_query->is_author || $wp_query->is_date || $wp_query->is_feed && !$wp_query->is_singular && !$wp_query->is_archive /* home feed */ ) {
				$results = self::$wpdb->get_results($query);
				self::$found = self::$wpdb->get_var("SELECT FOUND_ROWS()");
				if ( !$wp_query->is_paged && !$wp_query->is_feed && !isset($_GET['debug']) ) {
					# no timeout: these requests can be efficiently flushed
					wp_cache_add(self::$cache_id, $results, 'url2posts');
					wp_cache_add(self::$cache_id, self::$found, 'url2posts_found');
				} else {
					# add a timeout: these requests are resource intensive to flush
					wp_cache_add(self::$cache_id, $results, 'url2posts', cache_timeout);
					wp_cache_add(self::$cache_id, self::$found, 'url2posts_found', cache_timeout);
				}
			}
		}
		
		return $results;
	} # get_posts()
	
	
	/**
	 * posts_request()
	 *
	 * @param string $posts_request
	 * @return string $posts_request
	 **/

	static function posts_request($posts_request) {
		global $wpdb;
		global $wp_query;
		global $wp_the_query;
		
		if ( $wp_query->is_preview )
			return $posts_request;
		
		if ( !$wp_query->is_singular && is_user_logged_in() ) {
			# optimize the query a bit
			$user = wp_get_current_user();
			$cap = 'posts';
			if ( preg_match("/AND $wpdb->posts.post_type = '([^']+)'/", $posts_request, $cap) ) {
				$cap = end($cap);
				if ( $cap == 'page' )
					$cap = 'pages';
				else
					$cap = 'posts';
				
				$strip = false;
				if ( current_user_can("read_private_$cap") ) {
					if ( !self::has_private_posts() )
						$strip = " OR $wpdb->posts.post_status = 'private'";
				} elseif ( !current_user_can("edit_$cap") || !self::has_private_posts($user->ID) ) {
					$strip = " OR $wpdb->posts.post_author = $user->ID AND $wpdb->posts.post_status = 'private'";
				}
				
				$posts_request = str_replace($strip, '', $posts_request);
			}
		}
		
		if ( $wp_query === $wp_the_query && !isset(self::$request) ) {
			self::$request = $posts_request;
			self::$found = false;
		}
		
		return $posts_request;
	} # posts_request()
	
	
	/**
	 * posts_results()
	 *
	 * @param array $posts
	 * @return array $posts
	 **/

	static function posts_results($posts) {
		global $wp_query;
		
		if ( $wp_query->is_preview || isset($_GET['trashed']) )
			return $posts;
		
		if ( $wp_query->is_singular ) {
			$post_id = wp_cache_get(self::$cache_id, 'url2post_id');
			
			if ( $post_id === false ) {
				if ( !$posts )
					$post_id = 0;
				else
					$post_id = $posts[0]->ID;
				if ( !$wp_query->is_paged && !$wp_query->is_feed && !isset($_GET['debug']) )
					wp_cache_add(self::$cache_id, $post_id, 'url2post_id');
				elseif ( $wp_query->is_feed )
					wp_cache_add(self::$cache_id, $post_id, 'url2post_id', 1800);
				else
					wp_cache_add(self::$cache_id, $post_id, 'url2post_id', cache_timeout);
			}
		}
		
		return $posts;
	} # posts_results()
	
	
	/**
	 * found_posts()
	 *
	 * @param int $num_posts
	 * @return int $num_posts
	 **/

	function found_posts($num_posts) {
		global $wp_query;
		global $wp_the_query;
		
		if ( $wp_query->is_preview || isset($_GET['trashed']) )
			return $num_posts;
		
		if ( $wp_query === $wp_the_query && self::$request == $posts_request ) {
			self::$request = false;
			self::$found = false;
		}
		return $num_posts;
	} # found_posts()
	
	
	/**
	 * has_private_posts()
	 *
	 * @param int $user_id
	 * @return bool
	 **/

	static function has_private_posts($user_id = null) {
		global $wpdb;
		
		$has_private_posts = get_transient('has_private_posts');
		if ( $has_private_posts === false ) {
			$has_private_posts = intval($wpdb->get_var("SELECT EXISTS( SELECT 1 FROM $wpdb->posts WHERE post_status = 'private' );"));
			set_transient('has_private_posts', $has_private_posts);
		}
		
		if ( !$user_id )
			return $has_private_posts;
		elseif ( !$has_private_posts )
			return false;
		
		$user_id = intval($user_id);
		$has_private_posts = get_usermeta($user_id, 'has_private_posts');
		
		if ( $has_private_posts )
			return true;
		elseif ( $has_private_posts === array() )
			return false;
		
		$has_private_posts = intval($wpdb->get_var("SELECT EXISTS( SELECT 1 FROM $wpdb->posts WHERE post_status = 'private' AND user_id = $user_id );"));
		if ( !$has_private_posts )
			$has_private_posts = array();
		
		update_usermeta($user_id, 'has_private_posts', $has_private_posts);
		
		return !empty($has_private_posts);
	} # has_private_posts()
} # query_cache

if ( ! $wpdb instanceof query_cache && $wpdb instanceof wpdb ) {
	$wpdb = new query_cache($wpdb);
	add_filter('posts_request', array('query_cache', 'posts_request'));
	add_filter('posts_results', array('query_cache', 'posts_results'));
	add_filter('found_posts', array('query_cache', 'found_posts'));
}
?>