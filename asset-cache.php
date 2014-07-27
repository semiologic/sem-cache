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

		$queue = $wp_styles->queue;
        $wp_styles->all_deps($queue);

		if ( !$wp_styles->to_do )
			return;

		$todo = array();
		$css = array();
		$dirs =  array( content_url(), plugins_url(), includes_url() );

		foreach ( $wp_styles->to_do as $key => $handle ) {

			$cssPath = $wp_styles->registered[$handle]->src;

			if (  !asset_cache::startsWith(  $cssPath,  site_url() ) )
				$cssPath = site_url() . $cssPath;

			$inDir = false;
			foreach ( $dirs as $dir ) {
				if (asset_cache::startsWith( $cssPath, $dir ) ) {
					$inDir = true;
					break;
				}
			}

			$suffixMatch = asset_cache::endsWith( $cssPath, ".css" );

			if ( $inDir && $suffixMatch ) {
				$css[$handle] = $wp_styles->registered[$handle]->ver;
				$todo[] = $handle;
				unset( $wp_styles->to_do[$key]);
				$wp_styles->done[] = $handle;
			}
		}

		if ( $todo ) {
			$file = '/assets/' . md5(serialize($css)) . '.css';
			if ( !cache_fs::exists($file) )
				asset_cache::concat_styles($file, $todo);
			$wp_styles->default_version = null;
			wp_enqueue_style('styles_concat', content_url() . '/cache' . $file);
		}

//		$wp_styles->do_concat = true;
//		$wp_styles->do_items();
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
		$css = array();
		
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
			$css[$base] = self::strip_bom(file_get_contents($src));
		}
		
		foreach ( $css as $base => &$style ) {
			$base = dirname($base) . '/';
			$style = preg_replace("{url\s*\(\s*([\"']?)(?![\"']?https?://)(?:\./)?(.+?)\\1\s*\)}i", "url($1$base$2$1)", $style);
			$style = self::compress_css( $style );
		}
		
		cache_fs::put_contents($file, implode("\n\n", $css));
	} # concat_styles()

	/**
	 * compress_css()
	 *
	 * @param string $buffer
	 * @return string
	 **/

	static function compress_css( $buffer ) {

		// compress crlf
		$buffer = str_replace("\r\n", "\n", $buffer);

		// Normalize whitespace
		$buffer = preg_replace( '/\s+/', ' ', $buffer );

        // remove comments
        $buffer = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $buffer);

		// remove ws around { } and last semicolon in declaration block
		$buffer = preg_replace('/\\s*{\\s*/', '{', $buffer);
		$buffer = preg_replace('/;?\\s*}\\s*/', '}', $buffer);

        // remove ws surrounding semicolons
		$buffer = preg_replace('/\\s*;\\s*/', ';', $buffer);

		// remove ws between rules and colons
        $buffer = preg_replace('/
                \\s*
                ([{;])              # 1 = beginning of block or rule separator
                \\s*
                ([\\*_]?[\\w\\-]+)  # 2 = property (and maybe IE filter)
                \\s*
                :
                \\s*
                (\\b|[#\'"])        # 3 = first character of a value
            /x', '$1$2:$3', $buffer);

		// Strip leading 0 on decimal values (converts 0.5px into .5px)
		$buffer = preg_replace( '/(:| )0\.([0-9]+)(%|em|ex|px|in|cm|mm|pt|pc)/i', '${1}.${2}${3}', $buffer );

		// Strip units if value is 0 (converts 0px to 0)
		$buffer = preg_replace( '/(:| )(\.?)0(%|em|ex|px|in|cm|mm|pt|pc)/i', '${1}0', $buffer );

		// Convert all zeros value into short-hand
		$buffer = preg_replace( '/0 0 0 0/', '0', $buffer );

		// minimize hex colors
        $buffer = preg_replace('/([^=])#([a-f\\d])\\2([a-f\\d])\\3([a-f\\d])\\4([\\s;\\}])/i'
            , '$1#$2$3$4$5', $buffer);

		// replace any ws involving newlines with a single newline
		$buffer= preg_replace('/[ \\t]*\\n+\\s*/', "\n", $buffer);

        // separate common descendent selectors w/ newlines (to limit line lengths)
		$buffer = preg_replace('/([\\w#\\.\\*]+)\\s+([\\w#\\.\\*]+){/', "$1\n$2{", $buffer);

        return trim($buffer);
	} # compress_css

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
		asset_cache::process_scripts( true );
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

		asset_cache::process_scripts( false );
	} # wp_print_footer_scripts()
	
	/**
	 * process_scripts()
	 *
	 * @param bool $header_scripts
	 * @return void
	 **/
	static function process_scripts( $header_scripts = true ) {

		global $wp_scripts;

		if ( !( $wp_scripts instanceof WP_Scripts ) )
			$wp_scripts = new WP_Scripts;

		$queue = $wp_scripts->queue;
        $wp_scripts->all_deps($queue);

		if ( !$wp_scripts->to_do )
			return;

		$todo = array();
		$js = array();
		$dirs =  array( content_url(), plugins_url() );

		foreach ( $wp_scripts->to_do as $key => $handle ) {
			if ( !empty($wp_scripts->registered[$handle]->args) )
				continue;

			// bail if is a footer script and we're doing headers
			if ( $header_scripts && $wp_scripts->groups[$handle] > 0 )
				continue;

			$jsPath = $wp_scripts->registered[$handle]->src;

			// bail if alias
			if ( ! $jsPath ) {
				continue;
			}

			if (  !asset_cache::startsWith(  $jsPath,  site_url() ) )
				$jsPath = site_url() . $jsPath;

			$inDir = false;
			foreach ( $dirs as $dir ) {
				if (asset_cache::startsWith( $jsPath, $dir ) ) {
					$inDir = true;
					break;
				}
			}

			$suffixMatch = asset_cache::endsWith( $jsPath, ".js" );

			if ( $inDir && $suffixMatch ) {
				$js[$handle] = $wp_scripts->registered[$handle]->ver;
				$todo[] = $handle;
				unset( $wp_scripts->to_do[$key]);
				$wp_scripts->done[] = $handle;
			}
		}

		if ( $todo ) {
			$file = '/assets/' . md5(serialize($js)) . '.js';
			if ( !cache_fs::exists($file) )
				asset_cache::concat_scripts($file, $todo);
			$wp_scripts->default_version = null;
			if ( $header_scripts ) {
				wp_enqueue_script('scripts_concat', content_url() . '/cache' . $file);
			}
			else {
				wp_enqueue_script('footer_scripts_concat', content_url() . '/cache' . $file, array(), false, true);
				$wp_scripts->groups['footer_scripts_concat'] = 1;
				$wp_scripts->in_footer[] = 'footer_scripts_concat';
			}
		}

		$wp_scripts->do_concat = true;
		( $header_scripts ) ? $wp_scripts->do_head_items() : $wp_scripts->do_footer_items();
		if ( $wp_scripts->print_code ) {
			echo "<script type='text/javascript'>\n";
			echo "/* <![CDATA[ */\n";
			echo $wp_scripts->print_code;
			echo "/* ]]> */\n";
			echo "</script>\n";
		}

		//		$wp_scripts->reset();
	}
	/**
	 * concat_scripts()
	 *
	 * @param string $file
	 * @param array $handles
	 * @return void
	 **/

	static function concat_scripts($file, $handles) {
		global $wp_scripts;
		$js = array();
		
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
			$script = self::strip_bom(file_get_contents($src));
			$js[] = self::compress_js( $script );
		}
		
		cache_fs::put_contents($file, implode("\n\n", $js));
	} # concat_scripts()

	/**
	 * compress_js()
	 *
	 * @param string $buffer
	 * @return string
	 **/

	static function compress_js( $buffer ) {
		return $buffer;

        /* remove tabs, spaces, newlines, etc. */
        $buffer = str_replace(array("\r\n","\r","\t","\n",'  ','    ','     '), '', $buffer);
        /* remove other spaces before/after ) */
        $buffer = preg_replace(array('(( )+\))','(\)( )+)'), ')', $buffer);
        return $buffer;
	} # compress_js

	/**
	 * strip_bom()
	 *
	 * @param string $str
	 * @return string $str
	 **/

	static function strip_bom($str) {
		if ( preg_match('{^\x0\x0\xFE\xFF}', $str) ) {
			# UTF-32 Big Endian BOM
			$str = substr($str, 4);
		} elseif ( preg_match('{^\xFF\xFE\x0\x0}', $str) ) {
			# UTF-32 Little Endian BOM
			$str = substr($str, 4);
		} elseif ( preg_match('{^\xFE\xFF}', $str) ) {
			# UTF-16 Big Endian BOM
			$str = substr($str, 2);
		} elseif ( preg_match('{^\xFF\xFE}', $str) ) {
			# UTF-16 Little Endian BOM
			$str = substr($str, 2);
		} elseif ( preg_match('{^\xEF\xBB\xBF}', $str) ) {
			# UTF-8 BOM
			$str = substr($str, 3);
		}
		
		return $str;
	} # strip_bom()

	/**
	 * startsWith()
	 *
	 * @param string $str
	 * @return string $str
	 **/
	static function startsWith($haystack, $needle)
	{
	     $length = strlen($needle);
	     return (substr($haystack, 0, $length) === $needle);
	}

	/**
	 * endsWith()
	 *
	 * @param string $str
	 * @return string $str
	 **/
	static function endsWith($haystack, $needle)
	{
	    $length = strlen($needle);
	    if ($length == 0) {
	        return true;
	    }

	    return (substr($haystack, -$length) === $needle);
	}
} # asset_cache

if ( !SCRIPT_DEBUG ) {
	add_filter('wp_print_scripts', array('asset_cache', 'wp_print_scripts'), 1000000);
	add_filter('wp_print_footer_scripts', array('asset_cache', 'wp_print_footer_scripts'), 9);
}

if ( !sem_css_debug ) {
	add_filter('wp_print_styles', array('asset_cache', 'wp_print_styles'), 1000000);
}
