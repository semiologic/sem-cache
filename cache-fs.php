<?php
/**
 * cache_fs
 *
 * @package Semiologic Cache
 **/

class cache_fs {
	private static $base_dir;
	
	
	/**
	 * start()
	 *
	 * @return void
	 **/

	static function start() {
		if ( strpos($_SERVER['HTTP_HOST'], '/') !== false )
			trigger_error(sprintf('%s is not a valid hostname', $_SERVER['HTTP_HOST']), E_USER_ERROR);
		
		self::$base_dir = function_exists('is_site_admin') && defined('VHOST') && VHOST
			? WP_CONTENT_DIR . '/cache/' . $_SERVER['HTTP_HOST']
			: WP_CONTENT_DIR . '/cache';
	} # start()
	
	
	/**
	 * path_join()
	 *
	 * @param string $file
	 * @return string $file
	 **/

	protected static function path_join($file) {
		return self::$base_dir . '/'
			. trim($file, '/');
	} # path_join()
	
	
	/**
	 * exists()
	 *
	 * @param string $file
	 * @param int $timeout
	 * @return bool $valid
	 **/

	static function exists($file, $timeout = false) {
		$file = self::path_join($file);
		
		return file_exists($file)
			&& ( !$timeout || ( filemtime($file) + $timeout >= time() ) );
	} # exists()
	
	
	/**
	 * get_contents()
	 *
	 * @param string $file
	 * @return string $contents
	 **/

	static function get_contents($file) {
		$file = self::path_join($file);
		
		return file_get_contents($file);
	} # get_contents()
	
	
	/**
	 * readfile()
	 *
	 * @param string $file
	 * @return int $bytes
	 **/

	static function readfile($file) {
		$file = self::path_join($file);
		
		return readfile($file);
	} # readfile()
	
	
	/**
	 * put_contents()
	 *
	 * @return bool $success
	 **/

	static function put_contents($file, $contents) {
		$file = self::path_join($file);
		
		# bail if the file was concurrently built
		if ( self::exists($file, 300) )
			return true;
		
		$dir = dirname($file);
		
		if ( !wp_mkdir_p($dir) )
			return false;
		
		$perms = stat($dir);
		$perms = $perms['mode'] & 0000666;
		
		# work on a temporary file to bypass file locking requirements
		$tmp = tempnam($dir, 'new_');
		if ( !file_put_contents($tmp, $contents) ) {
			unlink($tmp);
			return false;
		}
		
		if ( !chmod($tmp, $perms) || !rename($tmp, $file) ) {
			unlink($tmp);
			return false;
		}
		
		return true;
	} # put_contents()
	
	
	/**
	 * flush()
	 *
	 * @param string $dir
	 * @param int $timeout
	 * @param bool $recursive
	 * @return bool $success
	 **/
	
	static function flush($dir = '/', $timeout = false, $recursive = true) {
		return self::rm(self::path_join($dir), $timeout, $recursive);
	} # flush()
	
	
	/**
	 * rm()
	 *
	 * @param string $dir
	 * @param int $timeout
	 * @param bool $recursive
	 * @return bool $success
	 **/

	protected static function rm($dir, $timeout = false, $recursive = true) {
		$dir = rtrim($dir, '/');
		
		if ( !file_exists($dir) )
			return true;
		
		if ( !is_dir($dir) )
			return ( $timeout && ( filemtime($dir) + $timeout >= time() ) ) || unlink($dir);
		elseif ( !$recursive )
			return is_file("$dir/index.html") ? self::rm("$dir/index.html", $timeout, $recursive) : true;
		
		if ( !( $handle = opendir($dir) ) )
			return false;
		
		$success = true;
		while ( ( $file = readdir($handle) ) !== false ) {
			if ( in_array($file, array('.', '..')) )
				continue;
			$success &= self::rm("$dir/$file", $timeout, $recursive);
		}
		
		closedir($handle);
		
		return $timeout || rmdir($dir) && $success;
	} # rm()
	
	
	/**
	 * stats()
	 *
	 * @param string $dir
	 * @return array($total_pages, $expired_pages)
	 **/

	public static function stats($dir = '/', $timeout = false, $bucket = null) {
		static $total_pages = 0;
		static $expired_pages = 0;
		if ( !isset($bucket) ) {
			$bucket = $dir;
			$total_pages = 0;
			$expired_pages = 0;
			$dir = self::path_join($bucket);
		}
		
		if ( !file_exists($dir) ) {
			return array($total_pages, $expired_pages);
		} elseif ( is_file($dir) ) {
			$total_pages++;
			if ( $timeout && ( filemtime($dir) + $timeout < time() ) )
				$expired_pages++;
			return array($total_pages, $expired_pages);
		} else {
			if ( !( $handle = opendir($dir) ) )
				return array($total_pages, $expired_pages);

			while ( ( $file = readdir($handle) ) !== false ) {
				if ( in_array($file, array('.', '..')) )
					continue;
				self::stats("$dir/$file", $timeout, $bucket);
			}

			closedir($handle);
			
			return array($total_pages, $expired_pages);
		}
	} # stats()
} # cache_fs

cache_fs::start();
?>