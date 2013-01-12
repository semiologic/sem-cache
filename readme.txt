=== Semiologic Cache ===
Contributors: Denis-de-Bernardy && Mike Koepke
Donate link: http://www.semiologic.com/partners/
Tags: semiologic
Requires at least: 3.1
Tested up to: 3.5
Stable tag: 2.3.2

A high performance cache for WordPress.


== Description ==

The Semiologic Cache plugin for WordPress is a high performance cache for sites built using WP.

Activate the Semiologic Cache plugin, and browse Settings / Cache to use the plugin.

Contrary to similar WP plugins, this one strives for simplicity. There are no complicated looking options to choose from. The cache's various elements are either on, or off -- with no options.

It implements each and every one of the following:

- Static-level caching, which potentially serves pre-generated pages even before PHP loads
- Query-level caching, which serves cached SQL queries to avoid hits to the database for logged in users
- Persistent object-level caching, which makes WP objects persistent from a page to the next to avoid further hits to the database
- Asset-level caching, which concatenates javascript and CSS files on the site's front end to avoid further hits to the server
- GZip-level caching, which conditionally serves compressed files at the apache level (which is faster than using php)


= Help Me! =

The [Semiologic forum](http://forum.semiologic.com) is the best place to report issues.


== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress


== Change Log ==

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
