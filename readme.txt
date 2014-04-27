=== Semiologic Cache ===
Contributors: Denis-de-Bernardy && Mike Koepke
Donate link: http://www.semiologic.com/partners/
Tags: semiologic
Requires at least: 3.3
Tested up to: 3.9
Stable tag: trunk

A high performance cache for WordPress.


== Description ==

The Semiologic Cache plugin for WordPress is a high performance cache for sites built using WP.

**Important: For those upgrading, turn the off caching (Settings->Cache) then back on to force new server rules to be written**

Activate the Semiologic Cache plugin, and browse Settings / Cache to use the plugin.

Contrary to similar WP plugins, this one strives for simplicity. There are no complicated looking options to choose from. The cache's various elements are either on, or off -- with no options.

It implements each and every one of the following:

- Static-level caching, which potentially serves pre-generated pages even before PHP loads
- Query-level caching, which serves cached SQL queries to avoid hits to the database for logged in users
- Persistent object-level caching, which makes WP objects persistent from a page to the next to avoid further hits to the database
- Asset-level caching, which concatenates javascript and CSS files on the site's front end to avoid further hits to the server
- GZip-level caching, which conditionally serves compressed files at the apache level (which is faster than using php)

= Manual Disabling of Page Cache =

You have the ability to manually turn off caching for a given page.   The plugin supports 2 detection methods to not cache a page.

If the text 'sem_do_not_cache' is found anywhere on the page, the plugin simple ignores generating a static version of the page.

If you're using the Semiologic Scripts & Meta plugin, you can add

	<!--sem_do_not_cache-->

to the header area when editing the page.   Alternatively you can drop the <!--sem_do_not_cache--> into the HTML tab or a page to add to the page content.   The tag be hidden in the page content.

OR

If the PHP constant DONOTCACHEPAGE is set to 'true', caching of the page is ignored.

	define( 'DONOTCACHEPAGE', true );

Note: Asset caching (css and js) still occurs.  This flag only effects static page caching.


= Help Me! =

The [Semiologic forum](http://forum.semiologic.com) is the best place to report issues.


== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress

== Notes ==

**Important: Turn the cache off (Settings->Cache) then back on to force new server rules to be written after upgrading the plugin**


== Change Log ==

= 2.7 =

- Code refactoring
- Silence 'Directory not empty' warning message in the cache-fs.php file
- Further tweaking to fix invalid operation warning message in the cache-fs.php file
- WP 3.9 compat

= 2.6 =

- Added ability to manually disable caching for a page.
- Pages that include the Semiologic Contact Form widget are no longer cached.   Caching conflicted with spam prevention techniques and was resulting in occasional false positives.
- Fix invalid operation warning message in the cache-fs.php file
- Updated htaccess caching rules

= 2.5 =

- Some Static Cache rules written to htaccess even if cached was turn off.
- Fixed undefined variable in query_cache module
- WP 3.8 compat

= 2.4.1 =

- Fix Undefined variable: wpdb in /wp-content/plugins/sem-cache/query-cache.php on line 526 warning

= 2.4 =

- Updated htaccess caching rules
- www -> non-www or non-www -> www redirect rules added to htaccess is caching is turned on.
- WP 3.6 compat
- PHP 5.4 compat
**Important: Turn the cache off (Settings->Cache) then back on to force new server rules to be written**

= 2.3.6 =

- Add Blackberry 10 User Agent detection

= 2.3.5 =

- Turn off auto enabling of cache

= 2.3.4 =

- Resolved unknown index warnings

= 2.3.3 =

- Fix: 3rd time the charm?  wpdb::escape_by_ref() expected to be a reference message in query-cache.php

= 2.3.2 =

- Fix: wpdb::escape_by_ref() expected to be a reference message in query-cache.php
- Silence warning message attempting to delete non-existant file
- Fix misc. coding errors spotted by phpstorm

= 2.3.1 =

- Fix: Detection of mobile user agents that used mixed case failed

= 2.3 =

- Updated mobile agent detection Opera and Android Tablets
- Asset cache is now cleared when Scripts & Meta is updated, theme is switched
  or plugins are activated/deactivated
- Flush cache on Profile update due to Google+ Authorship
- Fix warning message deleting non-existent file.  Seems to be PHP 5.3 issue


= 2.2 =

- Fix static caching as WP_CACHE was not being written to wp-config.php
- Add mod_expires and mod_mime sections.
- Added more mod_deflate filters
- Add cache control headers  
- Added auto-enable logic and source code switch to enable
- Updated mobile User Agents

= 2.1.1 =

- WP 3.0.1 compat

= 2.1 =

- WP 3.0 compat

= 2.0.1 =

- Fix Apache 1.3 quirks
- Improve safe_mode and open_basedir handling

= 2.0 =

- Complete rewrite
- Add query cache, object cache, asset cache and gzip cache

= 1.2.1 =

- Improve handling of custom wp-content and plugin folders

= 1.2 =

- Don't super cache requests with a cookie or GET parameter
