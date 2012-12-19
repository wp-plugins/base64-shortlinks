=== Base64 Shortlinks ===

Contributors: jwz
Tags: shortlinks
Requires at least: 2.7
Tested up to: 3.5
Stable tag: 1.3

This plugin makes your shortlinks shorter!

== Description ==

The default WordPress "shortlink" URLs look like this:
`http&#58;//www.example.com/blog/?p=123`, where "`123`" is actually a
7+ digit decimal number. This plugin shrinks your shortlinks by
encoding that number into only 4 characters, and using the abbreviated
URL prefix of your choice.

On my site, the default shortlinks are 35 bytes long, even though I
have a very short domain name. This plugin shrinks them to 21 total
bytes, which is comparable to most public URL-shortener services, and
better than many.

E.g., this: `http&#58;//www.jwz.org/blog/?p=13240780`  
becomes: `http&#58;//jwz.org/b/ygnM`

This doesn't affect your (long) permalinks: those can still be in
whatever format you like.

== Installation ==

1. Upload the `base64-shortlinks` directory to your `/wp-content/plugins/`
   directory.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Select the "Base64 Shortlinks" menu under "Settings" and enter your
   Shortlink URL Prefix.

== Changelog ==

= 1.0 =
* Created

= 1.1 =
* Shortlinks that happened to have "-" in them were failing.  Fixed.

= 1.2 =
* Fixed a bug that affected blogs installed in the root directory of
  their site.

= 1.3 =
* Fixed a bug that caused shortlinks to be longer than necessary on
  32-bit systems.
