<?php

class sem_cache_rules {

	/**
	 * Plugin instance.
	 *
	 * @see get_instance()
	 * @type object
	 */
	protected static $instance = NULL;

	/**
	 * Access this plugin’s working instance
	 *
	 * @wp-hook plugins_loaded
	 * @return  object of this class
	 */
	public static function get_instance()
	{
		NULL === self::$instance and self::$instance = new self;

		return self::$instance;
	}

	public function __construct() {

    }

	/**
	 * init()
	 *
	 * @return void
	 **/

	function init() {
		add_filter('mod_rewrite_rules', array($this, 'rewrite_rules'), 1000000);
	}
	/**
	 * rewrite_rules()
	 *
	 * @param string $rules
	 * @return string $rules
	 **/

	static function rewrite_rules($rules) {

		if ( (bool) get_site_option('static_cache') ) {

			$rules = self::base_rewrite_rules($rules);

        }  // static_cache option

		if ( (bool) get_site_option('gzip_cache') ) {
			$extra = self::mod_deflate_rules();
			$rules = $extra . $rules;
		} // gzip rules

        if ( (bool) get_site_option('static_cache') ) {

	        $extra = self::mod_expires_rules();
                $rules = $extra . $rules;

	        $extra = self::mod_mime_rules();
                $rules = $extra . $rules;

	        $extra = self::mod_header_rules();
	     		$rules = $extra . $rules;
        } // static cache

		$extra = self::encoding_rules();
		$rules = $extra . $rules;

		$extra = self::www_nonwww_rules();
        $rules = $extra . $rules;

		return $rules;
	} # rewrite_rules()

	/**
	 * vary_header_rules()
	 *
	 * @param $rules
	 * @return string $rules
	 */

	static function base_rewrite_rules($rules) {
		$cache_dir = WP_CONTENT_DIR . '/cache/static';
		$cache_url = parse_url(WP_CONTENT_URL . '/cache/static');
		$cache_url = $cache_url['path'];

		if ( defined('SUBDOMAIN_INSTALL') && SUBDOMAIN_INSTALL ) {
			$cache_dir .= '/' . $_SERVER['HTTP_HOST'];
			$cache_url .= '/' . $_SERVER['HTTP_HOST'];
		}

		$cache_cookies = array();
		foreach ( sem_cache::get_cookies() as $cookie )
			$cache_cookies[] = "RewriteCond %{HTTP_COOKIE} !\b$cookie=";
		$cache_cookies = implode("\n", $cache_cookies);

		$mobile_agents = sem_cache::get_mobile_agents();
//		$mobile_agents = array_map('preg_quote', $mobile_agents);
		$mobile_agents = implode('|', $mobile_agents);

		global $wp_rewrite;

		if ( $wp_rewrite->use_trailing_slashes ) {
			$extra = <<<EOS

RewriteCond %{REQUEST_URI} !^.*[^/]$
RewriteCond %{REQUEST_URI} !^.*//.*$
RewriteCond %{REQUEST_METHOD} !POST
RewriteCond %{QUERY_STRING} !.*=.*
RewriteCond %{HTTP:Cookie} !^.*(comment_author_|wordpress_logged_in|wp-postpass_).*$
RewriteCond %{DOCUMENT_ROOT}/wp-content/cache/static%{REQUEST_URI}index.html -f
RewriteRule ^ "$cache_url%{REQUEST_URI}index.html" [L]

EOS;
		} else {
			$extra = <<<EOS

RewriteCond %{REQUEST_URI} !^.*[^/]$
RewriteCond %{REQUEST_URI} !^.*//.*$
RewriteCond %{REQUEST_METHOD} !POST
RewriteCond %{QUERY_STRING} !.*=.*
RewriteCond %{HTTP:Cookie} !^.*(comment_author_|wordpress_logged_in|wp-postpass_).*$
RewriteCond %{DOCUMENT_ROOT}/wp-content/cache/static%{REQUEST_URI}index.html -f
RewriteRule ^ "$cache_url%{REQUEST_URI}" [L]

RewriteCond %{REQUEST_URI} !^.*[^/]$
RewriteCond %{REQUEST_URI} !^.*//.*$
RewriteCond %{REQUEST_METHOD} !POST
RewriteCond %{QUERY_STRING} !.*=.*
RewriteCond %{HTTP:Cookie} !^.*(comment_author_|wordpress_logged_in|wp-postpass_).*$
RewriteCond %{DOCUMENT_ROOT}/wp-content/cache/static%{REQUEST_URI}index.html -f
RewriteRule ^ "$cache_url%{REQUEST_URI}.html" [L]

EOS;
		}

		# this will simply fail if mod_rewrite isn't available
		if ( preg_match("/RewriteBase.+\n*/i", $rules, $rewrite_base) ) {
			$rewrite_base = end($rewrite_base);
			$new_rewrite_base = trim($rewrite_base) . "\n\n" . trim($extra) . "\n\n";
			$rules = str_replace($rewrite_base, $new_rewrite_base, $rules);
		}

		return $rules;
	}

	/**
	 * encoding_rules()
	 *
	 * @return string $rules
	 **/

	static function encoding_rules() {
		$encoding = get_site_option('blog_charset');
		if ( !$encoding )
			$encoding = 'utf-8';

		$extra = <<<EOS

# ------------------------------------------------------------------------------
# | UTF-8 encoding                                                             |
# ------------------------------------------------------------------------------

# Use UTF-8 encoding for anything served as `text/html` or `text/plain`.
AddDefaultCharset $encoding

# Force UTF-8 for certain file formats.
<IfModule mod_mime.c>
    AddCharset utf-8 .atom .css .js .json .jsonld .rdf .rss .vtt .webapp .webmanifest .xml
</IfModule>

EOS;

		return $extra;
	}


	/**
	 * www_nonwww_rules()
	 *
	 * @return string $rules
	 **/

	static function www_nonwww_rules() {

		$site_url = get_option('siteurl');

		// check for local server
		if ( strpos($site_url, 'localhost') !== false || strpos($site_url, '127.0.0.1') !== false)
			return '';

		$protocol = 'http';
		if ( is_ssl() )
			$protocol = 'https';

		$site_www = strpos($site_url, $protocol . '://www.') !== false;

		$domain = sem_cache::url_to_domain($site_url);

		if ($site_www) {

		$extra = <<<EOS
# ----------------------------------------------------------------------
# Set non-www to www redirect
# ----------------------------------------------------------------------

<IfModule mod_rewrite.c>
	RewriteCond %{HTTP_HOST} !^www\.$domain$ [NC]
	RewriteRule ^(.*)$ $protocol://www.$domain/$1 [R=301,L]
</IfModule>

EOS;
		}
		else {
	$extra = <<<EOS
# ----------------------------------------------------------------------
# Set www to non-www redirect
# ----------------------------------------------------------------------

 <IfModule mod_rewrite.c>
    RewriteCond %{HTTP_HOST} ^www\.$domain [NC]
    RewriteRule ^(.*)$ $protocol://$domain/$1 [L,R=301]
</IfModule>

EOS;

		}
		return $extra;
	} // www_nonwww_rules()

	/**
	 * mod_header_rules()
	 *
	 * @return string $rules
	 **/

	static function mod_header_rules() {

	$extra = <<<EOS

# `FileETag None` is not enough for every server.
<IfModule mod_headers.c>
    Header unset ETag
</IfModule>

# Since we're sending far-future expires headers (see below), ETags can
# be removed: http://developer.yahoo.com/performance/rules.html#etags.
FileETag None

<IfModule mod_alias.c>
	<FilesMatch "\.(html|htm|rtf|rtx|svg|svgz|txt|xsd|xsl|xml)$">
		<IfModule mod_headers.c>
			Header unset Pragma
			Header append Cache-Control "public"
			Header unset Last-Modified
		</IfModule>
	</FilesMatch>
	
	<FilesMatch "\.(css|htc|js|asf|asx|wax|wmv|wmx|avi|bmp|class|divx|doc|docx|eot|exe|gif|gz|gzip|ico|jpg|jpeg|jpe|json|mdb|mid|midi|mov|qt|mp3|m4a|mp4|m4v|mpeg|mpg|mpe|mpp|otf|odb|odc|odf|odg|odp|ods|odt|ogg|pdf|png|pot|pps|ppt|pptx|ra|ram|svg|svgz|swf|tar|tif|tiff|ttf|ttc|wav|wma|wri|xla|xls|xlsx|xlt|xlw|zip)$">
		<IfModule mod_headers.c>
			Header unset Pragma
			Header append Cache-Control "public"
		</IfModule>
	</FilesMatch>
</IfModule>

EOS;

		return $extra;
	}

	/**
	 * mod_deflate_rules()
	 *
	 * @return string $rules
	 **/

	static function mod_deflate_rules() {
		$extra = <<<EOS

<IfModule mod_deflate.c>

    # Force compression for mangled headers.
    # http://developer.yahoo.com/blogs/ydn/posts/2010/12/pushing-beyond-gzipping
    <IfModule mod_setenvif.c>
        <IfModule mod_headers.c>
            SetEnvIfNoCase ^(Accept-EncodXng|X-cept-Encoding|X{15}|~{15}|-{15})$ ^((gzip|deflate)\s*,?\s*)+|[X~-]{4,13}$ HAVE_Accept-Encoding
            RequestHeader append Accept-Encoding "gzip,deflate" env=HAVE_Accept-Encoding
        </IfModule>
    </IfModule>

    # Compress all output labeled with one of the following MIME-types
    # (for Apache versions below 2.3.7, you don't need to enable `mod_filter`
    #  and can remove the `<IfModule mod_filter.c>` and `</IfModule>` lines
    #  as `AddOutputFilterByType` is still in the core directives).
    <IfModule mod_filter.c>
        AddOutputFilterByType DEFLATE "application/atom+xml" \
                                      "application/javascript" \
                                      "application/json" \
                                      "application/ld+json" \
                                      "application/manifest+json" \
                                      "application/rdf+xml" \
                                      "application/rss+xml" \
                                      "application/schema+json" \
                                      "application/vnd.geo+json" \
                                      "application/vnd.ms-fontobject" \
                                      "application/x-font-ttf" \
                                      "application/x-javascript" \
                                      "application/x-web-app-manifest+json" \
                                      "application/xhtml+xml" \
                                      "application/xml" \
                                      "font/eot" \
                                      "font/opentype" \
                                      "image/bmp" \
                                      "image/svg+xml" \
                                      "image/vnd.microsoft.icon" \
                                      "image/x-icon" \
                                      "text/cache-manifest" \
                                      "text/css" \
                                      "text/html" \
                                      "text/javascript" \
                                      "text/plain" \
                                      "text/vcard" \
                                      "text/vnd.rim.location.xloc" \
                                      "text/vtt" \
                                      "text/x-component" \
                                      "text/x-cross-domain-policy" \
                                      "text/xml"


		# Don't compress binaries
		SetEnvIfNoCase Request_URI .(?:exe|t?gz|zip|iso|tar|bz2|sit|rar) no-gzip dont-vary

		# Don't compress images
		#SetEnvIfNoCase Request_URI .(?:gif|jpe?g|jpg|ico|png)  no-gzip dont-vary

		# Don't compress PDFs
		SetEnvIfNoCase Request_URI .pdf no-gzip dont-vary

		# Don't compress flash files (only relevant if you host your own videos)
		SetEnvIfNoCase Request_URI .(?:flv|swf) no-gzip dont-vary

	</IfModule>

    <IfModule mod_mime.c>
        AddEncoding gzip              svgz
    </IfModule>


EOS;

		return $extra;
	}

	/**
	 * mod_mime_rules()
	 *
	 * @return string $rules
	 **/

	static function mod_mime_rules() {
		$extra = <<<EOS

<IfModule mod_mime.c>

  # Data interchange
    AddType application/atom+xml                        atom
    AddType application/json                            json map topojson
    AddType application/ld+json                         jsonld
    AddType application/rss+xml                         rss
    AddType application/vnd.geo+json                    geojson
    AddType application/xml                             rdf xml

  # JavaScript
    # Normalize to standard type.
    # https://tools.ietf.org/html/rfc4329#section-7.2

    AddType application/javascript                      js

  # Manifest files
    AddType application/manifest+json                   webmanifest
    AddType application/x-web-app-manifest+json         webapp
    AddType text/cache-manifest                         appcache

  # Images
  	AddType image/bmp                                   bmp
	AddType image/gif                                   gif
	AddType image/jpeg                                  jpg jpeg jpe
	AddType image/tiff                                  tif tiff
	AddType image/png                                   png

  # Media files
    AddType audio/mp4                                   f4a f4b m4a
    AddType audio/ogg                                   oga ogg opus
    AddType image/bmp                                   bmp
    AddType image/svg+xml                               svg svgz
    AddType image/webp                                  webp
    AddType video/mp4                                   f4v f4p m4v mp4
    AddType video/ogg                                   ogv
    AddType video/webm                                  webm
    AddType video/x-flv                                 flv

    # Serving `.ico` image files with a different media type
    # prevents Internet Explorer from displaying them as images:
    # https://github.com/h5bp/html5-boilerplate/commit/37b5fec090d00f38de64b591bcddcb205aadf8ee

    AddType image/x-icon                                cur ico


  # Web fonts
    AddType application/font-woff                       woff
    AddType application/font-woff2                      woff2
    AddType application/vnd.ms-fontobject               eot

    # Browsers usually ignore the font media types and simply sniff
    # the bytes to figure out the font type.
    # https://mimesniff.spec.whatwg.org/#matching-a-font-type-pattern
    #
    # However, Blink and WebKit based browsers will show a warning
    # in the console if the following font types are served with any
    # other media types.

    AddType application/x-font-ttf                      ttc ttf
    AddType font/opentype                               otf

  # Other
    AddType application/octet-stream                    safariextz
    AddType application/x-bb-appworld                   bbaw
    AddType application/x-chrome-extension              crx
    AddType application/x-opera-extension               oex
    AddType application/x-xpinstall                     xpi
    AddType text/vcard                                  vcard vcf
    AddType text/vnd.rim.location.xloc                  xloc
    AddType text/vtt                                    vtt
    AddType text/x-component                            htc
    AddType application/xml                             atom rdf rss xml
	AddType text/plain                                  txt
	AddType text/richtext                               rtf rtx
    AddType application/x-shockwave-flash               swf

  # Compressed Files
	AddType application/x-tar                           tar
	AddType application/x-gzip                          gz gzip
	AddType application/zip                             zip

  # Applications
	AddType application/msword                          doc docx
	AddType application/x-msdownload                    exe
	AddType application/pdf                             pdf
	AddType application/vnd.ms-access                   mdb
	AddType application/vnd.ms-powerpoint               pot pps ppt pptx
	AddType application/vnd.ms-excel                    xla xls xlsx xlt xlw
	AddType application/vnd.ms-project                  mpp

</IfModule>

EOS;

		return $extra;
	}


	/**
	 * mod_expires_rules()
	 *
	 * @return string $rules
	 **/

	static function mod_expires_rules() {
		$extra = <<<EOS

<IfModule mod_expires.c>

    ExpiresActive on
    ExpiresDefault                                      "access plus 1 month"

  # CSS
    ExpiresByType text/css                              "access plus 1 year"

  # Data interchange
    ExpiresByType application/atom+xml                  "access plus 1 hour"
    ExpiresByType application/rdf+xml                   "access plus 1 hour"
    ExpiresByType application/rss+xml                   "access plus 1 hour"

    ExpiresByType application/json                      "access plus 0 seconds"
    ExpiresByType application/ld+json                   "access plus 0 seconds"
    ExpiresByType application/schema+json               "access plus 0 seconds"
    ExpiresByType application/vnd.geo+json              "access plus 0 seconds"
    ExpiresByType application/xml                       "access plus 0 seconds"
    ExpiresByType text/xml                              "access plus 0 seconds"

  # Favicon (cannot be renamed!) and cursor images
    ExpiresByType image/vnd.microsoft.icon              "access plus 1 week"
    ExpiresByType image/x-icon                          "access plus 1 week"

  # HTML
    ExpiresByType text/html                             "access plus 0 seconds"

  # JavaScript
    ExpiresByType application/javascript                "access plus 1 year"
    ExpiresByType application/x-javascript              "access plus 1 year"
    ExpiresByType text/javascript                       "access plus 1 year"

  # Manifest files
    ExpiresByType application/manifest+json             "access plus 1 week"
    ExpiresByType application/x-web-app-manifest+json   "access plus 0 seconds"
    ExpiresByType text/cache-manifest                   "access plus 0 seconds"

  # Media
    ExpiresByType audio/ogg                             "access plus 1 month"
    ExpiresByType image/bmp                             "access plus 1 month"
    ExpiresByType image/gif                             "access plus 1 month"
    ExpiresByType image/jpeg                            "access plus 1 month"
    ExpiresByType image/png                             "access plus 1 month"
    ExpiresByType image/svg+xml                         "access plus 1 month"
    ExpiresByType image/webp                            "access plus 1 month"
    ExpiresByType video/mp4                             "access plus 1 month"
    ExpiresByType video/ogg                             "access plus 1 month"
    ExpiresByType video/webm                            "access plus 1 month"


  # Web fonts
    # Embedded OpenType (EOT)
    ExpiresByType application/vnd.ms-fontobject         "access plus 1 month"
    ExpiresByType font/eot                              "access plus 1 month"

    # OpenType
    ExpiresByType font/opentype                         "access plus 1 month"

    # TrueType
    ExpiresByType application/x-font-ttf                "access plus 1 month"

    # Web Open Font Format (WOFF) 1.0
    ExpiresByType application/font-woff                 "access plus 1 month"
    ExpiresByType application/x-font-woff               "access plus 1 month"
    ExpiresByType font/woff                             "access plus 1 month"

    # Web Open Font Format (WOFF) 2.0
    ExpiresByType application/font-woff2                "access plus 1 month"

  # Application files
    ExpiresByType application/msword                    "access plus 1 month"
    ExpiresByType application/pdf                       "access plus 1 month"
    ExpiresByType application/vnd.ms-access             "access plus 1 month"
    ExpiresByType application/vnd.ms-write              "access plus 1 month"
    ExpiresByType application/vnd.ms-excel              "access plus 1 month"
    ExpiresByType application/vnd.ms-powerpoint         "access plus 1 month"
    ExpiresByType application/vnd.ms-project            "access plus 1 month"
    ExpiresByType text/richtext                         "access plus 1 month"
    ExpiresByType text/plain                            "access plus 1 month"
    ExpiresByType application/x-msdownload              "access plus 1 month"
    ExpiresByType application/x-shockwave-flash         "access plus 1 month"
	ExpiresByType application/java                      "access plus 1 month"

  # Compressed files
    ExpiresByType application/x-tar                     "access plus 1 month"
    ExpiresByType application/zip                       "access plus 1 month"
    ExpiresByType application/x-gzip                    "access plus 1 month"

  # Other
    ExpiresByType text/x-cross-domain-policy            "access plus 1 week"

</IfModule>

EOS;

		return $extra;
	}

}# sem_cache_rules

$sem_cache_rules = sem_cache_rules::get_instance();
$sem_cache_rules->init();