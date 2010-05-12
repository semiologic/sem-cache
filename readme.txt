=== Semiologic Cache ===
Contributors: Denis-de-Bernardy
Donate link: http://www.semiologic.com/partners/
Tags: semiologic
Requires at least: 2.8
Tested up to: 3.0
Stable tag: trunk

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
