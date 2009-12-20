<?php
/**
 * asset_cache
 *
 * @package Semiologic Cache
 **/

class asset_cache {
	/**
	 * wp_print_styles()
	 *
	 * @return void
	 **/

	static function wp_print_styles() {
		static $done = false;
		if ( $done )
			return;
		
		$done = true;
		global $wp_styles;
		
		if ( !( $wp_styles instanceof WP_Styles ) )
			$wp_styles = new WP_Styles;
		
		$todo = array_diff($wp_styles->queue, $wp_styles->done);
		
		if ( !$todo )
			return;
		
		$site_url = '(/|' . preg_quote(content_url()) . '|' . preg_quote(plugins_url()) . ')';
		$redo = array();
		$css = array();
		
		foreach ( $todo as $handle ) {
			if ( preg_match("{^$site_url}i", $wp_styles->registered[$handle]->src)
				&& preg_match("/\.css$/i", $wp_styles->registered[$handle]->src) ) {
				$css[$handle] = $wp_styles->registered[$handle]->ver;
			} else {
				$redo[] = $handle;
			}
		}
		
		if ( $redo ) {
			$todo = array_diff($todo, $redo);
			$wp_styles->done = array_diff($wp_styles->done, $redo);
		}
		
		if ( $todo ) {
			$file = '/assets/' . md5(serialize($css)) . '.css';
			if ( !cache_fs::exists($file) )
				asset_cache::concat_styles($file, $todo);
			$wp_styles->default_version = null;
			wp_enqueue_style('styles_concat', content_url() . '/cache' . $file);
			$wp_styles->done = array_merge($wp_styles->done, $todo);
		}	
	} # wp_print_styles()
	
	
	/**
	 * concat_styles()
	 *
	 * @param string $file
	 * @param array $handles
	 * @return void
	 **/

	static function concat_styles($file, $handles) {
		global $wp_styles;
		$css = '';
		
		foreach ( $handles as $handle ) {
			$src = $wp_styles->registered[$handle]->src;
			if ( substr($src, 0, 1) == '/' ) {
				$base = site_url() . $src;
				$src = ABSPATH . ltrim($src, '/');
			} else {
				$base = $src;
				$src = str_replace(
					array(plugins_url(), content_url()),
					array(WP_PLUGIN_DIR, WP_CONTENT_DIR),
					$src
					);
			}
			$css[$base] = file_get_contents($src);
		}
		
		foreach ( $css as $base => &$style ) {
			$base = dirname($base) . '/';
			$style = preg_replace("{url\s*\(\s*(?!https?://)(./)?}i", "url($base", $style);
		}
		
		cache_fs::put_contents($file, implode("\n\n", $css));
	} # concat_styles()
	
	
	/**
	 * wp_print_scripts()
	 *
	 * @return void
	 **/

	static function wp_print_scripts() {
		static $done = false;
		if ( $done )
			return;
		
		$done = true;
		global $wp_scripts;
		
		if ( !( $wp_scripts instanceof WP_Scripts ) )
			$wp_scripts = new WP_Scripts;
		
		$done = $wp_scripts->done;
		$wp_scripts->do_concat = true;
		$todo = array_diff($wp_scripts->do_head_items(), $done);
		$wp_scripts->reset();
		
		if ( !$todo )
			return;
		
		$site_url = '(/|' . preg_quote(content_url()) . '|' . preg_quote(plugins_url()) . ')';
		$redo = array();
		$js = array();
		
		foreach ( $todo as $handle ) {
			if ( preg_match("{^$site_url}i", $wp_scripts->registered[$handle]->src)
				&& preg_match("/\.js$/i", $wp_scripts->registered[$handle]->src) ) {
				$js[$handle] = $wp_scripts->registered[$handle]->ver;
			} else {
				$redo[] = $handle;
			}
		}
		
		if ( $redo ) {
			$todo = array_diff($todo, $redo);
			$wp_scripts->done = array_diff($wp_scripts->done, $redo);
		}
		
		if ( $todo ) {
			$file = '/assets/' . md5(serialize($js)) . '.js';
			if ( !cache_fs::exists($file) )
				asset_cache::concat_scripts($file, $todo);
			$wp_scripts->default_version = null;
			wp_enqueue_script('scripts_concat', content_url() . '/cache' . $file);
		}	
	} # wp_print_scripts()
	
	
	/**
	 * wp_print_footer_scripts()
	 *
	 * @return void
	 **/

	static function wp_print_footer_scripts() {
		static $done = false;
		if ( $done )
			return;
		
		$done = true;
		global $wp_scripts;
		
		if ( !( $wp_scripts instanceof WP_Scripts ) )
			$wp_scripts = new WP_Scripts;
		
		$done = $wp_scripts->done;
		$wp_scripts->do_concat = true;
		$todo = array_diff($wp_scripts->do_footer_items(), $done);
		$wp_scripts->reset();
		
		if ( !$todo )
			return;
		
		$site_url = '(/|' . preg_quote(content_url()) . '|' . preg_quote(plugins_url()) . ')';
		$redo = array();
		$js = array();
		
		foreach ( $todo as $handle ) {
			if ( preg_match("{^$site_url}i", $wp_scripts->registered[$handle]->src)
				&& preg_match("/\.js$/i", $wp_scripts->registered[$handle]->src) ) {
				$js[$handle] = $wp_scripts->registered[$handle]->ver;
			} else {
				$redo[] = $handle;
			}
		}
		
		if ( $redo ) {
			$todo = array_diff($todo, $redo);
			$wp_scripts->done = array_diff($wp_scripts->done, $redo);
		}
		
		if ( $todo ) {
			$file = '/assets/' . md5(serialize($js)) . '.js';
			if ( !cache_fs::exists($file) )
				asset_cache::concat_scripts($file, $todo);
			$wp_scripts->default_version = null;
			wp_enqueue_script('footer_scripts_concat', content_url() . '/cache' . $file, array(), false, true);
			$wp_scripts->groups['footer_scripts_concat'] = 1;
			$wp_scripts->in_footer[] = 'footer_scripts_concat';
		}
	} # wp_print_footer_scripts()
	
	
	/**
	 * concat_scripts()
	 *
	 * @param string $file
	 * @param array $handles
	 * @return void
	 **/

	static function concat_scripts($file, $handles) {
		global $wp_scripts;
		$js = '';
		
		foreach ( $handles as $handle ) {
			$src = $wp_scripts->registered[$handle]->src;
			if ( substr($src, 0, 1) == '/' ) {
				$src = ABSPATH . ltrim($src, '/');
			} else {
				$src = str_replace(
					array(plugins_url(), content_url()),
					array(WP_PLUGIN_DIR, WP_CONTENT_DIR),
					$src
					);
			}
			$js[] = file_get_contents($src);
		}
		
		cache_fs::put_contents($file, implode("\n\n", $js));
	} # concat_scripts()
} # asset_cache

if ( !SCRIPT_DEBUG ) {
	add_filter('wp_print_scripts', array('asset_cache', 'wp_print_scripts'), 1000000);
	add_filter('wp_print_footer_scripts', array('asset_cache', 'wp_print_footer_scripts'), 1000000);
}

if ( !sem_css_debug ) {
	add_filter('wp_print_styles', array('asset_cache', 'wp_print_styles'), 1000000);
}
?>