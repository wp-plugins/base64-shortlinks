<?php
/*
Plugin Name: Base64 Shortlinks
Plugin URI: http://www.jwz.org/base64-shortlinks/
Version: 1.1
Description: This plugin makes your shortlinks shorter! Depending on your domain name, this can reduce the length of your shortlink URLs to as few as 20 characters, which is comparable to or better than what most public URL-shortening services can accomplish.
Author: Jamie Zawinski
Author URI: http://www.jwz.org/
*/

/* Copyright © 2010-2011 Jamie Zawinski <jwz@jwz.org>

   Permission to use, copy, modify, distribute, and sell this software and its
   documentation for any purpose is hereby granted without fee, provided that
   the above copyright notice appear in all copies and that both that
   copyright notice and this permission notice appear in supporting
   documentation.  No representations are made about the suitability of this
   software for any purpose.  It is provided "as is" without express or 
   implied warranty.
 
   Created: 20-Dec-2010

   This implements a custom URL-shortener for WordPress blogs.

   For example, the blog post:
       http://www.jwz.org/blog/2011/08/base64-shortlinks/

   has this default shortlink:
       http://www.jwz.org/blog/?p=13240780 (35 bytes)

   Other services give us: 
       http://tinyurl.com/3et9fw7 (26 bytes)
       http://bit.ly/qbFuII (20 bytes)
       http://goo.gl/xraFX (19 bytes)
       http://t.co/jJAv1SQ (19 bytes)
       http://dnklg.tk/ (16 bytes)

   This code gives us:
       http://jwz.org/b/ygnM (21 bytes)

   So, that's pretty good...
 */


$b64sl_plugin_title  = 'Base64 Shortlinks';
$b64sl_plugin_name   = 'base64-shortlinks';
$b64sl_prefs_url_key = 'base_url';
$b64sl_prefs_url_id  = "$b64sl_plugin_name-$b64sl_prefs_url_key";


/*************************************************************************
 Encoding and decoding.
 *************************************************************************/


/* Given a 64-bit integer, encode it in URL-friendly big-endian base64 form.
 */
function b64sl_pack_id($id) {
  $id = intval($id);
  $id = pack ('N', $id >> 32) .			// 32 bit big endian, top
        pack ('N', $id & 0xFFFFFFFF);		// 32 bit big endian, bottom
  $id = preg_replace('/^\000+/', '', "$id");	// omit high-order NUL bytes
  $id = base64_encode ($id);
  $id = str_replace ('+', '-', $id);		// encode URL-unsafe "+" "/"
  $id = str_replace ('/', '_', $id);
  $id = preg_replace ('/=+$/', '', $id);	// omit trailing padding bytes
  return $id;
}


/* Decode a base64-encoded big-endian integer of up to 64 bits.
 */
function b64sl_unpack_id($id) {
  $id = str_replace ('-', '+', $id);		// decode URL-unsafe "+" "/"
  $id = str_replace ('_', '/', $id);
  $id = base64_decode ($id);
  while (strlen($id) < 8) { $id = "\000$id"; }	// pad with leading NULs
  $a = unpack ('N*', $id);			// 32 bit big endian
  $id = ($a[1] << 32) | $a[2];			// pack top and bottom word
  return $id;
}


function b64sl_encoder_selftest() {
  $out = '';
  foreach (Array (0,
                  1,
                  17,
                  65,
                  254,
                  255,
                  256,
                  4067,
                  0xDEAD,
                  65534,
                  65535,
                  65536,
                  598217,
                  7108496,
                  13239493,
                  13241918,
                  16777214,
                  16777215,
                  16777216,
                  987473985,
                  0xDEADBEEF,
                  0xFFFFFFFF,
                  0xFFFFFFFF1,
                  459699516001,
                  0xFFFFFFFFFF,
                  0xFFFFFFFFFF1,
                  0xFFFFFFFFFFFF,
                  0xFFFFFFFFFFFF1,
                  0xFF3FF5FF6789a,
                  0xFFFFFFFFFFFFFF,
                  0xCAFE0000000CAFE,
                  0xFFFFFFFFFFFFFF1,
                  0x7EADBEEFDEADBEEF,
                  -1,
               // 0xDEADBEEFDEADBEEF,  // no unsigned long in php
               // 0xFFFFFFFFFFFFFFFF,
      ) as $id) {
    $enc = b64sl_pack_id($id);
    $id2 = b64sl_unpack_id($enc);
    $out .= sprintf ("%s %20u  %2d  0x%016X  0x%016X  %2d  %s\n",
                     ($id == $id2 ? 'ok ' : 'BAD'),
                     $id, strlen(sprintf("%u", $id)), $id,
                     $id2, strlen($enc), $enc);
  }
  return $out;
}


/*************************************************************************
 URL parsing: make ?p=XXXX work where XXXX is a base64 ID, not decimal.
 *************************************************************************/

add_action ('parse_query', 'b64sl_parse_query');

function b64sl_parse_query ($query) {

  $p = $query->query['p'];
  if (!isset($p)) return;
  if (absint($p) && get_post($p)) return;  // already the decimal ID of a post.

  if (preg_match ('@^[-_/+A-Za-z0-9]+$@', $p)) { // looks like base64 so far...
    $p2 = b64sl_unpack_id ($p);

    // Now we have to un-set a bunch of crap that already got parsed...

    if ($p2) {
      $query->query['p']             = $p2;
      $query->query_vars['p']        = $p2;
      $query->query['pagename']      = '';
      $query->query_vars['pagename'] = '';
      $query->is_single              = true;
      $query->is_singular            = true;
      $query->is_page                = false;
      $query->is_home                = false;
      $query->query_vars_changed     = true;
    }
  }
}


/* Converts the stock WordPress shortlink into the new style.
 */
add_filter ('pre_get_shortlink', 'b64sl_pre_get_shortlink', 10, 4);

function b64sl_pre_get_shortlink ($shortlink, $id, $context, $allow_slugs) {
  global $b64sl_plugin_name;
  global $b64sl_prefs_url_key;

  $post = get_post($id);
  if (empty($post)) return false;
  $id = $post->ID;
  if (empty($id)) return false;
  if (!isset($post->post_type) || 'post' != $post->post_type)
    return false;

  $options = get_option ($b64sl_plugin_name);
  $url = $options[$b64sl_prefs_url_key];
  if (! $url) return false;

  $url .= b64sl_pack_id ($id);
  return $url;
}


/*************************************************************************
 Admin pages
 *************************************************************************/


/* I would like to put our single option on the "Permalinks" page, but
   a bug prevents that!  http://core.trac.wordpress.org/ticket/9296
   So instead we create our own settings page.
 */
add_action('admin_menu', 'b64sl_admin_add_page');

function b64sl_admin_add_page() {
  global $b64sl_plugin_title;
  global $b64sl_plugin_name;

  add_options_page ($b64sl_plugin_title . ' Options', $b64sl_plugin_title,
                    'manage_options', $b64sl_plugin_name,
                    'b64sl_options_page');
}


/* Create our preferences page.
 */
function b64sl_options_page() {
  global $b64sl_plugin_name;
  global $b64sl_prefs_url_key;

?>
  <style>
   #wpbody-content p { max-width: 60em; margin-right; 1em; }
  </style>
  <div>
   <h2>Base64 Shortlinks</h2>
   <i>By <a href="http://www.jwz.org/">Jamie Zawinski</a></i>

   <p> This plugin makes your shortlinks shorter!

   <p> The default WordPress "shortlink" URLs look like this:
   <code><?php echo get_option('home'); ?>/?p=123</code>,
   where <code>"123"</code> is actually a 7+ digit decimal number.
   This plugin shrinks your shortlinks by encoding that number into
   only 4 characters, and using the abbreviated URL prefix
   of your choice.

   <p> On my site, the default shortlinks are 36 bytes long, even
   though I have a very short domain name.  This plugin shrinks
   them to 21 total bytes, which is comparable to most public
   URL-shortener services, and better than many.
<?
//  print "<p><pre>" . b64sl_encoder_selftest() . "</pre><p>";
?>
   <p>
   <form action="options.php" method="post">
    <?php settings_fields ($b64sl_plugin_name); ?>
    <?php do_settings_sections ($b64sl_plugin_name); ?>
    <p>
    <input name="Submit" type="submit"
           value="<?php esc_attr_e('Save Changes'); ?>" />
   </form>
  </div>

  <p> If your blog is in the root directory of your site, and your
  Shortlink URL Prefix is on the same site, then this should all work
  automatically.  However, if your shortlink prefix points to a
  different directory than your blog, or to a different domain entirely,
  then you will need to edit the appropriate <code>.htaccess</code> file
  manually.  That will be necessary if, for example, your blog is at
  <code>http://www.example.com/blog/</code> and you want your
  shortlinks to begin with <code>http://example.com/b/</code> or
  <code>http://exam.pl/b/</code>.
  <p>
<?

  /* I guess this is the closest we can get to "flush the rules
     when the user hit the Submit button"?
   */
  if (isset($_GET['settings-updated']) && $_GET['settings-updated']) {
    global $wp_rewrite;
    $wp_rewrite->flush_rules();
  }

  $a = b64sl_make_rewrite_rule();
  if (! $a) {
    $options = get_option ($b64sl_plugin_name);
    $url = $options[$b64sl_prefs_url_key];
    print "<p>";
    if (empty($url))
      print "Enter your URL Prefix and press the Save button.";
    else
     print "Oops! Something's wrong with the URL Prefix <code>" .
           htmlspecialchars($url) . "</code>.  Try again?";
    print "<p>";

  } else {
    $host  = $a[0];
    $base  = $a[1];
    $rules = $a[2];
    $ht = '';
    $ht .= "<IfModule mod_rewrite.c>\n" .
           "  RewriteEngine On\n";
    if ($base) $ht .= "  RewriteBase $base\n";
    foreach ($rules as $from => $to) {
      $ht .= "  RewriteRule $from $to [QSA,L]\n";
    }
    $ht .= "</IfModule>\n";
    $ht = preg_replace('/\n/', '<br>', htmlspecialchars($ht));

    print "<p>";

    if (!$host && $base) {
      print "This should have ended up in the <code>" . $base . 
            ".htaccess</code> file:";
    } else {
      print "You will need to put this in the <code>.htaccess</code> file";
      if ($host) print " in the document root of <code>$host</code>";
      print ":\n";
    }

    print "<p><pre style='display:inline-block; background:#EAEAEA;" .
                        " margin-left: 2em; padding: 0.5em 1em;'>" .
	  "$ht</pre><p>\n";
  }
}


/* Add a "Settings" link on the "Plugins" page too, next to "Deactivate".
 */
add_filter ('plugin_action_links', 'b64sl_add_settings_link', 10, 2);

function b64sl_add_settings_link ($links, $file) {
   global $b64sl_plugin_name;
   if ($file == "$b64sl_plugin_name/$b64sl_plugin_name.php" &&
       function_exists ('admin_url')) {
     $link = '<a href="' .
       admin_url ("options-general.php?page=$b64sl_plugin_name") .
       '">' . __('Settings') . '</a>';
     array_unshift ($links, $link);
  }
  return $links;
}


/* Create the preferences fields and hook in to the database.
 */
add_action('admin_init', 'b64sl_admin_init');

function b64sl_admin_init() {
  global $b64sl_plugin_title;
  global $b64sl_plugin_name;
  global $b64sl_prefs_url_id;

  register_setting ($b64sl_plugin_name, $b64sl_plugin_name,
                    'b64sl_options_validate');

  add_settings_section ($b64sl_plugin_name, $b64sl_plugin_title . ' Settings',
                        'b64sl_section_text', $b64sl_plugin_name);
  add_settings_field ($b64sl_prefs_url_id, 'Shortlink URL Prefix',
                      'b64sl_setting_string', $b64sl_plugin_name,
                      $b64sl_plugin_name);

  add_filter ('generate_rewrite_rules', 'b64sl_rewrite');
}


function b64sl_section_text() {
?>
  <p> Enter the prefix you would like to use for your shortlinks
  here.  Use the shortest prefix you can (e.g., leave the
  <code>"www."</code> part off, and use a one or two character
  pathname).  If your domain name is long, you might consider
  registering a second domain with fewer characters just to use
  for shortlinks.
<?
}


/* Generates the <input> form element for our preference.
 */
function b64sl_setting_string() {
  global $b64sl_plugin_name;
  global $b64sl_prefs_url_key;
  global $b64sl_prefs_url_id;

  $options = get_option ($b64sl_plugin_name);
  $def_url = $options[$b64sl_prefs_url_key];

  if (!$def_url) {
    $def_url = get_option('home');
    $def_url = preg_replace ('@^([a-z]+:\d*//)(www\.)([^/:]+).*$@i',
			     '$1$3', $def_url);
    $def_url .= '/b/';
  }

  echo "<input id='$b64sl_prefs_url_id'
             name='" . $b64sl_plugin_name . "[" . $b64sl_prefs_url_key . "]'
             size='38' type='text' value='{$def_url}' />";
}


/* Simple sanity-checking on the typed-in URL.
 */
function b64sl_options_validate($input) {
  global $b64sl_prefs_url_key;

  $newinput[$b64sl_prefs_url_key] = trim ($input[$b64sl_prefs_url_key]);
  if (!preg_match ('@^https?:[-_/?.,a-z\d]+$@i',
                   $newinput[$b64sl_prefs_url_key]))
    $newinput[$b64sl_prefs_url_key] = '';
  return $newinput;
}


/* Computes and returns the mod_rewrite rule necessary to make this work.
   Return value is an array of [ <Domain>, <RewriteBase>, [ <from> => <to> ]].
   If Domain is non-null, or if RewriteBase is null, then the .htaccess file
   must be updated manually, because these rules need to go in a different
   .htaccess file than the one we have write access to.
 */
function b64sl_make_rewrite_rule() {
  global $b64sl_plugin_name;
  global $b64sl_prefs_url_key;

  $options = get_option ($b64sl_plugin_name);
  $url = $options[$b64sl_prefs_url_key];

  $home = preg_replace ('@/+$@', '', get_option ('home'));

  $blog_base = preg_replace ('@^([a-z]+://)(www\.)@i', '$1', $home);

  if (!preg_match ('@^[a-z]+://([^/:]+)/(.*)[:\d]*(.*)$@i', $url, $match))
    return false;
  $url_host = $match[1];
  $url_root = $match[2];

  if (!preg_match ('@^[a-z]+://([^/:]+)/(.*)[:\d]*(.*)$@i', $blog_base,$match))
     return false;
  $blog_host = $match[1];
  $blog_root = $match[2];

  /* If the "Shortlink URL Prefix" is on the same host as the blog,
     and in a subdirectory of it, then we can add a rewrite rule.
     E.g., we can do a rewrite rule for:

         Short: http://example.com/b/XXXX
          Blog: http://www.example.com/?p=XXXX
     Or:
         Short: http://example.com/blog/b/XXXX
          Blog: http://www.example.com/blog/?p=XXXX

     But if they are on different hosts, we can't rewrite, because that
     rule has to go on the rewriting host:

         Short: http://exam.pl/b/XXXX
          Blog: http://www.example.com/blog/?p=XXXX

     Also if the blog is in a subdirectory and the rewriter is not,
     we can't rewrite those either, because that has to go in the
     .htaccess file that is one level higher:

         Short: http://example.com/b/XXXX
          Blog: http://example.com/blog/?p=XXXX
   */

  if (!strncmp ($url, $blog_base, strlen($blog_base))) {	# same subdir
    $url_root = substr ($url, strlen($blog_base));
    if (empty ($url_root)) return false; // bad url!
    $url_root = preg_replace ('@^/+@', '', $url_root);
    $target = 'index.php?p=$1';
    $url_host = '';
    $blog_root = $blog_root ? "/$blog_root/" : "/";

  } else if ($url_host === $blog_host) {			# same host
    $target = "/$blog_root/index.php?p=\$1";
    $blog_root = '';
  } else {							# diff hosts
    $target = "$home/?p=\$1";
    $blog_root = '';
  }

  $rules = array ( "^$url_root(.*)\$" => $target );
  return Array ($url_host, $blog_root, $rules);
}


/* Write changes to the .htaccess file.
 */
function b64sl_rewrite ($wp_rewrite) {

  $a = b64sl_make_rewrite_rule();
  if (! $a) return false;
  $host  = $a[0];
  $base  = $a[1];
  $rules = $a[2];
  if (!$host && $base) {
    // blog and redirector are in the same dir on the same host.
    $wp_rewrite->non_wp_rules = $rules + $wp_rewrite->non_wp_rules;
  }
}
