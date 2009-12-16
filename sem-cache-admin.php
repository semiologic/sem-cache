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
		
		switch ( $_POST['action'] ) {
		case 'flush':
			sem_cache::flush_assets();
			sem_cache::flush_static();
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
			sem_cache::disable_static();
			sem_cache::disable_memcached();
			sem_cache::disable_assets();
			sem_cache::disable_gzip();
			
			echo '<div class="updated fade">' . "\n"
				. '<p>'
					. '<strong>'
					. __('Settings saved. Cache Disabled.', 'sem-cache')
					. '</strong>'
				. '</p>' . "\n"
				. '</div>' . "\n";
			break;
		
		default:
			$can_static = sem_cache::can_static();
			$can_memcached = sem_cache::can_memcached();
			$can_query = sem_cache::can_query();
			$can_assets = sem_cache::can_assets();
			$can_gzip = sem_cache::can_gzip();
			
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
				$query_cache = $can_memcached;
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
			
			sem_cache::enable_static();
			sem_cache::enable_memcached();
			sem_cache::enable_assets();
			sem_cache::enable_gzip();
			
			echo '<div class="updated fade">' . "\n"
				. '<p>'
					. '<strong>'
					. __('Settings saved. Cache Enabled.', 'sem-cache')
					. '</strong>'
				. '</p>' . "\n"
				. '</div>' . "\n";
			break;
		}
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
		
		global $_wp_using_ext_object_cache;
		$can_static = sem_cache::can_static();
		$can_memcached = sem_cache::can_memcached();
		$can_query = sem_cache::can_query();
		$can_assets = sem_cache::can_assets();
		$can_gzip = sem_cache::can_gzip();
		
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
				. __('Flush the cache', 'sem-cache')
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
				. ( !$can_static
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
				. ( !$can_memcached
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
				. ( !$can_memcached
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
				. ( !$can_memcached
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
				. ( !$can_assets
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
				. ( !$can_gzip
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
} # sem_cache_admin

add_action('settings_page_sem-cache', array('sem_cache_admin', 'save_options'), 0);
?>