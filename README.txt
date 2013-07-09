=== Mollom (old) ===
Contributors: Matthias Vandermaesen
Requires at least: 2.5.0
Tested up to: 2.9.0
Stable tag: 0.7.5

Superseded by new Mollom plugin.

== Description ==

Superseded by new and vastly improved [Mollom plugin](http://wordpress.org/plugins/mollom/).

= How to upgrade =

1. Deactivate and uninstall/delete the old `wp-mollom` plugin (version 0.7.5 or older).
1. Install the new [Mollom plugin](http://wordpress.org/plugins/mollom/) (version 2.0 or later).
1. Re-enter your Mollom API keys.

There is no automated upgrade path, since the plugin has been entirely rewritten, and re-installing the new is a matter of minutes.  Also, this old plugin was used by a few users only.  
We're sorry for this (one-time) inconvenience.


== Installation ==

Do not install this plugin; **use the new [Mollom plugin](http://wordpress.org/plugins/mollom/) instead**.


== Credits ==

Thank you very much for supporting this project! These people contributed to the plugin with translations,
pointing out bugs and helpful suggestions.

* Dries Buytaert (http://buytaert.net)
* DonaldZ (http://zuoshen.com)
* Alexander Langer (http://webseiter.de)
* Gianni Diurno (http://www.gidibao.net)
* Pascal Van Hecke (http://pascal.vanhecke.info/)
* John Eckman (http://www.openparenthesis.org/)
* Paul Maunders (http://www.pyrosoft.co.uk/blog)
* Petko Stoyanov
* 9el (http://lenin9l.wordpress.com/)
* Minh-Quân Tran

== Changelog ==

* Replaced by new and vastly improved [Mollom plugin](http://wordpress.org/plugins/mollom/).
* 2009/12/20 - 0.7.5
 * fixed: wrong character encoding when comment is fed to wordpress after a CAPTCHA
 * fixed: url was also truncated in href if > 32 chars in the management module
 * fixed: changed 2 strings against typo's
 * improved: added pagination on the bottom of the management module
 * changed: contact details of plugin author
* 2009/04/18 - 0.7.4
 * added: vietnamese (vi) translation
 * added: bulgarian (bg_BG) translation
 * added: bangla (bn_BD) translation
* 2009/03/16 - 0.7.3
 * fixed: multiple moderation would incorrectly state 'moderation failed' due to incorrect set boolean.
 * added: german (de_DE) translation
 * added: italian (it_IT) translation
* 2009/02/12 - 0.7.2
 * fixed: closing a gap that allowed bypassing checkContent through spoofing $_POST['mollom_sessionid']
 * fixed: if mb_convert_encoding() is not available, the CAPTCHA would generate a PHP error. Now falls back to htmlentities().
 * improved: the check_trackback_content and check_comment_content are totally rewritten to make them more secure.
 * added: user roles capabilities. You can now exempt roles from a check by Mollom
 * added: simplified chinese (zh_CN) translation
* 2008/12/27 - 0.7.1
 * fixed: all plugin panels now show in the new WP 2.7 administration interface
 * fixed: non-western character sets are now handled properly in the captcha form
 * fixed: handles threaded comments properly now
 * fixed: handling multiple records in the manage module not correctly handled
 * improved: extra - non standard- fields added to the comment form don't get dropped anymore
 * improved: revamped the administration panel
 * improved: various smaller code improvements
 * added: the plugin is now compatible with the new plugin uninstall features in Wordpress 2.7
 * added: the 'quality' of 'spaminess' of a comment is now logged and shown as an extra indicator
* 2008/11/27 - 0.7.0
 * fixed: hover over statistics bar graph wouldn't yield numerical data
 * added: localization/internationalisation (i8n) support. Now you can translate wp-mollom through POEdit and the likes.
* 2008/11/10 - 0.6.2
 * fixed: wrong feedback qualifiers (spam, profanity, unwanted, low-quality) were transmitted to Mollom upon moderation
* 2008/09/24 - 0.6.1
 * fixed: division by 0 error on line 317
 * fixed: if 'unsure' but captcha was filled in correctly, HTML attributes in comment content would sometimes be eaten by kses.
 * improved: the mollom function got an overhaul to reflect the september 15 version of the Mollom API documentation
 * changed: mollom statistics are now hooked in edit-comments.php instead of plugins.php
 * added: _mollom_retrieve_server_list() function now handles all getServerList calls
* 2008/08/24 - 0.6.0
 * fixed: html is preserved in a comment when the visitor is confronted with the captcha
 * fixed: handling of session id's in show_captcha() en check_captcha() follows the API flow better.
 * fixed: broken bulk moderation of comments is now fixed
 * fixed: the IP adress was incorrectly passed to the 'mollom.checkCaptcha' call
 * fixed: the session_id is now passed correctly to _save_session() after the captcha is checked.
 * improved: more verbose status messages report when using the Mollom Manage module
 * improved: cleaned up some deprecated functions
 * improved: handling of Mollom feedback in _mollom_send_feedback() function
 * added: approve and unapprove options in the Mollom Manage module
 * added: link to the originating post in the Mollom Manage module
 * added: if a comment had to pass a CAPTCHA, it will be indicated in the Mollom Manage module
 * added: plugin has it's own HTTP USER AGENT string which will be send with XML RPC calls to the API
 * added: detailed statistics. You can find these under Plugins > Mollom
* 2008/07/20 - 0.5.2
 * fixed: passing $comment instead of $_POST to show_captcha() in check_captcha()
 * improved: implemented wpdb->prepare() in vunerable queries
 * improved: mollom_activate() function now more robust
 * changed: mollom_author_ip() reflects changes in the API documentation. This function is now 'reverse proxy aware'
* 2008/06/30 - 0.5.1
 * fixed: issues with the captcha page not being rendered correctly
 * added: mollom_manage_wp_queue() function which deals with Mollom feedback from the default WP moderation queue
 * improved: legacy code when activating the plugin (needed for upgrading from < 0.5.0 (testversions!)
* 2008/06/26 - 0.5.0
 * Added: installation/activation can contain legacy code and versioning for handling old (test)configurations
 * Added: PHPDoc style documentation of functions
 * Added: mollom_moderate_comment() template function. Allows moderation from your theme.
 * Removed: 'moderation mode'. Moderation should only be configured through the proper wordpress interface.
 * fixed: compatibility issues with the WP-OpenID plugin
 * Improved: the plugin relies far less on global variables now.
 * Improved: all mollom data is now saved to it's own seprerate, independent table.
 * Improved: SQL revision
 * Improved: error handling is now more verbose
 * Improved: status messages in the configuration/moderation panels now only show when relevant
 * Improved: handling of mollom servers not being available or unreachable
* 2008/06/03 - 0.4
 * Changed: 'configuration' now is under WP 'settings' menu instead of 'plugins'
 * Added: show_mollom_plugincount() as a template function to show off your mollom caught
* 2008/05/27 - 0.3
 * Added: trackback support. If ham: passed. If unsure/spam: blocked.
 * Added: 'moderation mode' mollom approved comments/trackbacks still need to be moderated
 * Added: 'Restore' When the plugin is deactivated, optionally purge all mollom related data
 * Changed: moderation isn't mandatory anymore, only optional. Comments aren't saved to the  database until the CAPTCHA is filled out correctly. Otherwise: never registered.
 * Improved: Error handling now relies on WP Error handling (WP_Error object)
* 2008/05/22 - 0.2
 * Added: bulk moderation of comments
 * Added: 'policy mode' disables commenting if the Mollom service is down
 * Improved: moderation interface is more userfriendly
 * Improved: only unmoderated messages with a mollom session id can be moderated
 * Improved: deactivation restores database to previous state. Removal of stored option values and deletion of the mollom_session_id column in $prefix_comments
 * Fixed: persistent storage of the mollom session id in the database
 * Fixed: no messages shown in the configuration screen triggers a PHP error
* 2008/05/12 - 0.1
 * Initial release to testers
