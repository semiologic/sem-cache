<?php
/*
sem-cache Config Sample File

See sem-cache.php for author details.
*/

// these variables can be set using the admin interface

define( 'sem_cache_path', dirname(__FILE__) . '/plugins/sem-cache' );

$cache_enabled = false;
$super_cache_enabled = false;
$cache_rejected_uri = array();


// these variables can only be set by manually editing this file

$cache_debug = false;
$cache_max_time = 3600; // an hour, in seconds
$super_cache_max_time = 21600; // 6 hours, in seconds
$cache_path = ABSPATH . 'wp-content/cache/';
$file_prefix = 'wp-cache-';

// Change the sem_id value if you have conflicts with semaphores
$sem_id = 5419;

//$use_flock = true; // Set it true or false if you know what to use


// the rest should not be edited

// We want to be able to identify each blog in a WordPress MU install
$blogcacheid = '';
if( defined( 'VHOST' ) ) {
	$blogcacheid = 'blog'; // main blog
	if( constant( 'VHOST' ) == 'yes' ) {
		$blogcacheid = $_SERVER['HTTP_HOST'];
	} else {
		$request_uri = preg_replace('/[ <>\'\"\r\n\t\(\)]/', '', str_replace( '..', '', $_SERVER['REQUEST_URI'] ) );
		if( strpos( $request_uri, '/', 1 ) ) {
			if( $base == '/' ) {
				$blogcacheid = substr( $request_uri, 1, strpos( $request_uri, '/', 1 ) - 1 );
			} else {
				$blogcacheid = str_replace( $base, '', $request_uri );
				$blogcacheid = substr( $blogcacheid, 0, strpos( $blogcacheid, '/', 1 ) );
			}
			if ( '/' == substr($blogcacheid, -1))
				$blogcacheid = substr($blogcacheid, 0, -1);
		}
	}
}

if ( '/' != substr($cache_path, -1)) {
	$cache_path .= '/';
}

?>