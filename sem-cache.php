<?php
/*
Plugin Name: Semiologic Cache
Plugin URI: http://www.semiologic.com/software/wp-tweaks/sem-cache/
Description: An advanced caching module for WordPress.
Version: 1.1
Author: Denis de Bernardy
Author URI: http://www.semiologic.com
*/
/*  Copyright 2005-2006  Ricardo Galli Granada  (email : gallir@uib.es)
	Copyright 2007-2008  Donncha O Caoimh  (http://ocaoimh.ie/)
	Copyright 2008       Denis de Bernardy  (http://www.mesoconcepts.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

global $wp_cache_config_file, $wp_cache_link, $wp_cache_config_file_sample, $wp_cache_file;

global $cache_enabled, $super_cache_enabled, $cache_rejected_uri, $cache_debug, $cache_max_time, $super_cache_max_time, $cache_path, $file_prefix, $sem_id, $use_flock, $blogcacheid;

$wp_cache_config_file = ABSPATH . 'wp-content/sem-cache-config.php';
$wp_cache_link = ABSPATH . 'wp-content/advanced-cache.php';

if ( !defined('sem_cache_path') )
{
	if ( !@include_once($wp_cache_config_file) )
	{
		define('sem_cache_path', dirname(__FILE__));
		
		$wp_cache_config_file_sample = sem_cache_path . '/sem-cache-config-sample.php';
		@include($wp_cache_config_file_sample);
	}
}

$wp_cache_config_file_sample = sem_cache_path . '/sem-cache-config-sample.php';
$wp_cache_file = sem_cache_path . '/sem-cache-phase1.php';

include_once sem_cache_path . '/sem-cache-base.php';
include_once sem_cache_path . '/sem-cache-phase2.php';


function comment_form_lockdown_message()
{
	echo '<p><strong>Notice</strong>: A cache module is enabled on this site. Your comment may take some time to appear.</p>' . "\n";
}

if( defined( 'WPLOCKDOWN' ) && WPLOCKDOWN )
	add_action( 'comment_form', 'comment_form_lockdown_message' );


if ( is_admin() )
{
	include sem_cache_path . '/sem-cache-admin.php';
}


function wp_cache_activate()
{
	require_once sem_cache_path . '/sem-cache-admin.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-includes/rewrite.php';
	
	if ( !isset($GLOBALS['wp_rewrite']) ) $GLOBALS['wp_rewrite'] =& new WP_Rewrite;
	
	$home_path = get_home_path();
	wpsc_remove_marker( $home_path.'.htaccess', 'WPSuperCache' );
	
	if ( wp_cache_can_cache() ) wp_cache_enable();
}

function wp_cache_deactivate()
{
	require_once sem_cache_path . '/sem-cache-admin.php';
	
	if ( wp_cache_can_cache() )
	{
		global $wp_cache_link;

		wp_cache_disable();
		@unlink($wp_cache_link);
	}
}

register_activation_hook(__FILE__, 'wp_cache_activate');
register_deactivation_hook(__FILE__, 'wp_cache_deactivate');
?>