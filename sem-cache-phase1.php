<?php
if( !@include(WP_CONTENT_DIR . '/sem-cache-config.php') ) {
	return;
}
if( !defined( 'sem_cache_path' ) )
	define('sem_cache_path', dirname(__FILE__));

include_once sem_cache_path . '/sem-cache-base.php';

$mutex_filename = 'wp_cache_mutex.lock';
$new_cache = false;


// Don't change variables behind this point

if (!$cache_enabled || $_SERVER["REQUEST_METHOD"] == 'POST' ) 
	return;

$file_expired = false;
$cache_filename = '';
$meta_file = '';

$key = $blogcacheid . md5($_SERVER['HTTP_HOST'].preg_replace('/#.*$/', '', $_SERVER['REQUEST_URI']).wp_cache_get_cookies_values());

$cache_filename = $file_prefix . $key . '.html';
$meta_file = $file_prefix . $key . '.meta';
$cache_file = realpath( $cache_path . $cache_filename );
$meta_pathname = realpath( $cache_path . 'meta/' . $meta_file );

$wp_start_time = microtime();
if( ($mtime = @filemtime($meta_pathname)) ) {
	if ($mtime + $cache_max_time > time() ) {
		$meta = new CacheMeta;
		if (! ($meta = unserialize(@file_get_contents($meta_pathname))) ) 
			return;
		foreach ($meta->headers as $header) {
			header($header);
		}
		if ( !($content_size = @filesize($cache_file)) > 0 || $mtime < @filemtime($cache_file))
			return;
		if ($meta->dynamic) {
			include($cache_file);
		} else {
			/* No used to avoid problems with some PHP installations
			$content_size += strlen($log);
			header("Content-Length: $content_size");
			*/
			if(!@readfile ($cache_file)) 
				return;
		}
		die;
	}
	$file_expired = true; // To signal this file was expired
}

function wp_cache_postload() {
	global $cache_enabled;

	if (!$cache_enabled) 
		return;
	require_once sem_cache_path . '/sem-cache-phase2.php';
	wp_cache_phase2();
}

function wp_cache_get_cookies_values() {
	$string = '';
	while ($key = key($_COOKIE)) {
		if (preg_match("/^wp-postpass|^wordpress|^comment_author_email_/", $key)) {
			$string .= $_COOKIE[$key] . ",";
		}
		next($_COOKIE);
	}
	reset($_COOKIE);
	if( $string != '' )
		return $string;

	return $string;
}

?>