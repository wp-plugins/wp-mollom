<?php
/* Plugin Name: Mollom
Plugin URI: http://wordpress.org/extend/plugins/wp-mollom/
Description: Enable <a href="http://www.mollom.com">Mollom</a> on your wordpress blog
Author: Matthias Vandermaesen
Version: 0.6.2
Author URI: http://www.netsensei.nl
Email: matthias@netsensei.nl

Version history:
- 2 april 2008: creation
- 12 may 2008: first closed release
- 22 may 2008: second closed release
- 29 may 2008: third closed release
- 3 june 2008: first public release
- 28 juni 2008: second public release
- 1 juli 2008: small bugfix release
- 20 juli 2008: small bugfix release
- 24 augustus 2008: third public release
- 24 september 2008: fourth public release
- 10 november 2008: small bugfix release
*/

/*  Copyright 2008  Matthias Vandermaesen  (email : matthias@netsensei.nl) 
   This program is free software; you can redistribute it and/or modify
   it under the terms of the GNU General Public License as published by
   the Free Software Foundation; either version 2 of the License, or 
   (at your option) any later version.
   This program is distributed in the hope that it will be useful, 
   but WITHOUT ANY WARRANTY; without even the implied warranty of
   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
   GNU General Public License for more details.
   You should have received a copy of the GNU General Public License
   along with this program; if not, write to the Free Software
   Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

define( 'MOLLOM_API_VERSION', '1.0' );
define( 'MOLLOM_VERSION', '0.6.2' );
define( 'MOLLOM_USER_AGENT', '(Incutio XML-RPC) WP Mollom for Wordpress ' . MOLLOM_VERSION );
define( 'MOLLOM_TABLE', 'mollom' );

define( 'MOLLOM_ERROR'   , 1000 );
define( 'MOLLOM_REFRESH' , 1100 );
define( 'MOLLOM_REDIRECT', 1200 );

define( 'MOLLOM_ANALYSIS_HAM'     , 1);
define( 'MOLLOM_ANALYSIS_SPAM'    , 2);
define( 'MOLLOM_ANALYSIS_UNSURE'  , 3);

/** 
* mollom_activate
* activate the plugnin and install stuff upon first activation
*/
function mollom_activate() {
	global $wpdb;

	// create a new table to store mollom sessions if it doesn't exist
	$mollom_table = $wpdb->prefix . MOLLOM_TABLE;
	if($wpdb->get_var("SHOW TABLES LIKE '$mollom_table'") != $mollom_table) {
		$sql = "CREATE TABLE " . $mollom_table . " (
				`comment_ID` BIGINT( 20 ) UNSIGNED NOT NULL DEFAULT '0',
				`mollom_session_ID` VARCHAR( 40 ) NULL DEFAULT NULL,
				`mollom_had_captcha` INT ( 1 ) NOT NULL DEFAULT '0',
				UNIQUE (
					`comment_ID` ,
					`mollom_session_ID`
				)
			);";
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);
	}
	
	// if there is no version option, mollom is installed for the first time
	if (!get_option('mollom_version')) {
		//Set these variables if they don't exist
		$version = MOLLOM_VERSION;
		add_option('mollom_version', $version);
	}
	
	
	// also, let's create these variables if they don't exist
	if(!get_option('mollom_private_key'))
		add_option('mollom_private_key', '');
	if(!get_option('mollom_public_key'))		
		add_option('mollom_public_key', '');
	if(!get_option('mollom_servers'))
		add_option('mollom_servers', NULL);
	if(!get_option('mollom_ham_count'))
		add_option('mollom_ham_count', 0);
	if(!get_option('mollom_spam_count'))
		add_option('mollom_spam_count', 0);
	if(!get_option('mollom_unsure_count'))
		add_option('mollom_unsure_count', 0);
	if(!get_option('mollom_count_moderated'))
		add_option('mollom_count_moderated', 0);
	if(!get_option('mollom_site_policy'))
		add_option('mollom_site_policy', true);
	if(!get_option('mollom_dbrestore'))
		add_option('mollom_dbrestore', false);
	if(!get_option('mollom_reverseproxy'))
		add_option('mollom_reverseproxy', false);
	if(!get_option('mollom_reverseproxy_addresses'))
		add_option('mollom_reverseproxy_addresses', NULL);
	
	// if a previous installed version doesn't match with this version, mollom might need an update
	if (get_option('mollom_version') != MOLLOM_VERSION) {
		// updates of the database if the plugin  was already installed
		$version = MOLLOM_VERSION;
		update_option('mollom_version', $version);
		
		// legacy code here: 
		// 1. moving data from old to new data model if necessary (0.4 -> 0.5)
		$comments_table = $wpdb->prefix . 'comments';
			
		// only update if mollom_session_id still exists
		foreach ($wpdb->get_col("DESC $comments_table", 0) as $column ) {
			if ($column == 'mollom_session_ID') {
				$comments = $wpdb->get_results("SELECT comment_ID, mollom_session_ID FROM $comments_table WHERE mollom_session_ID IS NOT NULL");

				if ($comments) {
					$stat = true;
			
					foreach($comments as $comment) {				
						if(!$wpdb->query( $wpdb->prepare("INSERT INTO $mollom_table(comment_ID, mollom_session_ID) VALUES(%d, %s)", $comment->comment_ID, $comment->mollom_session_ID))) {
							$stat = false;
						}	
					}
			
					if($stat) {
						$wpdb->query("ALTER TABLE $wpdb->comments DROP COLUMN mollom_session_id");
					} else {
						wp_die(__('Something went wrong while moving data from comments to the new Mollom data table'));
					}
				}
			}
		}

		// 2. Add anextra column to the mollom table
		$stat = true;
		foreach ($wpdb->get_col("DESC $mollom_table", 0) as $column ) {
			if ($column == 'mollom_had_captcha') {
				$stat = false;
			}
		}

		if ($stat) {
			$wpdb->query("ALTER TABLE $mollom_table ADD mollom_had_captcha TINYINT (1) NOT NULL DEFAULT 0");
		}
		// end of legacy code
	}
}
register_activation_hook(__FILE__, 'mollom_activate');

/** 
* mollom_deactivate
* restore database to previous state upon deactivation 
*/
function mollom_deactivate() {
	global $wpdb;

	// only delete if full restore is allowed
	if(get_option('mollom_dbrestore')) {
		delete_option('mollom_private_key');
		delete_option('mollom_public_key');
		delete_option('mollom_servers');
		delete_option('mollom_version');
		delete_option('mollom_count');
		delete_option('mollom_ham_count');
		delete_option('mollom_spam_count');
		delete_option('mollom_unsure_count');
		delete_option('mollom_count_moderated');
		delete_option('mollom_reverseproxy');
		delete_option('mollom_site_policy');
		delete_option('mollom_reverseproxy_addresses');
			
		$mollom_table = $wpdb->prefix . MOLLOM_TABLE;
		
		// delete MOLLOM_TABLE
		$wpdb->query("DROP TABLE $mollom_table");
		
		delete_option('mollom_dbrestore');
	}
}
register_deactivation_hook(__FILE__, 'mollom_deactivate');

/** 
* mollom_config_page
* hook the config page in the Wordpress administration module 
*/
function mollom_config_page() {
	global $submenu;
	if ((function_exists('add_submenu_page')) && (isset($submenu['options-general.php']))) {
		add_submenu_page('options-general.php', __('Mollom'), __('Mollom'), 'manage_options', 'mollom-key-config', 'mollom_config');
	}
}
add_action('admin_menu','mollom_config_page');

/** 
* mollom_manage_page
* hook the manage page in the Wordpress administration module
*/
function mollom_manage_page() {
	global $submenu;
	if ( isset( $submenu['edit-comments.php'] ) ) {
		add_submenu_page('edit-comments.php', __('Mollom'), __('Mollom'), 'moderate_comments', 'mollommanage', 'mollom_manage');
	}
}
add_action('admin_menu','mollom_manage_page');

/**
* mollom_statistics_page
* hook the statistics page in the Wordpress administration module
*/
function mollom_statistics_page() {
	global $submenu;
	if ( isset( $submenu['edit-comments.php'] ) ) {
		add_submenu_page('edit-comments.php', __('Mollom statistics'), __('Mollom statistics'), 'manage_options', 'mollomstats', 'mollom_statistics');
	}
}
add_action('admin_menu','mollom_statistics_page');

/** 
* _mollom_set_plugincount
* Sets the count of comments asserted as spam or unsure. Local stored data is used to generate statistics
* @param boolean $action The action which was taken and for wich to set the count.
*/
function _mollom_set_plugincount($action) {
	switch ($action) {
		default:
		case "spam":
			$count = get_option('mollom_spam_count');
			$count++;
			update_option('mollom_spam_count', $count);
			break;
		case "ham":
			$count = get_option('mollom_ham_count');
			$count++;
			update_option('mollom_ham_count', $count);
			break;
		case "unsure":
			// make a spam count into an unsure count if a captcha was correctly completed!
			$count = get_option('mollom_spam_count');
			$count--;
			update_option('mollom_spam_count', $count);
			$count = get_option('mollom_unsure_count');
			$count++;
			update_option('mollom_unsure_count', $count);
			break;
		case "moderated":
			$count_moderated = get_option('mollom_count_moderated');
			$count_moderated++;
			update_option('mollom_count_moderated', $count_moderated);
			break;
	}
}

/**
* show_statistics
* Shows statistics from the Mollom servers in the Wordpress administration module (hooks on plugins.php)
*/
function mollom_statistics() {
?>
<div class="wrap">
<h2><?php _e('Mollom Statistics'); ?><h2>
<h3><?php _e('What is happening at Mollom?'); ?></h3>
<?php
	$public_key = get_option('mollom_public_key');
	if (!empty($public_key)) {
?>
<p><?php _e('These are statistics kept by the Mollom service.'); ?></p>
<!-- Flash object geneterated by mollom.com -->
<embed src="http://mollom.com/statistics.swf?key=<?php echo $public_key; ?>"
quality="high" width="600" height="430" name="Mollom" align="middle"
play="true" loop="false" allowScriptAccess="sameDomain"
type="application/x-shockwave-flash"
pluginspage="http://www.adobe.com/go/getflashplayer"></embed>

<?php
	} else {
?>
<p><?php _e('The Mollom plugin is not configured. Please go to <strong><a href="options-general.php?page=mollom-key-config">Settings &gt; Mollom</a></strong> and configure the plugin.'); ?></p>
<?php
	}
?>
<h3><?php _e('What is happening over here?'); ?></h3>
<p><?php _e('The plugin keeps some statistics of it\'s own. These are stored in the database. These values represent the number of messages that the plugin has succesfully parsed.'); ?></p>
<?php mollom_graphs(); ?>
</div>
<?php
}

/**
* _mollom_calc_statistics
* fetch all statistical data that is stored locally. 
* @param string $mode the mode of what to output: 'nominal' returns absolute numbers, 'percentage' returns percentages
* @return array An array containing the total number of parsed messages and a breakdown of that number
*/
function _mollom_calc_statistics($mode = 'nominal') {
	// Generate local statistics
	$count_nominal = array(
		'spam' => get_option('mollom_spam_count'),
		'ham' => get_option('mollom_ham_count'),
		'unsure' => get_option('mollom_unsure_count')
	);
	
	$total_count = 0;
	foreach($count_nominal as $count) {
		$total_count += $count;
	}
	
	$count_nominal['moderated'] = get_option('mollom_count_moderated');
	
	$count_percentage = array();	
	foreach($count_nominal as $key => $count) {
		if ($total_count != 0) {
			$count_percentage[$key] = round(($count / $total_count * 100), 2);
		} else {
			$count_percentage[$key] = 0;
		}
	}
	
	switch ($mode) {
		default:
		case 'nominal':
			$count_nominal['total'] = $total_count;
			return $count_nominal;
			break;
		case 'percentage':
			$count_percentage['total'] = $total_count;
			return $count_percentage;
			break;
	}
}

/**
* mollom_graphs()
* print out a nice XHTML/CSS graph with a breakdown of all the locally stored Mollom statistics
* @param boolean $css If set to false, you can override the CSS of the bargraphs with your own CSS (Usefull in a theme CSS file)
*/
function mollom_graphs($css = true) {
	$count_percentage = _mollom_calc_statistics('percentage');
	$count_nominal = _mollom_calc_statistics('nominal');
	
if ($css) {
?>
<style type="text/css">
#mollom-bar-graph {
	position: relative;
	margin: 0 0 0 15px;
}

#mollom-bar-graph ul {
	margin: 0;
	padding: 0;
	list-style: none;
}

#mollom-bar-graph ul li {
	clear: both;
}

#mollom-bar-graph ul li .graph {
	position: relative; /* IE is dumb */
	width: 400px;
	border: 1px solid #ddd;
	padding: 2px;
	height: 26px;
}

#mollom-bar-graph ul li .graph .bar {
	display: block;
	position: relative;
	background: #ddd;
	text-align: left;
	color: #333;
	height: 2em;
	line-height: 2em;
}

</style>
<?php } ?>
<div id="mollom-bar-graph">
	<p><?php _e('WP Mollom has processed a total of <strong>'); echo $count_percentage['total']; _e('</strong> messages.'); ?>
	<ul>
		<li><?php _e('Ham: '); ?><div class="graph" title="<?php echo $count_nominal['ham']; _e(' messages cleared as ham.'); ?>"><strong class="bar" style="width: <?php echo $count_percentage['ham']; ?>%;"><?php echo $count_percentage['ham']; ?>%</strong></div></li>
		<li><?php _e('Spam: '); ?><div class="graph" title="<?php echo $count_nominal['spam']; _e(' messages blocked as spam.'); ?>"><strong class="bar" style="width: <?php echo $count_percentage['spam']; ?>%;"><?php echo $count_percentage['spam']; ?>%</strong></div></li>
		<li><?php _e('Unsure: '); ?><div class="graph" title="<?php echo $count_nominal['unsure']; _e(' messages passed a captcha succesfully.'); ?>"><strong class="bar" style="width: <?php echo $count_percentage['unsure']; ?>%;"><?php echo $count_percentage['unsure']; ?>%</strong></div></li>
		<li><?php _e('Moderated: '); ?><div class="graph" title="<?php echo $count_nominal['moderated']; _e(' messages had to be manually moderated by you.'); ?>"><strong class="bar" style="width: <?php echo $count_percentage['moderated']; ?>%;"><?php echo $count_percentage['moderated']; ?>%</strong></div></li>
	</ul>
</div>
<?php
}

/** 
* mollom_config
* Handles the configuration  on your blog(keys, options,...) 
*/
function mollom_config() {	
	global $wpdb;
	
	$ms = array();
	$result = '';
	$tmp_publickey = '';
	$tmp_privatekey = '';
			
	if(isset($_POST['submit'])) {
		if (function_exists('check_admin_referer')) {
			check_admin_referer('mollom-action-configuration');
		}
			
		if (function_exists('current_user_can') && !current_user_can('manage_options')) {
			die(__('Cheatin&#8217; uh?'));
		}
		
		$privatekey = $_POST['mollom-private-key'];
		$publickey = $_POST['mollom-public-key'];
		$reverseproxy_addresses = $_POST['mollom-reverseproxy-addresses'];
			
		if (empty($privatekey)) {
			$ms[] = 'privatekeyempty';
		}
			
		if (empty($publickey)) {
			$ms[] = 'publickeyempty';
		}

		if (!empty($privatekey) && !empty($publickey)) {
			// store previous values in temporary buffer
			$tmp_privatekey = get_option('mollom_private_key');
			$tmp_publickey = get_option('mollom_public_key');
			
			update_option('mollom_private_key', $privatekey);
			update_option('mollom_public_key', $publickey);
			
			$result = mollom('mollom.verifyKey');
		}
		
		// set the policy mode for the site
		if(isset($_POST['sitepolicy'])) {
			if ($_POST['sitepolicy'] == on) {
				update_option('mollom_site_policy', true);
			}
		} else {
				update_option('mollom_site_policy', false);
		}
		
		// set restore of database (purge all mollom data)
		if(isset($_POST['mollomrestore'])) {
			if ($_POST['mollomrestore'] == on) {
				update_option('mollom_dbrestore', true);
			}
		} else {
				update_option('mollom_dbrestore', false);
		}

		// set restore of database (purge all mollom data)
		if(isset($_POST['mollomreverseproxy'])) {
			if ($_POST['mollomreverseproxy'] == on) {
				update_option('mollom_reverseproxy', true);
			}
		} else {
				update_option('mollom_reverseproxy', false);
		}
		
		// set a commaseperated list of reverse proxy addresses. Needed to determine visitor's valid ip.
		if (!empty($reverseproxy_addresses)) {
			update_option('mollom_reverseproxy_addresses', $reverseproxy_addresses);
		}
		
		
	} else {
		$privatekey = get_option('mollom_private_key');
		$publickey = get_option('mollom_public_key');
		
		if (!empty($privatekey) && !empty($publickey)) {
			$result = mollom('mollom.verifyKey');
		} else {
			if (empty($privatekey)) {
				$ms[] = 'privatekeyempty';
			}
				
			if (empty($publickey)) {
				$ms[] = 'publickeyempty';
			}
		}
	}
	
	// evaluate the result, if it is a WP_Error object or a bool
	if (function_exists('is_wp_error') && is_wp_error($result)) {
		if (($result->get_error_code()) == MOLLOM_ERROR) {
			$ms[] = 'mollomerror';
			// reset the wordpress variables to whatever (empty or
			// previous value) is in the buffer if mollom returned error code 1000
			update_option('mollom_private_key', $tmp_privatekey);
			update_option('mollom_public_key', $tmp_publickey);
		}
		
		if (($result->get_error_code()) != MOLLOM_ERROR) {	
			 $ms[] = 'networkerror';
		}
		
		$errormsg = $result->get_error_message();
	}

	if (is_bool($result)) {
		$ms[] = 'correctkey';
	}
		
	$messages = array('privatekeyempty' => array('color' => 'aa0', 'text' => __('Your private key is empty.')),
				'publickeyempty' => array('color' => 'aa0', 'text' => __('Your public key is empty.')),
				'wrongkey' => array('color' => 'd22', 'text' => __('The key you provided is wrong.')),
				'mollomerror' => array('color' => 'd22', 'text' => __('Mollom error: ' . $errormsg)),
				'networkerror' => array('color' =>'d22', 'text' => __('Network error: ' . $errormsg)),
				'correctkey' => array('color' => '2d2', 'text' => __('Your keys are valid.')));
	?>	
<div class="wrap">
<h2><?php _e('Mollom Configuration'); ?></h2>
<div class="narrow">
<?php if ( !empty($_POST ) ) : ?>
<div id="message" class="updated fade"><p><strong><?php _e('Options saved.') ?></strong></p></div>
<?php endif; ?>
<form action="" method="post" id="mollom_configuration" style="margin: auto; width: 420px;">
	<?php
		if ( !function_exists('wp_nonce_field') ) {
			function mollom_nonce_field($action = -1) { return; }
			$mollom_nonce = -1;
		} else {
			function mollom_nonce_field($action = -1) { return wp_nonce_field($action); }
			$mollom_nonce = 'mollom-action-configuration';
		}

		mollom_nonce_field($mollom_nonce);
	?>
	<p><?php _e('<a href=\"http://mollom.com\" title=\"Mollom\">Mollom</a> is a web service that helps you identify content quality and, more importantly,
	helps you stop comment and contact form spam. When moderation becomes easier, you can spend
	more time and energy to interact with your web community.'); ?></p>	
	<p><?php _e('<a href="http://mollom.com/user/register">Register</a> with Mollom to get your keys.'); ?></p>
	<?php if(!empty($ms)) {
		foreach ( $ms as $m ) : ?>
	<p style="padding: .5em; background-color: #<?php echo $messages[$m]['color']; ?>; color: #fff; font-weight: bold;"><?php echo $messages[$m]['text']; ?></p>
	<?php endforeach; } ?>
	<h3><label><?php _e('Public key'); ?></label></h3>
	<p><input type="text" size="35" maxlength="32" name="mollom-public-key" id="mollom-public-key" value="<?php echo get_option('mollom_public_key'); ?>" /></p>
	<h3><label><?php _e('Private key'); ?></label></h3>
	<p><input type="text" size="35" maxlength="32" name="mollom-private-key" id="mollom-private-key" value="<?php echo get_option('mollom_private_key'); ?>" /></p>
	<h3><label><?php _e('Policy mode'); ?></label></h3>
	<p><input type="checkbox" name="sitepolicy" <?php if (get_option('mollom_site_policy')) echo 'value = "on" checked'; ?> />&nbsp;&nbsp;<?php _e('If Mollom services are down, all comments are blocked by default.'); ?></p>
	<h3><label><?php _e('Restore'); ?></label></h3>
	<p><input type="checkbox" name="mollomrestore" <?php if (get_option('mollom_dbrestore')) echo 'value = "on" checked'; ?> />&nbsp;&nbsp;<?php _e('Restore the database (purge all Mollom data) upon deactivation of the plugin.'); ?></p>
	<h3><label><?php _e('Reverse proxy'); ?></label></h3>
	<p><?php _e('Check this if your host is running a reverse proxy service (squid,...) and enter the ip address(es) of the reverse proxy your host runs as a commaseparated list.'); ?></p>
	<p><?php _e('When in doubt, just leave this off.'); ?></p>
	<p><?php _e('enable: '); ?><input type="checkbox" name="mollomreverseproxy" <?php if (get_option('mollom_reverseproxy')) echo 'value = "checked"'; ?> />&nbsp;-&nbsp;
	<input type="text" size="35" maxlength="255" name="mollom-reverseproxy-addresses" id="mollom-reverseproxy-addresses" value="<?php echo get_option('mollom_reverseproxy_addresses'); ?>" /></p>
	<p class="submit"><input type="submit" value="<?php _e('Update options &raquo;'); ?>" id="submit" name="submit"/></p>
</form>
</div>
</div>
	
<?php
}

/** 
* _mollom_send_feedback
* Send feedback about a comment to mollom and purge the comment
* @param string $action the action to perform. Valid values: spam, profanity, unwanted lowquality
* @param integer $comment_ID the id of the comment on which to perform the action
* @param array Return an empty array on success. If failed: array contains error messages
*/
function _mollom_send_feedback($action, $comment_ID) {
	global $wpdb;
	$mollom_table = $wpdb->prefix . MOLLOM_TABLE;
	$ms = array();
	
	$mollom_sessionid = $wpdb->get_var( $wpdb->prepare("SELECT mollom_session_ID FROM $mollom_table WHERE comment_ID = %d", $comment_ID) );
	
	switch($action) {
		case $action == "spam":
		case $action == "profanity":
		case $action == "unwanted":
		case $action == "lowquality":
			switch ($action) {
				case $action == "spam":
					$data = array('feedback' => 'spam', 'session_id' => $mollom_sessionid);
					break;
				case $action == "profanity":
					$data = array('feedback' => 'profanity', 'session_id' => $mollom_sessionid);
					break;
				case $action == "unwanted":
					$data = array('feedback' => 'unwanted', 'session_id' => $mollom_sessionid);
					break;
				case $action == "lowquality";
					$data = array('feedback' => 'low-quality', 'session_id' => $mollom_sessionid);
					break;
			}
			
			$result = mollom('mollom.sendFeedback', $data);
				
			if($result) {
				if($wpdb->query( $wpdb->prepare("DELETE FROM $wpdb->comments, $mollom_table USING $wpdb->comments INNER JOIN $mollom_table USING(comment_ID) WHERE $wpdb->comments.comment_ID = %d", $comment_ID))) {
					// update the  statistics for manual moderation
					_mollom_set_plugincount("moderated");
					$ms[] = 'allsuccess'; // return all success on successfull feedback
				} else {
					$ms[] = 'feedbacksuccess';
				}
			}		
			else if (function_exists( 'is_wp_error' ) && is_wp_error( $result )) {
				$ms[] = 'mollomerror';
			}
			else {
				$ms[] = 'networkfail';
			}
			
			break;
		case $action == "approve":
			if (wp_set_comment_status($comment_ID, 'approve')) {
				$ms[] = 'approved';
			} else {
				$ms[] = 'approvefail';
			}
			break;
		case $action == "unapprove":
			if (wp_set_comment_status($comment_ID, 'hold')) {
				$ms[] = 'unapproved';
			} else {
				$ms[] = 'approvefail';
			}
			break;
		default:
			$ms[] = 'invalidaction';
			return $ms;
			break;
	}
	
	return $ms; // return the result
}

/** 
* mollom_manage
* Moderate messages that have a stored Mollom session ID 
*/
function mollom_manage() {
	if (function_exists('current_user_can') && !current_user_can('manage_options')) {
		die(__('Cheatin&#8217; uh?'));
	}
		
	global $wpdb;
	$feedback = array();
	$broken_comment = "";
	
		
	// moderation of a single item
	if ($_GET['maction'] && !$_POST['mollom-delete-comments']) {
		if (function_exists('check_admin_referer')) {
			check_admin_referer('mollom-moderate-comment');
		}

		$mollom_private_key = get_option('mollom_private_key');
		$mollom_public_key = get_option('mollom_public_key');
		
		$comment_ID = $_GET['c'];
		
		if (empty($mollom_private_key) || empty($mollom_public_key)) {
			$feedback[$comment_ID] = array('emptykeys');
		} else {
			$action = $_GET['maction'];				
			$feedback[$comment_ID] = _mollom_send_feedback($action, $comment_ID);
		}
	}
	
	// moderation of multiple items (bulk)
	if ($_POST['mollom-delete-comments']) {
		if (function_exists('check_admin_referer')) {
			check_admin_referer('mollom-bulk-moderation');
		}
		
		$mollom_private_key = get_option('mollom_private_key');
		$mollom_public_key = get_option('mollom_public_key');
		
		if (empty($mollom_private_key) || empty($mollom_public_key)) {
			$feedback[] = array('emptykeys');	
		} else {
			$multiple_failed = false;
			foreach($_POST["mollom-delete-comments"] as $comment_ID) {			
				$result = _mollom_send_feedback($action, $comment_ID);
				switch ($result[0]) {
					case 'allsuccess':
					case 'unapprove':
					case 'approve':
						$multipe_failed = false;
					default:
						$multiple_failed = true;
				}
				$feedback[$comment_ID] = $result;
			}
		}
	}
	
	// Generate local statistics
	$count_nominal = _mollom_calc_statistics('nominal');
	$count_percentage = _mollom_calc_statistics('percentage');
	
	// from here on: generate messages and overview page
	$messages = array('allsuccess' => array('color' => 'd2f2d7', 'text' => __('Feedback sent to Mollom. The comment was successfully deleted.')),
					  'approved' => array('color' => 'd2f2d7', 'text' => __('You flagged the comment as approved.')),
					  'unapproved' => array('color' => 'd2f2d7', 'text' => __('You flagged the comment as unapproved.')),
					  'feedbacksuccess' => array('color' => 'f6d5cb', 'text' => __('Feedback sent to Mollom but the comment could not be deleted.')),
					  'networkfail' => array('color' => 'f6d5cb', 'text' => __('Mollom was unreachable. Maybe the service is down or there is a network disruption.')),
					  'emptykeys' => array('color' => 'f6d5cb', 'text' => __('Could not perform action because the Mollom plugin was not configured. Please configure it first.')),
					  'mollomerror' => array('color' => 'f6d5cb', 'text' => __('Mollom could not process your request.')),
					  'approvefail' => array('color' => 'f6d5cb', 'text' => __('Wordpress could not (un)approve your comment.')),
					  'invalidaction' => array('color' => 'f6d5cb', 'text' => __('Invalid mollom feedback action.'))); 

	// pagination code
	$show_next = true;

	$mollom_table = $wpdb->prefix . MOLLOM_TABLE;
	$count = $wpdb->get_var("SELECT COUNT(mollom_session_ID) FROM $mollom_table");
	
	if ($count > 0) {
		if ($_GET['apage']) {
			$apage = $_GET['apage'];
		} else {
			$apage = 0;
		}
		
		if ($apage == 0) {
			$start = $apage;
			$limit = $apage + 25;
		} else {
			$start = ($apage * 25) + 1;
			$limit = $start + 25;
		}
		
		$prevpage = $apage - 1;
		$nextpage = $apage + 1;
				
		$comments = $wpdb->get_results( $wpdb->prepare("SELECT comments.comment_ID, mollom.mollom_had_captcha FROM $wpdb->comments comments, $mollom_table mollom WHERE mollom.comment_ID = comments.comment_ID ORDER BY comment_date DESC LIMIT %d, %d", $start, $limit) );

		if ($limit >= $count) {
			$show_next = false;
		}
	} else {
		$comments = false;
	}

?>
<script type="text/javascript">
//<![CDATA[
function checkAll(form) {
	for (i = 0, n = form.elements.length; i < n; i++) {
		if(form.elements[i].type == "checkbox" && !(form.elements[i].getAttribute('onclick', 2))) {
 	  	if(form.elements[i].checked == true)
 	    	form.elements[i].checked = false;
 	    else
	    	form.elements[i].checked = true;
 	  }
 	}
}

jQuery(document).ready(function() {
	jQuery('#mollom-messages').hide();
	jQuery('#mollom-statistics').hide();
	
	jQuery('a#mollom-toggle').click(function() {
		jQuery('#mollom-messages').slideToggle('slow');
		return false;
	});
	
	jQuery('a#mollom-stat-toggle').click(function() {
		jQuery('#mollom-statistics').slideToggle('slow');
		return false;
	});
});
//]]>
</script>
<style type="text/css">
.mollom-comment-list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.mollom-comment-list a {
	text-decoration: none;
}

.mollom-comment-list li {
	border-bottom: 1px solid #ddd;
	margin: 0 0 15px 0;
	padding: 0 0 33px 0;
	clear: right;
}

.mollom-comment-head {
	background: #ddd;
	font-size: 1.0em;
	padding: 3px 0;
}

.mollom-comment-head a {
	color: #222;
	text-decoration: none;
	border-bottom: 1px dotted #000;
}

.mollom-comment-metadata {
	font-size: 0.85em;	
	float: left;
}

.mollom-action-links {	
	font-size: 0.85em;	
	float: right;
}

.mollom-no-comments {
	font-size: 1.1em;
	background: #e4f2fd;
	font-weight: strong;
	border: 1px solid #ddd;
}

.mollom-report {
}

.mollom-report p, .mollom-report a, {
	margin: 5px;
	padding: 0;
}

.mollom-legend-clean {
	background: #ddd;
	border: 1px solid #ccc;
	padding: 3px;
}

.mollom-legend-captcha {
	background: #abc;
	border: 1px solid #bcd;
	padding: 3px;
}

.mollom-legend-unapproved {
	background: #fdf8e4;
	padding: 3px;
}

</style>
<div class="wrap">
<h2><?php _e('Mollom Manage'); ?></h2>
<p><?php _e('Mollom stops spam before it even reaches your database.'); ?></p>
<p><?php _e('This is an overview of all the Mollom approved comments posted on your website. You can moderate them here. Through moderating these messages, Mollom learns from it\'s mistakes. Moderation of messages that, in your view, should have been blocked, is encouraged.'); ?></p>
<p><?php _e('Take a look at <a href="#" id="mollom-stat-toggle">some statistics</a>.')?></p>

<div id="mollom-statistics">
<?php mollom_graphs(); ?>
</div>

<div class="mollom-report">
<?php 

if(!empty($feedback)) {
	if (count($feedback) == 1) {
		$comment = current($feedback);
		$comment_ID = key($feedback);
		foreach ($comment as $message) :
?>
<p style="padding: .5em; background-color: #<?php echo $messages[$message]['color']; ?>; color: #555; font-weight: bold;"><?php echo 'Comment #' . $comment_ID . ' : ' . $messages[$message]['text']; ?></p>	
<?php
		endforeach;
	} else {
		if (!multiple_failed) { ?>
	<p><strong><?php _e('Something went wrong while processing the feedback. <a href="#" id="mollom-toggle">Click to display a detailed report</a>.'); ?></strong></p>
<?php 	} else { ?>
	<p><strong><?php _e('All comments were succesfully moderated. <a href="#" id="mollom-toggle">Click to display a detailed report</a>.'); ?></strong></p>
<?php 	} ?>

<div id="mollom-messages">
<?php
	foreach ( $feedback as $comment_ID => $comment ) :
		foreach( $comment as $message) : 
?>
<p style="padding: .5em; background-color: #<?php echo $messages[$message]['color']; ?>; color: #fff; font-weight: bold;"><?php echo 'Comment #' . $comment_ID . ' : ' . $messages[$message]['text']; ?></p>
<?php 
		endforeach;
	endforeach;
?>
</div>

<?php  } 
} ?>
</div>

<?php
	if (!$comments) { ?>

<p class="mollom-no-comments"><?php _e('There are no comments that can be moderated through Mollom.'); ?></p>

<?php } else { ?>

<form id="comments-form" action="" method="post">
<?php
	if ( !function_exists('wp_nonce_field') ) {
		function mollom_nonce_field($action = -1) { return; }
		$mollom_nonce = -1;
	} else {
		function mollom_nonce_field($action = -1) { return wp_nonce_field($action); }
		$mollom_nonce = 'mollom-bulk-moderation';
	}
	mollom_nonce_field($mollom_nonce);
?>
<p><small><em><?php _e("&raquo;&nbsp;Legend: "); ?></em><span class="mollom-legend-clean"><?php _e("Clean"); ?></span>&nbsp;<span class="mollom-legend-captcha"><?php _e("Captcha"); ?></span>
&nbsp;<span class="mollom-legend-unapproved">Unapproved</span></small></p>
<div class="tablenav">
<div class="alignleft">
<input type="checkbox" onclick="checkAll(document.getElementById('comments-form'));" />&nbsp;All
&nbsp;&nbsp;<input type="submit" name="maction" value="spam" class="button-secondary" />
<input type="submit" name="maction" value="profanity" class="button-secondary" />
<input type="submit" name="maction" value="low quality" class="button-secondary" />
<input type="submit" name="maction" value="unwanted" class="button-secondary" />
</div>
<div class="tablenav-pages">
<a href="edit-comments.php?page=mollommanage">Start</a>
<?php
	if($apage != 0) { ?>
	<a href="edit-comments.php?page=mollommanage&amp;apage=<?php echo $prevpage; ?>"><?php _e('&laquo;Previous'); ?></a>
<?php }
	if($show_next) { ?>
	<a href="edit-comments.php?page=mollommanage&amp;apage=<?php echo $nextpage; ?>"><?php _e('Next&raquo;'); ?></a>
<?php } ?>
</div>
</div>
<ul class="mollom-comment-list">
	<?php foreach ($comments as $_comment) {
		global $comment, $post;
		$comment = get_comment($_comment->comment_ID);
	
		$spam = clean_url(wp_nonce_url('edit-comments.php?page=mollommanage&c=' . $comment->comment_ID . '&maction=spam', 'mollom-moderate-comment'));
		$profanity = clean_url(wp_nonce_url('edit-comments.php?page=mollommanage&c=' . $comment->comment_ID . '&maction=profanity', 'mollom-moderate-comment'));
		$lowquality = clean_url(wp_nonce_url('edit-comments.php?page=mollommanage&c=' . $comment->comment_ID . '&maction=lowquality', 'mollom-moderate-comment'));
		$unwanted = clean_url(wp_nonce_url('edit-comments.php?page=mollommanage&c=' . $comment->comment_ID . '&maction=unwanted', 'mollom-moderate-comment')); 
		$approve = clean_url(wp_nonce_url('edit-comments.php?page=mollommanage&c=' . $comment->comment_ID . '&maction=approve', 'mollom-moderate-comment')); 
		$unapprove = clean_url(wp_nonce_url('edit-comments.php?page=mollommanage&c=' . $comment->comment_ID . '&maction=unapprove', 'mollom-moderate-comment')); 
		
		(strlen($comment->comment_author_url) > 32) ? $comment_url = substr($comment->comment_author_url, 0, 32) . '...' : $comment_url = $comment->comment_author_url;
		($_comment->mollom_had_captcha == 1) ? $header_style = "#abc" : $header_style = "#ddd";
		($comment->comment_approved != 1) ? $item_style = 'style="background:#fdf8e4;"' : $item_style = "";
		
		$post = get_post($comment->comment_post_ID);
		$post_link = get_comment_link();
		
	?>

	<li <?php echo $item_style; ?>>
		<p class="mollom-comment-head" style="background:<?php echo $header_style; ?>;"><input type="checkbox" name="mollom-delete-comments[]" value="<?php echo $comment->comment_ID; ?>" />&nbsp;&nbsp<strong><?php echo $comment->comment_author; ?></strong> on <a href="<?php echo $post_link; ?>"><?php echo $post->post_title; ?></a></p>
		<p><strong><?php echo $comment->comment_title; ?></strong></p>
		<p><?php echo $comment->comment_content; ?></p>
		<p class="mollom-comment-metadata">
		<?php if ($comment_url != "") { ?>
			<a href="<?php echo $comment_url; ?>"><?php echo $comment_url; ?></a> |
		<?php } ?>
		<?php echo $comment->comment_date; ?> |
		<?php echo $comment->comment_author_IP; ?></p>
		<p class="mollom-action-links"><a href="<?php echo $spam; ?>">spam</a> | <a href="<?php echo $profanity; ?>">profanity</a>
		| <a href="<?php echo $lowquality; ?>">low-quality</a> | <a href="<?php echo $unwanted; ?>">unwanted</a>
		| <a href="<?php echo $approve; ?>">approve</a> | <a href="<?php echo $unapprove; ?>">unapprove</a>
		</p>
	</li>
	<?php } ?>
</ul>
</form>
<?php } ?>

</div>
<?php
}

/**
* mollom_manage_wp_queue
* passes messages as spam to Mollom when moderated through the default WP 'comments' panel
* @param integer $comment_ID the id of the comment that is being moderated
* @param string $comment_status the status that was passed by the user through the default comments panel
* @return integer The id of the coment is passed back to the main program flow
*/
function mollom_manage_wp_queue($comment_ID) {
	$comment = get_commentdata($comment_ID, 1, true);
	$post_id = $comment['comment_post_ID'];	
	
	if ($comment['comment_approved'] == 'spam') {
		_mollom_send_feedback('spam', $comment_ID);
	}
	
	return $post_id;
}
add_action('wp_set_comment_status', 'mollom_manage_wp_queue');

/**
* mollom_moderate_comment
* Show moderation options in your theme if you're logged in and have permissions. Must be within the comment loop.
* @param string The moderation links to show as a string
*/
function mollom_moderate_comment($comment_ID) {
	if (function_exists('current_user_can') && current_user_can('manage_options')) {
		$spam = clean_url(wp_nonce_url('edit-comments.php?page=mollommanage&amp;c=' . $comment_ID . '&maction=spam', 'mollom-moderate-comment'));
		$profanity = clean_url(wp_nonce_url('edit-comments.php?page=mollommanage&amp;c=' . $ccomment_ID . '&maction=profanity', 'mollom-moderate-comment'));
		$lowquality = clean_url(wp_nonce_url('edit-comments.php?page=mollommanage&amp;c=' . $comment_ID . '&maction=lowquality', 'mollom-moderate-comment'));
		$unwanted = clean_url(wp_nonce_url('edit-comments.php?page=mollommanage&amp;c=' . $comment_ID . '&maction=unwanted', 'mollom-moderate-comment'));
		$approved = clean_url(wp_nonce_url('edit-comments.php?page=mollommanage&amp;c=' . $comment_ID . '&maction=approve', 'mollom-moderate-comment'));		
		$unapproved = clean_url(wp_nonce_url('edit-comments.php?page=mollommanage&amp;c=' . $comment_ID . '&maction=unapprove', 'mollom-moderate-comment'));
		
		$str = 'Moderate: <a href="wp-admin/' . $spam . '" title="moderate as spam">spam</a> | ' .
		    '<a href="wp-admin/' . $profanity . '" title="moderate as profanity">profanity</a> | ' .
			'<a href="wp-admin/' . $lowquality . '" title="moderate as low quality">low quality</a> | ' .
			'<a href="wp-admin/' . $unwanted . '" title="moderate as unwanted">unwanted</a> | ' .
			'<a href="wp-admin/' . $approved . '" title="moderate as unwanted">approved</a> | ' . 
			'<a href="wp-admin/' . $unapproved . '" title="moderate as unwanted">unapproved</a>';
		
		return $str;
	}
}

/** 
* mollom_check_comment
* Check if a comment is spam or ham
* @param array $comment the comment passed by the preprocess_comment hook
* @return array The comment passed by the preprocess_comment hook
*/
function mollom_check_comment($comment) {
	global $mollom_sessionid;
	
	if($comment['comment_type'] == 'trackback') {
		return $comment;
	}
	
	$private_key = get_option('mollom_private_key');
	$public_key = get_option('mollom_public_key');
	
	// check if the client is configured all toghether
	if ((empty($private_key)) || (empty($public_key))) {
		if (get_option('mollom_site_policy')) {
			wp_die(__('You haven\'t configured Mollom yet! Per the website\'s policy. We could not process your comment.'));
		}
	}
	
	$user = wp_get_current_user();
	
	// only if the user is registered, there is no active session
	if (!$_POST['mollom_sessionid'] && !$user->ID) {
		$mollom_comment_data = array('post_body' => $comment['comment_content'],
									 'author_name' => $comment['comment_author'],
									 'author_url' => $comment['comment_author_url'],
									 'author_mail' => $comment['comment_author_email'],
									 'author_ip' => _mollom_author_ip());
				
		$result = mollom('mollom.checkContent', $mollom_comment_data);		

		// quit if an error was thrown else return to WP Comment flow
		if (function_exists('is_wp_error') && is_wp_error($result)) {
			if(get_option('mollom_site_policy')) {
				wp_die($result, __('Something went wrong!'));
			} else {
				return $comment;
			}
		}
		
		$mollom_sessionid = $result['session_id'];
		
		if($result['spam'] == MOLLOM_ANALYSIS_HAM) {
			// let the comment pass			
			_mollom_set_plugincount("ham");
			add_action('comment_post', '_mollom_save_session', 1);
			return $comment;
		}

		elseif ($result['spam'] == MOLLOM_ANALYSIS_SPAM) {
			// kill the process here because of spam detection and set the count of blocked messages
			_mollom_set_plugincount("spam");	
			wp_die(__('Your comment has been marked as spam or unwanted by Mollom. It could not be accepted.'));
		}
	
		elseif($result['spam'] == MOLLOM_ANALYSIS_UNSURE) {
			// show a CAPTCHA and and set the count of blocked messages
			_mollom_set_plugincount("spam");
			$mollom_comment = array('comment_post_ID' => $comment['comment_post_ID'],
									'mollom_sessionid' => $result['session_id'],
									'author' => $comment['comment_author'],
									'url' => $comment['comment_author_url'],
									'email' => $comment['comment_author_email'],
									'comment' => $comment['comment_content']);

			mollom_show_captcha('', $mollom_comment);
			die();
		}
		
		elseif (function_exists('is_wp_error') && is_wp_error($result)) {
			if(get_option('mollom_site_policy')) {
				wp_die($result, __('Something went wrong...'));
			} else {
				return $comment;
			}
		}
	}
	
	return $comment;
}
add_action('preprocess_comment', 'mollom_check_comment');

/** 
* mollom_check_trackback
* check if a trackback is ham or spam 
* @param array $comment the comment passed by the preprocess_comment hook
* @return array The comment passed by the preprocess_comment hook
*/
function mollom_check_trackback($comment) {
	if($comment['comment_type'] != 'trackback') {
		return $comment;
	}
	
	global $mollom_sessionid;
	
	$private_key = get_option('mollom_private_key');
	$public_key = get_option('mollom_public_key');
	
	// check if the client is configured
	if ((empty($private_key)) || (empty($public_key))) {
		if (get_option('mollom_site_policy')) {
			wp_die(__('You haven\'t configured Mollom yet! Per the website\'s policy. We could not process your comment.'));
		}
	}
	
	$mollom_comment_data = array('post_body' => $comment['comment_content'],
								'author_name' => $comment['comment_author'],
								'author_url' => $comment['comment_author_url'],
								'author_mail' => $comment['comment_author_email'],
								'author_ip' => _mollom_author_ip());
				
	$result = mollom('mollom.checkContent', $mollom_comment_data);

	// quit if an error was thrown else return to WP Comment flow
	if (function_exists('is_wp_error') && is_wp_error($result)) {
		if(get_option('mollom_site_policy')) {
			wp_die($result, __('Something went wrong!'));
		} else {
			return $comment;
		}
	}

	$mollom_sessionid = $result['session_id'];
		
	if($result['spam'] == MOLLOM_ANALYSIS_HAM) {
		// let the comment pass
		_mollom_set_plugincount("ham");
		add_action('comment_post', '_mollom_save_session', 1); // save session!!
		return $comment;
	}

	elseif ($result['spam'] == MOLLOM_ANALYSIS_SPAM) {
		// kill the process here because of spam detection
		_mollom_set_plugincount("spam");
		_mollom_trackback_error('spam', __('Mollom recognized your trackback as spam.'));
	}
	
	elseif($result['spam'] == MOLLOM_ANALYSIS_UNSURE) {
		// kill the process here because of unsure detection (Trackbacks don't get a CAPTCHA)
		_mollom_set_plugincount("spam");
		_mollom_trackback_error('unsure', __('Mollom could not recognize your trackback as spam or ham.'));
	}
		
	elseif (function_exists('is_wp_error') && is_wp_error($result)) {
		if(get_option('mollom_site_policy')) {
			$error_code = $result->get_error_code();
			_mollom_trackback_error($error_code, $result->get_error_message($error_code));
		} else {
			return $comment;
		}
	}	
}
add_action('preprocess_comment', 'mollom_check_trackback');

/** 
* _mollom_trackback_error
* return an XML answer when mollom fails or denies access to trackback
* @param string $code The error code to be outputted
* @param string $error_message The error message to be outputted
*/
function _mollom_trackback_error($code = '1', $error_message = '') {
	header('Content-Type: text/xml; charset=' . get_option('blog_charset'));
	echo '<?xml version="1.0" encoding="' . get_option('blog_charset') . '" ?>\n';
	echo "<response>\n";
	echo "<error>$code</error>\n";
	echo "<message>$error_message</message>\n";
	echo "</response>";
	die();
}

/**
* mollom_check_captcha 
* Check the answer of the CAPTCHA presented by Mollom. Called through the pre_process_hook as a callback.
* @param array $comment the comment array passed by the pre_process hook 
* @return array The comment array passed by the pre_process hook 
*/
function mollom_check_captcha($comment) {
	// strip redundant slashes
	$comment['comment_content'] = stripslashes($comment['comment_content']);
	
	if ($_POST['mollom_sessionid']) {
		global $wpdb;
	
		$mollom_sessionid = $_POST['mollom_sessionid'];
		$solution = $_POST['mollom_solution'];
		$mollom_image_session = $_POST['mollom_image_session'];
		$mollom_audio_session = $_POST['mollom_audio_session'];

		if ($solution == '') {
			$message = 'You didn\'t fill out all the required fields, please try again';
			
			$mollom_comment['mollom_sessionid'] = $mollom_sessionid;
			$mollom_comment['comment_post_ID'] = $comment['comment_post_ID'];
			$mollom_comment['author'] = $comment['comment_author'];
			$mollom_comment['url'] = $comment['comment_author_url'];
			$mollom_comment['email'] = $comment['comment_author_email'];
			$mollom_comment['comment'] = $comment['comment_content'];

			mollom_show_captcha($message, $mollom_comment);
			die();
		}
		
		// check captcha sessions and solution. If the image gives false, then try the audio session.
		// Normally the mollom session id, the image session id and the audio session id are the same though no guarantees can be made
		$data = array('session_id' => $mollom_image_session, 'solution' => $solution);
		if (!($result = mollom('mollom.checkCaptcha', $data))) {
			$data = array('session_id' => $mollom_audio_session, 'solution' => $solution);
			$result = mollom('mollom.checkCaptcha', $data);
		}
		
		// quit if an error was thrown else return to WP Comment flow
		if (function_exists('is_wp_error') && is_wp_error($result)) {
			if(get_option('mollom_site_policy')) {
				wp_die($result, "Something went wrong!");
			} else {
				return $comment;
			}
		}
		
		// if correct
		else if ($result) {
			global $mollom_sessionid;
			$mollom_sessionid = $_POST['mollom_sessionid'];
			$comment['comment_content'] = htmlspecialchars_decode($comment['comment_content']);
			add_action('comment_post', '_mollom_save_session', 1);
			add_action('comment_post', '_mollom_save_had_captcha', 1);
			_mollom_set_plugincount("unsure");
			return $comment;
		}
		
		// if incorrect
		else if (!$result) {
			// let's be forgiving and provide with a new CAPTCHA
			$message = 'The solution you submitted to the CAPTCHA was incorrect. Please try again...';
			$mollom_comment['mollom_sessionid'] = $mollom_sessionid;
			$mollom_comment['comment_post_ID'] = $comment['comment_post_ID'];
			$mollom_comment['author'] = $comment['comment_author'];
			$mollom_comment['url'] = $comment['comment_author_url'];
			$mollom_comment['email'] = $comment['comment_author_email'];
			$mollom_comment['comment'] = $comment['comment_content'];
			mollom_show_captcha($message, $mollom_comment);
			die();
		}
	}

	return $comment;
}
add_action('preprocess_comment','mollom_check_captcha');

/** 
* _mollom_show_captcha
* generate and show the captcha form 
* @param string $message an status or error message that needs to be shown to the user
* @param array $mollom_comment the array with the comment data
*/
function mollom_show_captcha($message = '', $mollom_comment = array()) {
	$data = array('author_ip' => _mollom_author_ip(), 'session_id' => $mollom_comment['mollom_sessionid']);

	$result = mollom('mollom.getAudioCaptcha', $data);	
	if (function_exists('is_wp_error') && is_wp_error($result)) {
		if(get_option('mollom_site_policy')) {
			wp_die($result, __('Something went wrong...'));
		}
	}
	
	$mollom_audio_session = $result['session_id'];
	$mollom_audio_captcha = $result['url'];

	$result = mollom('mollom.getImageCaptcha', $data);	
	if (function_exists('is_wp_error') && is_wp_error($result)) {
		if(get_option('mollom_site_policy')) {
			wp_die($result, __('Something went wrong...'));
		}
	}

	$mollom_image_session = $result['session_id'];
	$mollom_image_captcha = $result['url'];
?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" dir="ltr">
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
	<title>WordPress &raquo; Mollom CAPTCHA test</title>
	<link rel="stylesheet" href="<?php get_bloginfo('siteurl'); ?>/wp-admin/css/install.css" type="text/css" />
	<style media="screen" type="text/css">
		html { background: #f1f1f1; }
		
		body {
			background: #fff;
			color: #333;
			font-family: "Lucida Grande", "Lucida Sans Unicode", Tahoma, Verdana, sans-serif;
			margin: 2em auto 0 auto;
			width: 700px;
			padding: 1em 2em;
			-webkit-border-radius: 12px;
			font-size: 62.5%;
		}

		a { color: #2583ad; text-decoration: none; }

		a:hover { color: #d54e21; }

		h2 { font-size: 16px; }

		p {
			padding-bottom: 2px;
			font-size: 1.3em;
			line-height: 1.8em;
		}	

		h1 {
			border-bottom: 1px solid #dadada;
			clear: both;
			color: #666666;
			font: 24px Georgia, "Times New Roman", Times, serif;
			margin: 5px 0 0 -4px;
			padding: 0;
			padding-bottom: 7px;
		}

		#error-page {
			margin-top: 50px;
		}

		#error-page p {
			font-size: 14px;
			line-height: 1.6em;
		}
		
		#error-page p.message {
			border: 1px solid #d91f1f;
			background: #f88b8b;
			padding: 0 0 0 5px;
		}
      </style>
</head>
<body id="error-page">
<h1>Mollom CAPTCHA</h1>
<p><?php _e('This blog is protected by <a href="http://mollom.com">Mollom</a> against spam. Mollom is unsure wether your comment was spam or not. Please complete this form by typing the text in the image in the input box. Additionally, you can also listen to a spoken version of the text.'); ?></p>
<?php if ($message != '') { ?>
	<p class="message"><?php _e($message); ?></p>
<?php } ?>
<form action="wp-comments-post.php" method="post">
	<p><label><strong><?php _e('Image Captcha'); ?></strong></label></p>
	<p><img src="<?php echo $mollom_image_captcha; ?>" alt="mollom captcha" title="mollom captcha" /></p>
	<p><label><strong><?php _e('Audio Captcha'); ?></strong></label></p>	
	<object type="audio/mpeg" data="<?php echo $mollom_audio_captcha; ?>" width="50" height="16">
      <param name="autoplay" value="false" />
      <param name="controller" value="true" />
    </object>
	<p><small><a href="<?php echo $mollom_audio_captcha; ?>" title="mollom captcha">Download Audio Captcha</a></small></p>
	<p><input type="text" length="15" maxlength="15" name="mollom_solution" /></p>
	<input type="hidden" value="<?php echo $mollom_audio_session; ?>" name="mollom_audio_session" />
	<input type="hidden" value="<?php echo $mollom_image_session; ?>" name="mollom_image_session" />
	<input type="hidden" value="<?php echo $mollom_comment['mollom_sessionid']; ?>" name="mollom_sessionid" />
	<input type="hidden" value="<?php echo $mollom_comment['comment_post_ID']; ?>" name="comment_post_ID" />
	<input type="hidden" value="<?php echo $mollom_comment['author']; ?>" name="author" />
	<input type="hidden" value="<?php echo $mollom_comment['url']; ?>" name="url" />
	<input type="hidden" value="<?php echo $mollom_comment['email']; ?>" name="email" />
	<input type="hidden" value="<?php echo htmlentities($mollom_comment['comment']); ?>" name="comment" /></p>
	<p><input type="submit" value="Submit" class="submit" /></p>
</form>
</body>
</html>

<?php
}

/**
* _mollom_save_session
* save the session ID for this comment in the database in MOLLOM_TABLE
* @param integer $comment_ID the id of the comment
* @return integer The id of the comment
*/
function _mollom_save_session($comment_ID) {
	global $wpdb, $mollom_sessionid;
	
	// set the mollom session id for later use when moderation is needed
	$mollom_table = $wpdb->prefix . MOLLOM_TABLE;
	$result = $wpdb->query( $wpdb->prepare("INSERT INTO $mollom_table (comment_ID, mollom_session_ID) VALUES(%d, %s)", $comment_ID, $mollom_sessionid) );

	return $comment_ID;
}

/**
* _mollom_had_captcha
* save wether or not a CAPTCHA was shown for this comment
* @param integer $comment_ID the id of the comment
* @return integer The id of the comment
*/
function _mollom_save_had_captcha($comment_ID) {
	global $wpdb, $mollom_sessionid;
	
	$mollom_table = $wpdb->prefix . MOLLOM_TABLE;
	$result = $wpdb->query( $wpdb->prepare("UPDATE $mollom_table SET mollom_had_captcha = 1 WHERE comment_ID = %d", $comment_ID));
	
	return $comment_ID;
}

/** 
* mollom
* call to mollom API over XML-RPC.
* @param string $method the API function you like to call
* @param array $data the arguments the called API function you want to pass
* @return mixed Either a WP_Error on error or a mixed return depending on the called API function
*/
function mollom($method, $data = array()) {
	// Location of the Incutio XML-RPC library which is integrated with Wordpress
	// lazy loading: only if this function gets called
	require_once(ABSPATH . '/wp-includes/class-IXR.php');
	// let's set the user agent string
	$user_agent = MOLLOM_USER_AGENT;
	// let's fetch us some servers
	if (get_option('mollom_servers') == NULL) {
		$result = _mollom_retrieve_server_list();
		if (function_exists('is_wp_error') && is_wp_error($result)) {
			return $result;
		}
	}
	$servers = explode('#', get_option('mollom_servers'));	
	
	// fail-over/loadbalancing act
	foreach ($servers as $server) {
		$mollom_client = new IXR_Client($server . '/' . MOLLOM_API_VERSION);
		$mollom_client->useragent = $user_agent;

		$result = $mollom_client->query($method, $data + _mollom_authenticate());
	
		if($mollom_client->getErrorCode()) {
			// refresh the server list and try again
			if ($mollom_client->getErrorCode() == MOLLOM_REFRESH) {
				$result = _mollom_retrieve_server_list();
				if (function_exists('is_wp_error') && is_wp_error($result)) {
					return $result;
				} else {
					$servers = explode('#', get_option('mollom_servers'));
				}
			}
						
			// redirect to a different server
			else if ($mollom_client->getErrorCode() == MOLLOM_REDIRECT) {
				// $server is overloaded, let's try the next one
				// do nothing, travel through the loop again and try the next server in the list
			}

			// Mollom triggered an error
			else if ($mollom_client->getErrorCode() == MOLLOM_ERROR) {
				// Something went wrong! Return the errorcode
				$mollom_error = new WP_Error();
				$mollom_error->add($mollom_client->getErrorCode(), $mollom_client->getErrorMessage());
				return $mollom_error;
			}
		} else {
			// return a response if all went well
			return $mollom_client->getResponse();
		}
	}

	// renew the server cache. Maybe this will fix things next time.
	$result = _mollom_retrieve_server_list();
	if (function_exists('is_wp_error') && is_wp_error($result)) {
		return $result;
	}
	
	$mollom_error = new WP_Error();
	$mollom_error->add(-6, __('The Mollom servers could not be contacted at this time. Please try again.'));
	return $mollom_error;
}

/**
* _mollom_retrieve_server_list
* retrieves a list of servers and caches it in the database
* @return boolean true if a list was succesfully retrieved and stored. Otherwise, Mollom breaks here.
**/
function _mollom_retrieve_server_list() {
	// hard coded list cfr API documentation, section 9
	$servers = array(
				'http://xmlrpc1.mollom.com/',
				'http://xmlrpc2.mollom.com/',
				'http://xmlrpc3.mollom.com/'
			);
	
	$user_agent = MOLLOM_USER_AGENT;
	
	foreach($servers as $server) {
		$mollom_client = new IXR_Client($server . MOLLOM_API_VERSION);
		$mollom_client->useragent = $user_agent;
		
		if(!$mollom_client->query('mollom.getServerList', _mollom_authenticate())) {
			// Something went wrong! Let's try the next one in the list
		} else {
			$servers = $mollom_client->getResponse();
			update_option('mollom_servers', implode('#', $servers));
			return true;
		}
	}
	
	$mollom_error = new WP_Error();
	$mollom_error->add($mollom_client->getErrorCode(), $mollom_client->getErrorMessage());
	return $mollom_error;
}

/** 
* _mollom_nonce;
* generate a random nonce 
* @return string A random generated nonce of 32 characters
*/
function _mollom_nonce() {
	$str = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
	
	srand((double)microtime()*100000);
	for($i = 0; $i < 32; $i++) {
		$num = rand() % strlen($str);
		$tmp = substr($strn, $num, 1);
		$nonce .= $tmp;
	}
	
	return $nonce;
}

/**
* _mollom_authenticate 
* set an array with all the neccessary data to authenticate to Mollom
* @return string $data the BASE64 encoded authentication string need by the mollom function.
*/
function _mollom_authenticate() {
	$public_key = get_option('mollom_public_key');
	$private_key = get_option('mollom_private_key');
	
  	// Generate a timestamp according to the dateTime format (http://www.w3.org/TR/xmlschema-2/#dateTime):
  	$time = gmdate("Y-m-d\TH:i:s.\\0\\0\\0O", time());
 	
	// generate a random nonce
	$nonce = _mollom_nonce();

	// Calculate a HMAC-SHA1 according to RFC2104 (http://www.ietf.org/rfc/rfc2104.txt):
	$hash =  base64_encode(
	  	pack("H*", sha1((str_pad($private_key, 64, chr(0x00)) ^ (str_repeat(chr(0x5c), 64))) .
    	pack("H*", sha1((str_pad($private_key, 64, chr(0x00)) ^ (str_repeat(chr(0x36), 64))) . 
   	 	$time . ':' . $nonce . ':' . $private_key))))
	);
	
 	// Store everything in an array. Elsewhere in the code, we'll add the
  	// acutal data before we pass it onto the XML-RPC library:
  	$data['public_key'] = $public_key;
 	$data['time'] = $time;
 	$data['hash'] = $hash;
	$data['nonce'] = $nonce;
	
	return $data;
}

/** 
* _mollom_author_ip
* fetch user IP 
* @return string The IP of the host from which the request originates
*/
function _mollom_author_ip() {
	$ip_address = $_SERVER['REMOTE_ADDR'];
  	
	if(get_option('mollom_reverseproxy')) {
		if (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) {
			$reverse_proxy_addresses = explode(get_option('mollom_reverseproxy_addresses'), ',');
			if(!empty($reverse_proxy_addressses) && in_array($ip_address, $reverse_proxy_addresses, TRUE)) {
    			// If there are several arguments, we need to check the most
    			// recently added one, ie the last one.
	    		$ip_address = array_pop(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
			}
	 	 }
  	}
  
  	// If WP is run in a clustered environment
  	if (array_key_exists('HTTP_X_CLUSTER_CLIENT_IP', $_SERVER)) {
    	 $ip_address = $_SERVER['HTTP_X_CLUSTER_CLIENT_IP'];
	}
	
	return $ip_address;
}