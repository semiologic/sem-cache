<?php

function wp_cache_add_pages()
{
	if( function_exists( 'is_site_admin' ) ) {
		if( is_site_admin() ) {
			add_submenu_page('wpmu-admin.php', __('Cache'), __('Cache'), 'manage_options', __FILE__, 'wp_cache_manager');
			add_options_page('Cache', 'Cache', 'manage_options', __FILE__, 'wp_cache_manager');
		}
	} else {
		add_options_page('Cache', 'Cache', 'manage_options', __FILE__, 'wp_cache_manager');
	}
}

add_action('admin_menu', 'wp_cache_add_pages');


function wp_cache_manager()
{
	if( function_exists( 'is_site_admin' ) && !is_site_admin() ) return;

	global $wp_cache_config_file, $valid_nonce, $supercachedir, $cache_path, $cache_enabled, $super_cache_enabled, $cache_rejected_uri, $file_prefix, $supercachedir;
	
	global $wp_rewrite;

	$supercachedir = $cache_path . 'supercache/' . preg_replace('/:.*$/', '',  $_SERVER["HTTP_HOST"]);
	$valid_nonce = wp_verify_nonce($_REQUEST['_wpnonce'], 'sem-cache');

	echo '<div class="wrap">';

	if ( ini_get( 'safe_mode' ) )
	{
		$disable_form = true;

		?><div class="error">
		<h3>safe_mode Error</h3>
		<p>php safe_mode is enabled. Please <a href="http://www.semiologic.com/resources/wp-basics/">get a real host</a>.</p>
		</div>
		<?php
	}
	elseif ( !wp_cache_can_cache() )
	{
		$disable_form = true;
		
		?>
		<div class="error">
		<h3>Cache Error</h3>
		<p>To work, the sem-cache needs to be able to modify a file and a folder:</p>
		<ul>
		<li><code>/wp-config.php</code> (chmod 666), to turn the cache on or off</li>
		<li><code>/wp-content/</code> (chmod 777), to actually use the cache</li>
		</ul>
		<p><code>Please give write access on the server accordingly.</code></p>
		<br />
		<p>If permissions are set correctly, please delete the cache file <code>/wp-content/advanced-cache.php</code>.</p>
		</div>
		<?php
	}
	else
	{
		$disable_form = false;
	}
	
	$lockdown = defined('WPLOCKDOWN') && WPLOCKDOWN;
	
	if ( $valid_nonce )
	{
		if ( $_POST[ 'update_wp_cache_settings'] )
		{
			# rejected urls
			$text = wp_cache_sanitize_value($_POST['wp_rejected_uri'], $cache_rejected_uri);
			wp_cache_replace_line('^ *\$cache_rejected_uri', "\$cache_rejected_uri = $text;", $wp_cache_config_file);
			
			switch ( $_POST[ 'wp_cache_status'] )
			{
			case 'lock':
				wp_cache_enable(true);
				$lockdown = true;
				break;
			
			case 'cache':
				wp_cache_enable();
				$lockdown = false;
				break;
			
			default:
				wp_cache_disable();
				$lockdown = false;
				break;
			}

			echo '<div class="updated">' . "\n"
				. '<p>'
					. '<strong>'
					. __('Settings Saved.')
					. '</strong>'
				. '</p>' . "\n"
				. '</div>' . "\n";
		}
	}
	else
	{
		# clean up expired files
		wp_cache_clean_expired($file_prefix);
	}
	
	echo "<h2>Cache Settings</h2>\n";

	echo '<form name="wp_manager" action="" method="post">' . "\n";
	
	echo '<input type="hidden" name="update_wp_cache_settings" value="1">' . "\n";
		
	echo '<table class="form-table">' . "\n";
	
	echo '<tr valign="top">' . "\n"
		. '<th scope="row">'
		. 'Cache Status'
		. '</th>' . "\n"
		. '<td>' . "\n";
		
	echo '<p>'
		. '<label>'
		. '<input type="radio" name="wp_cache_status" value="none"'
			. ( !$cache_enabled
				? ' checked="checked"'
				: ''
				)
			. '>'
		. '&nbsp;'
		. '<strong>No Cache</strong>. WordPress should deliver dynamic pages.'
		. '</label>'
		. '</p>' . "\n";
		
	echo '<p>'
		.'<label>'
		. '<input type="radio" name="wp_cache_status" value="cache"'
			. ( $cache_enabled && !$lockdown
				? ' checked="checked"'
				: ''
				)
			. '>'
		. '&nbsp;'
		. ''
			. ( wp_cache_can_super_cache()
				? '<strong>Super Cache</strong>. Apache should try to deliver super cached pages before letting WordPress try to deliver cached pages.'
				: '<strong>Normal Cache</strong>. WordPress should try to deliver cached pages.'
				)
			. ' (Recommended)'
		. '</label>'
		. '</p>' . "\n";
		
	echo '<p>'
		. '<label>'
		. '<input type="radio" name="wp_cache_status" value="lock"'
			. ( $cache_enabled && $lockdown
				? ' checked="checked"'
				: ''
				)
			. '>'
		. '&nbsp;'
		. '<strong>Locked Cache</strong>. Identical to the previous setting, with the exception that new comments do not flush cached pages.'
		. '</label>'
		. '</p>' . "\n";
	
	if ( !wp_cache_can_super_cache() )
	{
		echo '<p>'
			. '<strong>Note</strong>: Super caching is disabled.'
				. ( !got_mod_rewrite()
					? ' Your server doesn\'t support mod_rewrite (<a href="http://www.semiologic.com/resources/">server requirements</a>).'
					: ' Browse Settings / Permalinks to configure a permalink structure and/or check that your .htaccess file is writable.'
					)
			. '</p>' . "\n";
	}
		
	echo  '</td>'
		. '</tr>' . "\n";
	
	echo '<tr valign="top">' . "\n"
		. '<th scope="row">'
		. 'Cache Stats'
		. '</th>' . "\n"
		. '<td>' . "\n";
	
	if ( '/' != substr($cache_path, -1)) {
		$cache_path .= '/';
	}

	$count = 0;
	$now = time();
	if ( ($handle = @opendir( $cache_path . 'meta/' )) ) { 
		while ( false !== ($file = readdir($handle))) {
			if ( preg_match("/^$file_prefix.*\.meta/", $file) ) {
				$content_file = preg_replace("/meta$/", "html", $file);
				$mtime = filemtime($cache_path . 'meta/' . $file);
				if ( ! ($fsize = @filesize($cache_path.$content_file)) ) 
					continue; // .meta does not exists
				$count++;
			}
		}
		closedir($handle);
	}
	
	$now = time();
	$sizes = array( 'expired' => 0, 'cached' => 0, 'ts' => 0 );

	if ( is_dir($supercachedir) )
	{
		$entries = glob($supercachedir. '/*');
		foreach ($entries as $entry) {
			if ($entry != '.' && $entry != '..') {
				$sizes = wpsc_dirsize( $entry, $sizes );
			}
		}
	}

	echo "$count cached pages, " . intval($sizes['cached']) . " super cached pages. (Updating your settings will clear the cache.)";
	
	echo  '</td>'
		. '</tr>' . "\n";
	
	echo '<tr valign="top">' . "\n"
		. '<th scope="row">'
		. 'Exceptions'
		. '</th>' . "\n"
		. '<td>' . "\n";
	
	echo '<textarea name="wp_rejected_uri" cols="58" rows="3" class="code">';
	
	foreach ( $cache_rejected_uri as $file )
	{
		echo format_to_edit($file) . "\n";
	}
	
	echo '</textarea>';
	
	echo '<p>'
		. 'If you\'d like the cache to skip some url patterns, enter them above. /2008/, for instance, would make it skip any url containing /2008/. These exceptions come in addition to the built-in ones. Note for experts: You can use POSIX regular expressions.'
		. '</p>';
		
	echo  '</td>'
		. '</tr>' . "\n";
		
	echo '</table>';

	wp_nonce_field('sem-cache');

	echo "<p class='submit'>"
		. "<input type='submit'"
			. ( $disable_form ? ' disabled="disabled"' : '' )
		 	. " value='Update Settings &raquo;' />"
		. "</p>" . "\n";
	
	echo '</form>' . "\n";
	
	echo "</div>\n";
}


function wpsc_dirsize($directory, $sizes)
{
	global $super_cache_max_time;
	$now = time();

	if (is_dir($directory)) {
		$entries = glob($directory. '/*');
		if( is_array( $entries ) && !empty( $entries ) ) foreach ($entries as $entry) {
			if ($entry != '.' && $entry != '..') {
				$sizes = wpsc_dirsize($entry, $sizes);
			}
		}
	} else {
		if(is_file($directory) ) {
			$sizes[ 'cached' ]+=1;
		}
	}
	return $sizes;
}

function wp_cache_replace_line($old, $new, $my_file)
{
	if (!is_writable($my_file)) return false;
	
	$found = false;
	$lines = file($my_file);
	foreach($lines as $line) {
	 	if ( preg_match("/$old/", $line)) {
			$found = true;
			break;
		}
	}
	if ($found) {
		$fd = fopen($my_file, 'w');
		foreach($lines as $line) {
			if ( !preg_match("/$old/", $line))
				fputs($fd, $line);
			else {
				if ( $new ) fputs($fd, "$new\n");
			}
		}
		fclose($fd);
		return true;
	}
	$fd = fopen($my_file, 'w');
	$done = false;
	foreach($lines as $line) {
		if ( $done || !preg_match('/^define|\$|\?>/', $line))
			fputs($fd, $line);
		else {
			if ( $new ) fputs($fd, "$new\n");
			fputs($fd, $line);
			$done = true;
		}
	}
	fclose($fd);
	return true;
}


function wp_cache_sanitize_value($text, & $array)
{
	$text = wp_specialchars(strip_tags($text));
	$array = preg_split("/[\s;,]+/", chop($text));
	$array = array_diff($array, array(''));
	$text = var_export($array, true);
	$text = preg_replace('/[\s]+/', ' ', $text);
	return $text;
}


function wp_cache_can_cache()
{
	return !ini_get('safe_mode')
		&& wp_cache_check_wp_config()
		&& wp_cache_check_cache_config()
		&& wp_cache_check_cache_dir()
		&& wp_cache_check_cache_link();
}


function wp_cache_can_super_cache()
{
	if ( !function_exists('got_mod_rewrite') )
	{
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
	}
	
	return got_mod_rewrite()
		&& get_option('permalink_structure')
		&& file_exists(ABSPATH . '.htaccess')
		&& is_writable(ABSPATH . '.htaccess');
}


function wp_cache_enable($lock = false)
{
	global $wp_cache_config_file, $cache_enabled, $super_cache_enabled, $supercachedir, $file_prefix;
	
	$line = "define('WP_CACHE', true); // Added by Semiologic Pro Cache";
	wp_cache_replace_line('^.*WP_CACHE', $line, ABSPATH . 'wp-config.php');
	
	$line = $lock ? "define('WPLOCKDOWN', '1');" : '';
	wp_cache_replace_line('^.*WPLOCKDOWN', $line, $wp_cache_config_file);

	wp_cache_replace_line('^ *\$cache_enabled', '$cache_enabled = true;', $wp_cache_config_file);
	$cache_enabled = true;

	if ( wp_cache_can_super_cache() )
	{
		wp_cache_replace_line('^ *\$super_cache_enabled', '$super_cache_enabled = true;', $wp_cache_config_file);
		$super_cache_enabled = true;

		if ( is_dir( $supercachedir . ".disabled" ) )
		{
			@rename( $supercachedir . ".disabled", $supercachedir );
		}
		
		add_filter('mod_rewrite_rules', 'wp_cache_mod_rewrite_rules', 1000);
		save_mod_rewrite_rules();
	}
	else
	{
		wp_cache_replace_line('^ *\$super_cache_enabled', '$super_cache_enabled = true;', $wp_cache_config_file);
		$super_cache_enabled = false;
		
		save_mod_rewrite_rules();
	}
	
	wp_cache_clean_cache($file_prefix);
}


function wp_cache_disable()
{
	global $supercachedir, $wp_cache_config_file, $cache_enabled, $super_cache_enabled, $supercachedir, $cache_path, $file_prefix;
	
	wp_cache_replace_line('^.*WP_CACHE', '', ABSPATH . 'wp-config.php');
	wp_cache_replace_line('^.*WPLOCKDOWN', '', $wp_cache_config_file);
	wp_cache_replace_line('^ *\$cache_enabled', '$cache_enabled = false;', $wp_cache_config_file);
	wp_cache_replace_line('^ *\$super_cache_enabled', '$super_cache_enabled = false;', $wp_cache_config_file);
	
	# clear cache
	if ( $cache_enabled || $super_cache_enabled )
	{
		# let existing processes finish
		sleep(1);
	}
	
	if ( is_dir( $supercachedir ) )
	{
		@rename( $supercachedir, $supercachedir . ".disabled" );
		# prune_super_cache( $supercachedir, true );
	}
	
	save_mod_rewrite_rules();
	
	wp_cache_clean_cache($file_prefix);

	# mark as disabled
	$cache_enabled = false;
	$super_cache_enabled = false;
}


function wp_cache_check_wp_config()
{
	return is_writable(ABSPATH . 'wp-config.php');
}


function wp_cache_check_cache_config()
{
	global $wp_cache_config_file, $wp_cache_config_file_sample;
	
	if ( file_exists($wp_cache_config_file) )
	{
		$content = file_get_contents($wp_cache_config_file);
		
		if ( strpos($content, 'sem_cache_path') !== false ) return true;
		
		if ( !@unlink($wp_cache_config_file) )
		{
			echo '<div class="error"><p>'
				. 'Your cache config file (wp-content/sem-cache-config.php) is out of date. Delete it before continuing.'
				. '</p></div>';
			
			return false;
		}
	}
	
	if ( !file_exists($wp_cache_config_file_sample) )
	{
		echo '<div class="error"><p>'
			. 'No sample cache config file found. Please reinstall the Semiologic Pro cache plugin before continuing.'
			. '</p></div>';
		
		return false;
	}

	if ( !@copy($wp_cache_config_file_sample, $wp_cache_config_file) ) return false;
	
	$path = dirname(__FILE__);
	$path = str_replace(ABSPATH, '', $path);
	$path = str_replace("\\", '/', $path); # windows...
	
	$line = 'define("sem_cache_path", ABSPATH . "' . $path . '");';
	
	wp_cache_replace_line('sem_cache_path', $line, $wp_cache_config_file);
	
	@chmod($wp_cache_config_file, 0666); # let user edit this file using ftp
	
	return true;
}


function wp_cache_check_cache_dir()
{
	global $cache_path;
	
	$cache_path = rtrim($cache_path, '/');
	
	if ( !is_dir($cache_path) )
	{
		@unlink($cache_path);

		if ( !( @mkdir($cache_path) && @chmod($cache_path, 0777) ) )
		{
			echo '<div class="error"><p>'
				. 'Couldn\'t create a user-writable wp-content/cache folder.'
				. '</p></div>';
				
			return false;
		}
	}
	elseif ( !is_writable($cache_path) )
	{
		echo '<div class="error"><p>'
			. 'A wp-content/cache folder exists but is not writable. Delete it before continuing.'
			. '</p></div>';
			
		return false;
	}
	
	$cache_path .= '/';
	
	if ( !is_dir($cache_path . 'meta') )
	{
		@unlink($cache_path . 'meta');
		
		if ( !@mkdir($cache_path . 'meta') || !@chmod($cache_path . 'meta', 0777) )
		{
			echo '<div class="error"><p>'
				. 'Couldn\'t create a user-writable wp-content/cache/meta folder.'
				. '</p></div>';
			
			return false;
		}
	}
	elseif ( !is_writable($cache_path) )
	{
		echo '<div class="error"><p>'
			. 'A wp-content/cache/meta folder exists but is not writable. Delete it before continuing.'
			. '</p></div>';
		
		return false;
	}

	return true;
}


function wp_cache_check_cache_link()
{
	global $wp_cache_link, $wp_cache_file;
	
	if ( file_exists($wp_cache_link) )
	{
		$contents = file_get_contents($wp_cache_link);
		
		# verify it's the sem-cache file
		if ( strpos($contents, 'sem_cache_path') !== false ) return true;
	
		# remove the junk, if any
		if ( !@unlink($wp_cache_link) )
		{
			echo '<div class="error"><p>'
				. 'Your cache file (wp-content/advanced-cache.php) is out of date. Delete it before continuing.'
				. '</p></div>';
		
			return false;
		}
	}
	
	# try a link before falling back to a copy
	if ( function_exists( 'symlink' ) && @symlink($wp_cache_file, $wp_cache_link)
		|| @copy($wp_cache_file, $wp_cache_link)
		)
	{
		return true;
	}
	
	return false;
}


function wp_cache_clean_cache($file_prefix) {
	global $cache_path, $supercachedir;

	// If phase2 was compiled, use its function to avoid race-conditions
	if(function_exists('wp_cache_phase2_clean_cache')) {
		if (function_exists ('prune_super_cache')) {
			if( is_dir( $supercachedir ) ) {
				prune_super_cache( $supercachedir, true );
			} elseif( is_dir( $supercachedir . '.disabled' ) ) {
				prune_super_cache( $supercachedir . '.disabled', true );
			}
			prune_super_cache( $cache_path, true );
		}
		return wp_cache_phase2_clean_cache($file_prefix);
	}

	$expr = "/^$file_prefix/";
	if ( ($handle = opendir( $cache_path )) ) { 
		while ( false !== ($file = readdir($handle))) {
			if ( preg_match($expr, $file) ) {
				unlink($cache_path . $file);
				unlink($cache_path . 'meta/' . str_replace( '.html', '.term', $file ) );
			}
		}
		closedir($handle);
	}
}

function wp_cache_clean_expired($file_prefix) {
	global $cache_path, $cache_max_time;

	// If phase2 was compiled, use its function to avoid race-conditions
	if(function_exists('wp_cache_phase2_clean_expired')) {
		if (function_exists ('prune_super_cache')) {
			$dir = $cache_path . 'supercache/' . preg_replace('/:.*$/', '',  $_SERVER["HTTP_HOST"]);
			if( is_dir( $dir ) ) {
				prune_super_cache( $dir );
			} elseif( is_dir( $dir . '.disabled' ) ) {
				prune_super_cache( $dir . '.disabled' );
			}
		}
		return wp_cache_phase2_clean_expired($file_prefix);
	}

	$expr = "/^$file_prefix/";
	$now = time();
	if ( ($handle = opendir( $cache_path )) ) { 
		while ( false !== ($file = readdir($handle))) {
			if ( preg_match($expr, $file)  &&
				(filemtime($cache_path . $file) + $cache_max_time) <= $now) {
				unlink($cache_path . $file);
				unlink($cache_path . 'meta/' . str_replace( '.html', '.term', $file ) );
			}
		}
		closedir($handle);
	}
}


function wpsc_remove_marker( $filename, $marker )
{
	if (!file_exists( $filename ) || is_writeable( $filename ) ) {
		if (!file_exists( $filename ) ) {
			return '';
		} else {
			$markerdata = explode( "\n", implode( '', file( $filename ) ) );
		}

		$f = fopen( $filename, 'w' );
		$foundit = false;
		if ( $markerdata ) {
			$state = true;
			foreach ( $markerdata as $n => $markerline ) {
				if (strpos($markerline, '# BEGIN ' . $marker) !== false)
					$state = false;
				if ( $state ) {
					if ( $n + 1 < count( $markerdata ) )
						fwrite( $f, "{$markerline}\n" );
					else
						fwrite( $f, "{$markerline}" );
				}
				if (strpos($markerline, '# END ' . $marker) !== false) {
					$state = true;
				}
			}
		}
		return true;
	} else {
		return false;
	}
}


function wp_cache_mod_rewrite_rules($rules)
{
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	
	$home_path = rtrim(get_home_path(), '/');
	$home_root = parse_url(get_option('home'));
	$home_root = rtrim($home_root['path'], '/');
	
	$extra = "\n"
		. "RewriteCond %{REQUEST_FILENAME} !-f\n"
		. "RewriteCond %{QUERY_STRING} =\"\"\n"
		. "RewriteCond %{HTTP_COOKIE} =\"\"\n"
		. "RewriteCond {$home_path}/wp-content/cache/supercache/%{HTTP_HOST}%{REQUEST_URI}index.html -f\n"
		. "RewriteRule ^ {$home_root}/wp-content/cache/supercache/%{HTTP_HOST}%{REQUEST_URI}index.html [L]\n"
		. "\n"
		;
	
	if ( preg_match("/RewriteBase.*/ix", $rules, $rewrite_base) )
	{
		$rewrite_base = end($rewrite_base);
		$rules = str_replace($rewrite_base, "\n$rewrite_base\n$extra\n", $rules);
		
		# optimize rewrite WordPress' rules, while we're at it...
		$rules = str_replace('RewriteRule . ', 'RewriteRule ^ ', $rules);
	}
	
	return $rules;
}
?>