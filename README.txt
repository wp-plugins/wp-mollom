WP MOLLOM

A plugin that brings the power of Mollom (http://www.mollom.com) to Wordpress and makes your website spamfree!

Version: 0.5.0
Author: Matthias Vandermaesen
URL: http://www.netsensei.be
Contact: matthias@netsensei.nl
Date: 2008/06/28
REQUIREMENTS:
* A Mollom account (http://www.mollom.com)
* A Wordpress installation >= 2.5.0

INSTALLATION:

* Drop wp-mollom.php in /wp-content/plugins
* Activate the plugin in your dashboard.
* Go to the 'mollom configuration' panel and enter the public/private key combination you got upon registering
  with the Mollom service. You can can create an account and register your website at http://www.mollom.com.

All comments posted to your blogs should now go through the Mollom service.

USAGE

Mollom takes care of everything. The idea is to relieve you, the administrator, editor, maintainer,... of 
whatever moderation or clean up tasks you would normally need to perform in order to keep your blog spamfree.
After you have set the public/private key combination, mollom will automatically protect your blog. If it is
not sure, it will present the commenter with a CAPTCHA test. Unless the test was completed correct, the plugin
will stop the comment from being saved into your database.

Although this reduces the need to moderation is still possible. You never know if a comment was treated as a false 
positive. You can moderate comments through the admin interface or insert the mollom_moderate_comment() function 
into your theme which makes direct moderation of a comment possible. Moderation is encouraged as you will send 
feedback to mollom from which it will learn.

There are four basic types of moderation:

* Spam: if the comment seems to be spam nonetheless.
* Profanity: if the comment contains swearing
* Low Quality: if the comment isn't really consistent or doesn't make much sense
* Unwanted: if the comment was i.e. posted by a particular person or bot.

Extra options in the configuration panel:

* Policy mode: if enabled, all comments/trackbacks will be blocked if the Mollom services are not available. If 
  you have a high traffic site, this might be useful if you can't respond right away. 
* Restore mode: if enabled, the 'mollom' table which contains mollom related information (session id's) and all
  mollom options will be deleted from your database.

NOTES:

* Although this plugin can be used on Wordpress MU, it is not designed nor supported to do so. Wordpress MU will
  be fully supported in future versions.
* The backend handling and storing of data has been significantly changed. The plugin will try to convert the 
  existing data if you used an earlier version of the plugin.* If you don't set policy mode, comments will not 
  pass through the Mollom filter yet they are   treated in the default fashion. This means a Mollom session ID 
  will not be assigned to them. This ID is necessary for moderation. As a result, these comments will not show up 
  in the mollom moderation queue.

CHANGELOG:

2008/06/26		0.5.0	Added: installation/activation can contain legacy code and versioning for handling old 
					(test)configurations
				Added: PHPDoc style documentation of functions
				Added: mollom_moderate_comment() template function. Allows moderation from your theme.
				Removed: 'moderation mode'. Moderation should only be configured through the proper 
					wordpress interface.
				fixed: compatibility issues with the WP-OpenID plugin
				Improved: the plugin relies far less on global variables now.
				Improved: all mollom data is now saved to it's own seprerate, independent table.
				Improved: SQL revision
				Improved: error handling is now more verbose
				Improved: status messages in the configuration/moderation panels now only show when relevant				Improved: handling of mollom servers not being available or unreachable
2008/06/03		0.4	Changed: 'configuration' now is under WP 'settings' menu instead of 'plugins'
				Added: show_mollom_plugincount() as a template function to show off your mollom caught
2008/05/27		0.3	Added: trackback support. If ham: passed. If unsure/spam: blocked.
				Added: 'moderation mode' mollom approved comments/trackbacks still need to be moderated
				Added: 'Restore' When the plugin is deactivated, optionally purge all mollom related data
				Changed: moderation isn't mandatory anymore, only optional. Comments aren't saved to the 
					dbase until the CAPTCHA is filled out correctly. Otherwise: never registered.
				Improved: Error handling now relies on WP Error handling (WP_Error object)
2008/05/22		0.2	Added: bulk moderation of comments
				Added: 'policy mode' disables commenting if the Mollom service is down
				Improved: moderation interface is more userfriendly
				Improved: only unmoderated messages with a mollom session id can be moderated
				Improved: deactivation restores database to previous state. Removal of stored option
					  values and deletion of the mollom_session_id column in $prefix_comments
				Fixed: persistent storage of the mollom session id in the database
				Fixed: no messages shown in the configuration screen triggers a PHP error
2008/05/12		0.1	Initial release to testers