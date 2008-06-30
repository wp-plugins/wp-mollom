<?php
/* Plugin Name: Mollom
Plugin URI: http://www.netsensei.nl/mollom/
Description: Enable <a href="http://www.mollom.com">Mollom</a> on your wordpress blog
Author: Matthias Vandermaesen
Version: 0.5.1
Author URI: http://www.netsensei.nl
Email: matthias@netsensei.nl

Version history:
- 2 april 2008: creation
- 12 may 2008: first closed release
- 22 may 2008: second closed release
- 29 may 2008: third closed release
- 3 june 2008: first public release
- 28 juni 2008: second public release
- 30 juni 2008: small bugfix release
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
define( 'MOLLOM_VERSION', '0.5.1' );
define( 'MOLLOM_TABLE', 'mollom' );

define( 'MOLLOM_ERROR'   , 1000 );
define( 'MOLLOM_REFRESH' , 1100 );
define( 'MOLLOM_REDIRECT', 1200 );

define('MOLLOM_ANALYSIS_HAM'     , 1);
define('MOLLOM_ANALYSIS_SPAM'    , 2);
define('MOLLOM_ANALYSIS_UNSURE'  , 3);

// Location of the Incutio XML-RPC library which is integrated with Wordpress
require_once(ABSPATH . '/wp-includes/class-IXR.php');

/** 
* mollom_activate
* activate the plugnin and install stuff upon first activation
*/
function mollom_activate() {
	global $wpdb;

	// create a new table to store mollom sessions if it doesn't exist
	$mollom_table = $wpdb->prefix . MOLLOM_TABLE;
	if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $mollom_table) {
		$sql = "CREATE TABLE " . $mollom_table . " (
				`comment_ID` BIGINT( 20 ) UNSIGNED NOT NULL DEFAULT '0',
				`mollom_session_ID` VARCHAR( 40 ) NULL DEFAULT NULL,
				UNIQUE (
					`comment_ID` ,
					`mollom_session_ID`
				)
			);";
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);
	}
	
	// if there is no version, mollom is installed for the first time
	if (!get_option('mollom_version')) {
		//Set these variables if they don't exist
		$version = MOLLOM_VERSION;
		add_option('mollom_version', $version);
		
		if(!get_option('mollom_private_key'))
			add_option('mollom_private_key', '');
		if(!get_option('mollom_public_key'))		
			add_option('mollom_public_key', '');
		if(!get_option('mollom_servers'))
			add_option('mollom_servers', NULL);
		if(!get_option('mollom_count'))
			add_option('mollom_count', 0);
		if(!get_option('mollom_site_policy'))
			add_option('mollom_site_policy', true);
		if(!get_option('mollom_dbrestore'))
			add_option('mollom_dbrestore', false);			
	} else {	
		if (get_option('mollom_version') != MOLLOM_VERSION) {
			// updates of the database if the plugin  was already installed
			$version = MOLLOM_VERSION;
			update_option('mollom_version', $version);
		
			// legacy code here: moving data from old to new data model
			$comments_table = $wpdb->prefix . 'comments';
			$comments = $wpdb->get_results("SELECT comment_ID, mollom_session_ID FROM $comments_table WHERE mollom_session_ID IS NOT NULL");
		
			if ($comments) {
				$stat = true;
			
				foreach($comments as $comment) {				
					if(!$wpdb->query("INSERT INTO $mollom_table(comment_ID, mollom_session_ID) VALUES($comment->comment_ID, '$comment->mollom_session_ID')"))
						$stat = false;
				}
			
				if($stat) {
					$wpdb->query("ALTER TABLE $wpdb->comments DROP COLUMN mollom_session_id");
				} else {
					wp_die(__('Something went wrong while moving data from comments to the new Mollom data table'));
				}
			
			}
			// end of legacy code
		}
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
		delete_option('mollom_dbrestore');
		if(get_option('mollom_private_key'))
			delete_option('mollom_private_key');
		if(get_option('mollom_public_key'))
			delete_option('mollom_public_key');
		if(get_option('mollom_servers'))
			delete_option('mollom_servers');
		if(get_option('mollom_version'))
			delete_option('mollom_version');
		if(get_option('mollom_count'))
			delete_option('mollom_count');
				
		$mollom_table = $wpdb->prefix . MOLLOM_TABLE;
		
		// delete MOLLOM_TABLE
		$wpdb->query("DROP TABLE '$mollom_table'");
	}
}
register_deactivation_hook(__FILE__, 'mollom_deactivate');

/** 
* mollom_config_page
* hook the config page in the Wordpress administration module 
*/
function mollom_config_page() {
	if (function_exists('add_submenu_page')) 
		add_submenu_page('options-general.php', __('Mollom'), __('Mollom'), 'manage_options', 'mollom-key-config', 'mollom_config');
}
add_action('admin_menu','mollom_config_page');

/** 
* mollom_manage_page
* hook the manage page in the Wordpress administration module
*/
function mollom_manage_page() {
	global $submenu;
	if ( isset( $submenu['edit-comments.php'] ) )
		add_submenu_page('edit-comments.php', __('Caught Spam'), 'Mollom', 'moderate_comments', 'mollommanage', 'mollom_manage');
	if ( function_exists('add_management_page') )
		add_management_page(__('Caught Spam'), 'Mollom', 'moderate_comments', 'mollommanage', 'mollom_manage');
}
add_action('admin_menu','mollom_manage_page');

/** 
* _mollom_set_plugincount
* count comments that were asserted as spam 
*/
function _mollom_set_plugincount() {
	$count = get_option('mollom_count');
	$count++;
	update_option('mollom_count', $count);
}

/** 
* _mollom_get_plugincount
* get the amount of blocked items 
* @return integer $count the amount of blocked items
*/
function _mollom_get_plugincount() {
	$count = get_option('mollom_count');
	return $count;
}

/** 
* mollom_show_count
* show the amount of blocked items 
*/
function mollom_show_count() {
	echo "Mollom has eaten " . _mollom_get_plugincount() . " spams so far.";
}

/**
* mollom_moderate_comment
* Show moderation options in your theme if you're logged in and have permissions. Must be within the comment loop.
* @param integer $comment_ID the id of the comment to moderate
* @param string $class the CSS style class
*/
function mollom_moderate_comment($comment_ID) {
	if (function_exists('current_user_can') && current_user_can('manage_options')) {
		$spam = clean_url(wp_nonce_url('edit-comments.php?page=mollommanage&amp;c=' . $comment_ID . '&maction=spam', 'mollom-moderate-comment'));
		$profanity = clean_url(wp_nonce_url('edit-comments.php?page=mollommanage&amp;c=' . $ccomment_ID . '&maction=profanity', 'mollom-moderate-comment'));
		$lowquality = clean_url(wp_nonce_url('edit-comments.php?page=mollommanage&amp;c=' . $comment_ID . '&maction=lowquality', 'mollom-moderate-comment'));
		$unwanted = clean_url(wp_nonce_url('edit-comments.php?page=mollommanage&amp;c=' . $comment_ID . '&maction=unwanted', 'mollom-moderate-comment'));
		
		$str = 'Moderate: <a href="wp-admin/' . $spam . '" title="moderate as spam">spam</a> | ' .
		    '<a href="wp-admin/' . $profanity . '" title="moderate as profanity">profanity</a> | ' .
			'<a href="wp-admin/' . $lowquality . '" title="moderate as low quality">low quality</a> | ' .
			'<a href="wp-admin/' . $unwanted . '" title="moderate as unwanted">unwanted</a>';
		
		return $str;
	}
}

/** 
* mollom_config
* Handles the configuration  on your blog(keys, options,...) 
* @return array $ms an array containing possible errors. Empty if all went succesfull.
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
				
		$privatekey = $wpdb->escape($_POST['mollom-private-key']);
		$publickey = $wpdb->escape($_POST['mollom-public-key']);
			
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
			
			$result = _mollom_verify_key();
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
	} else {
		$privatekey = get_option('mollom_private_key');
		$publickey = get_option('mollom_public_key');
		
		if (!empty($privatekey) && !empty($publickey)) {
			$result = _mollom_verify_key();
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
<h2>Mollom Configuration</h2>
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
	<p><input type="checkbox" name="sitepolicy" <?php if (get_option('mollom_site_policy')) echo 'value = "on" checked'; ?>>&nbsp;&nbsp;<?php _e('If Mollom services are down, all comments are blocked by default.'); ?></p>
	<h3><label><?php _e('Restore'); ?></label></h3>
	<p><input type="checkbox" name="mollomrestore" <?php if (get_option('mollom_dbrestore')) echo 'value = "on" checked'; ?>>&nbsp;&nbsp;<?php _e('Restore the database (purge all Mollom data) upon deactivation of the plugin.'); ?></p>

	<p class="submit"><input type="submit" value="Update options &raquo;" id="submit" name="submit"/></p>
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
* @param array $ms return an empty array on success. If failed: array contains error messages
*/
function _mollom_send_feedback($action, $comment_ID) {
	global $wpdb;
	$mollom_table = $wpdb->prefix . MOLLOM_TABLE;
	$ms = array();
	
	$mollom_sessionid = $wpdb->get_var("SELECT mollom_session_ID FROM $mollom_table WHERE comment_ID = $comment_ID");
		
	switch($action) {
		case $action == "spam":
			$data = array('feedback' => $action, 'session_id' => $mollom_sessionid);
			break;
		case $action == "profanity":
			$data = array('feedback' => $action, 'session_id' => $mollom_sessionid);
			break;
		case $action == "unwanted":
			$data = array('feedback' => $action, 'session_id' => $mollom_sessionid);
			break;
		case $action == "lowquality":
			$data = array('feedback' => 'low-quality', 'session_id' => $mollom_sessionid);
			break;
		default:
			$ms[] = 'invalidaction';
			return $ms;
			break;
	}
		
	$result = mollom('mollom.sendFeedback', $data);
		
	if($result) {
		if($wpdb->query("DELETE FROM $wpdb->comments, $mollom_table USING $wpdb->comments INNER JOIN $mollom_table USING(comment_ID) WHERE $wpdb->comments.comment_ID = $comment_ID"))
			return $ms; // return an empty array upon success
		else
			$ms[] = 'feedbacksuccess';
	}		
	else if (function_exists( 'is_wp_error' ) && is_wp_error( $result )) {
		$ms[] = 'mollomerror';
	}
	else {
		$ms[] = 'network';
	}
	
	return $ms; // return errors
}

/** 
* mollom_manage
* Moderate messages that have a stored Mollom session ID 
*/
function mollom_manage() {

	if (function_exists('current_user_can') && !current_user_can('manage_options'))
		die(__('Cheatin&#8217; uh?'));
		
	global $wpdb;
	$ms = array();
	$broken_comment = "";
		
	// moderation of a single item
	if ($_GET['maction']) {
		$mollom_private_key = get_option('mollom_private_key');
		$mollom_public_key = get_option('mollom_public_key');
		
		if (empty($mollom_private_key) || empty($mollom_public_key)) {
			$ms[] = 'emptykeys';
		} else {
			if (function_exists('check_admin_referer'))
				check_admin_referer('mollom-moderate-comment');
			
			$action = attribute_escape($_GET['maction']);
			$comment_ID = attribute_escape($_GET['c']);
			
			$ms = $ms + _mollom_send_feedback($action, $comment_ID);
			if (count($ms) == 0) // empty array = succes
				$ms[] = 'allsuccess';
			else
				$comment_ID = $broken_comment;
		}
	}
	
	// moderation of multiple items (bulk)
	if (!empty($_REQUEST["delete_comments"])) {
		$mollom_private_key = get_option('mollom_private_key');
		$mollom_public_key = get_option('mollom_public_key');
		
		if (empty($mollom_private_key) || empty($mollom_public_key)) {
			$ms[] = 'emptykeys';	
		} else {
			foreach($_REQUEST["delete_comments"] as $comment) {
				check_admin_referer('mollom-bulk-moderation');
				
				$comment_ID = (int) $wpdb->escape($comment);
				$action = $wpdb->escape($_REQUEST['maction']);
							
				$ms = $ms + _mollom_send_feedback($action, $comment_ID);
				
				if(count($ms) > 0) {
					$broken_comment = $comment_ID;
					break;
				}
			}
			
			if (count($ms) == 0) 
				$ms[] = 'allsuccess';
		}
	}
	
	// from here on: generate messages and overview page
	$messages = array('allsuccess' => array('color' => 'aa0', 'text' => __('Comment feedback sent. All comments successfully deleted.')),
					  'feedbacksuccess' => array('color' => 'aa0', 'text' => __('Comment ' . $broken_comment .': Comment feedback sent but the comment could not be deleted.')),
					  'networkfail' => array('color' => 'aa0', 'text' => __('Comment ' . $broken_comment .': Mollom was unreachable. Maybe the service is down or there is a network disruption.')),
					  'emptykeys' => array('color' => 'aa0', 'text' => __('Comment ' . $broken_comment .': Could not perform action because the Mollom plugin was not configured. Please configure it first.')),
					  'mollomerror' => array('color' => 'aa0', 'text' => __('Comment ' . $broken_comment .': Mollom could not process your request.')),
					  'invalidaction' => array('color' => 'aa0', 'text' => __('Comment ' . $broken_comment .': Invalid mollom feedback action.'))); 
	
	// pagination code
	$show_next = true;

	$mollom_table = $wpdb->prefix . MOLLOM_TABLE;
	$count = $wpdb->get_var("SELECT COUNT(mollom_session_ID) FROM $mollom_table");
	
	if ($count > 0) {
		if ($_GET['apage'])
			$apage = $wpdb->escape($_GET['apage']);
		else
			$apage = 0;
		
		if ($apage == 0) {
			$start = $apage;
			$limit = $apage + 25;
		} else {
			$start = ($apage * 25) + 1;
			$limit = $start + 25;
		}
		
		$prevpage = $apage - 1;
		$nextpage = $apage + 1;
				
		$comments = $wpdb->get_results("SELECT comments.* FROM $wpdb->comments comments, $mollom_table mollom WHERE mollom.comment_ID = comments.comment_ID ORDER BY comment_date DESC LIMIT $start, $limit");

		if ($limit >= $count)
			$show_next = false;
	}
	else
		$comments = false;

?>
<script type="text/javascript">
//<![CDATA[
function checkAll(form) {
	for (i = 0, n = form.elements.length; i < n; i++) {
		if(form.elements[i].type == "checkbox" && !(form.elements[i].getAttribute('onclick',2))) {
 	  	if(form.elements[i].checked == true)
 	    	form.elements[i].checked = false;
 	    else
	    	form.elements[i].checked = true;
 	  }
 	}
}
//]]>
</script>
<style type="text/css">
.mollom-comment-list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.mollom-comment-list li {
	border-bottom: 1px solid #ddd;
	margin: 0 0 15px 0;
	padding: 0 0 33px 0;
	clear: right;
}

.mollom-comment-head {
	background: #ddd;
	font-size: 1.1em;
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

</style>
<div class="wrap">
<h2>Mollom Manage</h2>
<p><?php _e('Mollom stops spam before it even reaches your database.'); ?></p>
<p><?php _e('This is an overview of all the Mollom approved comments posted on your website. You can moderate them here. Through moderating these messages, Mollom learns from it\'s mistakes. Moderation of errors is encouraged.'); ?></p>
<p><?php _e('So far, Mollom has stopped '); echo _mollom_get_plugincount(); _e(' spam messages on your blog.'); ?></p>
<?php if(!empty($ms)) { foreach ( $ms as $m ) : ?>
<p style="padding: .5em; background-color: #<?php echo $messages[$m]['color']; ?>; color: #fff; font-weight: bold;"><?php echo $messages[$m]['text']; ?></p>
<?php endforeach; } ?>
<?php if (!$comments) { ?>

<p class="mollom-no-comments">There are no comments that can be moderated through Mollom.</p>

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
	<a href="edit-comments.php?page=mollommanage&apage=<?php echo $prevpage; ?>">&laquo;Previous</a>
<?php }
	if($show_next) { ?>
	<a href="edit-comments.php?page=mollommanage&apage=<?php echo $nextpage; ?>">Next&raquo;</a>
<?php } ?>
</div>
</div>
<ul class="mollom-comment-list">
	<?php foreach ($comments as $comment) {
	
		$spam = clean_url(wp_nonce_url('edit-comments.php?page=mollommanage&c=' . $comment->comment_ID . '&maction=spam', 'mollom-moderate-comment'));
		$profanity = clean_url(wp_nonce_url('edit-comments.php?page=mollommanage&c=' . $comment->comment_ID . '&maction=profanity', 'mollom-moderate-comment'));
		$lowquality = clean_url(wp_nonce_url('edit-comments.php?page=mollommanage&c=' . $comment->comment_ID . '&maction=lowquality', 'mollom-moderate-comment'));
		$unwanted = clean_url(wp_nonce_url('edit-comments.php?page=mollommanage&c=' . $comment->comment_ID . '&maction=unwanted', 'mollom-moderate-comment')); 
		
		if (strlen($comment->comment_author_url) > 32)
			$comment_url = substr($comment->comment_author_url, 0, 32) . '...';
		else
			$comment_url = $comment->comment_author_url;
	?>
	
	<?php if ($comment->comment_approved == 0) { ?>
	<li style="background:#fdf8e4;">
	<?php } else { ?>
	<li>
	<?php } ?>	
		<p class="mollom-comment-head"><input type="checkbox" name="delete_comments[]" value="<?php echo $comment->comment_ID; ?>" />&nbsp;&nbsp<strong><?php echo $comment->comment_author; ?></strong></p>
		<p><strong><?php echo $comment->comment_title; ?></strong></p>
		<p><?php echo $comment->comment_content; ?></p>
		<p class="mollom-comment-metadata"><?php echo $comment_url; ?> | <?php echo $comment->comment_date; ?> |
		<?php echo $comment->comment_author_IP; ?></p>
		<p class="mollom-action-links"><a href="<?php echo $spam; ?>">spam</a> | <a href="<?php echo $profanity; ?>">profanity</a>
		| <a href="<?php echo $lowquality; ?>">low-quality</a> | <a href="<?php echo $unwanted; ?>">unwanted</a></p>
	</li>
	<?php } ?>
</ul>
</form>
<?php } ?>

</div>
<?php
}

/** 
* _mollom_verify_key
* vverifies the private/public key combo against the Mollom servers' information
* @return mixed $return returns true if authenticated, or a WP_Error object if something goes wrong 
*/
function _mollom_verify_key() {	
	return mollom('mollom.verifyKey');
}

/** 
* mollom_check_comment
* Check if a comment is spam or ham
* @param array $comment the comment passed by the preprocess_comment hook
* @return array $comment the comment passed by the preprocess_comment hook
*/
function mollom_check_comment($comment) {
	global $mollom_sessionid;
	
	if($comment['comment_type'] == 'trackback')
		return $comment;
	
	$private_key = get_option('mollom_private_key');
	$public_key = get_option('mollom_public_key');
	
	// check if the client is configured all toghether
	if ((empty($private_key)) || (empty($public_key))) {
		if (get_option('mollom_site_policy'))
			wp_die(__('You haven\'t configured Mollom yet! Per the website\'s policy. We could not process your comment.'));
	}
	
	// only if the user is not registered
	$user = wp_get_current_user();
	
	if (!$_POST['mollom_sessionid'] && !$user->ID) {
		$mollom_comment_data = array('post_body' => strip_tags($comment['comment_content']), // strip all the HTML/PHP from the content body
									 'author_name' => $comment['comment_author'],
									 'author_url' => $comment['comment_author_url'],
									 'author_mail' => $comment['comment_author_email'],
									 'author_ip' => _mollom_author_ip());
				
		$result = mollom('mollom.checkContent', $mollom_comment_data);		

		// quit if an error was thrown else return to WP Comment flow
		if (function_exists('is_wp_error') && is_wp_error($result)) {
			if(get_option('mollom_site_policy'))
				wp_die($result, "Something went wrong!");
			else
				return $comment;
		}
		
		$mollom_sessionid = $result['session_id'];
				
		if($result['spam'] == MOLLOM_ANALYSIS_HAM) {
			// let the comment pass			
			add_action('comment_post', '_mollom_save_session', 1);
			return $comment;
		}

		elseif ($result['spam'] == MOLLOM_ANALYSIS_SPAM) {
			// kill the process here because of spam detection
			_mollom_set_plugincount();
			wp_die(__('Your comment has been marked as spam or unwanted by Mollom. It could not be accepted.'));
		}
	
		elseif($result['spam'] == MOLLOM_ANALYSIS_UNSURE) {
			// show a CAPTCHA if unsure			
			$mollom_comment = array('comment_post_ID' => $comment['comment_post_ID'],
									'mollom_sessionid' => $result['session_id'],
									'author' => $comment['comment_author'],
									'url' => $comment['comment_author_url'],
									'email' => $comment['comment_author_email'],
									'comment' => $comment['comment_content']);

			_mollom_set_plugincount();
			_mollom_show_captcha('', $mollom_comment);
			die();
		}
		
		elseif (function_exists('is_wp_error') && is_wp_error($result)) {
			if(get_option('mollom_site_policy'))
				wp_die($result, 'Something went wrong...');
			else
				return $comment;
		}
	}
	
	return $comment;	
}
add_action('preprocess_comment', 'mollom_check_comment');

/** 
* mollom_check_trackback
* check if a trackback is ham or spam 
* @param array $comment the comment passed by the preprocess_comment hook
* @return array $comment the comment passed by the preprocess_comment hook
*/
function mollom_check_trackback($comment) {

	if($comment['comment_type'] != 'trackback')
		return $comment;
	
	global $mollom_sessionid;
	
	$private_key = get_option('mollom_private_key');
	$public_key = get_option('mollom_public_key');
	
	// check if the client is configured
	if ((empty($private_key)) || (empty($public_key))) {
		if (get_option('mollom_site_policy'))
			wp_die(__('You haven\'t configured Mollom yet! Per the website\'s policy. We could not process your comment.'));
	}
	
	$mollom_comment_data = array('post_body' => strip_tags($comment['comment_content']), // strip all the HTML/PHP from the content body
								'author_name' => $comment['comment_author'],
								'author_url' => $comment['comment_author_url'],
								'author_mail' => $comment['comment_author_email'],
								'author_ip' => _mollom_author_ip());
				
	$result = mollom('mollom.checkContent', $mollom_comment_data);

	// quit if an error was thrown else return to WP Comment flow
	if (function_exists('is_wp_error') && is_wp_error($result)) {
		if(get_option('mollom_site_policy'))
			wp_die($result, "Something went wrong!");
		else
			return $comment;
	}

	$mollom_sessionid = $result['session_id'];
		
	if($result['spam'] == MOLLOM_ANALYSIS_HAM) {
		// let the comment pass
		add_action('comment_post', '_mollom_save_session', 1); // save session!!
		return $comment;
	}

	elseif ($result['spam'] == MOLLOM_ANALYSIS_SPAM) {
		// kill the process here because of spam detection
		_mollom_trackback_error('spam', 'Mollom recognized your trackback as spam.');
	}
	
	elseif($result['spam'] == MOLLOM_ANALYSIS_UNSURE) {
		// kill the process here because of unsure detection
		_mollom_trackback_error('unsure', 'Mollom could not recognize your trackback as spam or ham.');
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
* @param string $code the error code to be outputted
* @param string $error_message the error message to be outputted
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
* _mollom_save_session
* save the session ID in the database in MOLLOM_TABLE
* @param integer $comment_ID the id of the comment for which to save the session
* @return integer $comment_ID the id of the comment for which to save the session
*/
function _mollom_save_session($comment_ID) {
	global $wpdb, $mollom_sessionid;
	
	// set the mollom session id for later use when moderation is needed
	$mollom_table = $wpdb->prefix . MOLLOM_TABLE;
	$result = $wpdb->query("INSERT INTO $mollom_table (comment_ID, mollom_session_ID) VALUES($comment_ID, '$mollom_sessionid')");

	return $comment_ID;
}

/**
* _mollom_check_captcha 
* Check the answer of the CAPTCHA presented by Mollom. Called through the pre_process_hook as a callback.
* @param array $comment the comment array passed by the pre_process hook 
* @return array $comment the comment array passed by the pre_process hook 
*/
function _mollom_check_captcha($comment) {
	if ($_POST['mollom_sessionid']) {
		global $wpdb, $mollom_sessionid;
		
		$mollom_sessionid = $_POST['mollom_sessionid'];
		$solution = $_POST['mollom_solution'];
		
		$comment['comment_content'] = stripslashes(htmlspecialchars_decode($comment['comment_content']));
					
		if ($solution == '') {
			$message = 'You didn\'t fill out all the required fields, please try again';
			_mollom_show_captcha($message, $_POST);
			die();
		}
		
		$data = array('session_id' => $mollom_sessionid, 'solution' => $solution, 'author_ip' => _mollom_author_ip());
			
		$result = mollom('mollom.checkCaptcha', $data);
		
		// quit if an error was thrown else return to WP Comment flow
		if (function_exists('is_wp_error') && is_wp_error($result)) {
			if(get_option('mollom_site_policy'))
				wp_die($result, "Something went wrong!");
			else
				return $comment;
		}
		
		// if correct
		else if ($result) {
			global $mollom_sessionid;
			$mollom_sessionid = $result['session_id'];
			add_action('comment_post', '_mollom_save_session', 1);
			return $comment;
		} 
		
		// if incorrect
		else if (!$result) {
			$message = 'The solution you submitted to the CAPTCHA was incorrect. Please try again...';
			// let's be forgiving and provide with a new CAPTCHA
			_mollom_show_captcha($message, $_POST);
			die();
		}
	}	

	return $comment;
}
add_action('preprocess_comment','_mollom_check_captcha');

/** 
* _mollom_show_captcha
* generate and show the captcha form 
* @param string $message an status or error message that needs to be shown to the user
* @param array $mollom_comment the array with the comment data
*/
function _mollom_show_captcha($message = '', $mollom_comment = array()) {
	$data = array('author_ip' => _mollom_author_ip(), 'session_id' => $mollom_comment['mollom_sessionid']);

	$result = mollom('mollom.getAudioCaptcha', $data);	
	if (function_exists('is_wp_error') && is_wp_error($result)) {
		if(get_option('mollom_site_policy'))
			wp_die($result, 'Something went wrong...');
	}
	$mollom_audio_captcha = $result['url'];

	$result = mollom('mollom.getImageCaptcha', $data);	
	if (function_exists('is_wp_error') && is_wp_error($result)) {
		if(get_option('mollom_site_policy'))
			wp_die($result, 'Something went wrong...');
	}

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

		#logo { margin: 6px 0 14px 0px; border-bottom: none;}

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
	<input type="hidden" value="<?php echo $mollom_comment['mollom_sessionid']; ?>" name="mollom_sessionid" />
	<input type="hidden" value="<?php echo $mollom_comment['comment_post_ID']; ?>" name="comment_post_ID" />
	<input type="hidden" value="<?php echo $mollom_comment['author']; ?>" name="author" />
	<input type="hidden" value="<?php echo $mollom_comment['url']; ?>" name="url" />
	<input type="hidden" value="<?php echo $mollom_comment['email']; ?>" name="email" />
	<input type="hidden" value="<?php echo htmlspecialchars($mollom_comment['comment']); ?>" name="comment" /></p>
	<p><input type="submit" value="Submit" class="submit" /></p>
</form>
</body>
</html>

<?php
}

/** 
* mollom
* call to mollom API over XML-RPC.
* @param string $method the API function you like to call
* @param array $data the arguments the called API function you want to pass
* @return mixed $result either a WP_Error on error or a mixed return depending on the called API function
*/
function mollom($method, $data = array()) {	
	if (get_option('mollom_servers') == NULL) {
		$mollom_client = new IXR_Client('http://xmlrpc.mollom.com/'. MOLLOM_API_VERSION);
		
		if(!$mollom_client->query('mollom.getServerList', _mollom_authenticate())) {
				// Something went wrong! Return the error
				$mollom_error = new WP_Error();
				$mollom_error->add($mollom_client->getErrorCode(), $mollom_client->getErrorMessage());
				return $mollom_error;
		}

		$servers = $mollom_client->getResponse();
		
		update_option('mollom_servers', implode('#', $servers));
	} else {
		$servers = explode('#', get_option('mollom_servers'));	
	}
	
	foreach ($servers as $server) {
		$mollom_client = new IXR_Client($server . '/' . MOLLOM_API_VERSION);

		$result = $mollom_client->query($method, $data + _mollom_authenticate());
	
		if($mollom_client->getErrorCode()) {
			// refresh the serverlist
			if ($mollom_client->getErrorCode() == MOLLOM_REFRESH) {
				$mollom_client = new IXR_Client('http://xmlrpc.mollom.com/'. MOLLOM_API_VERSION);
		
				if(!$mollom_client->query('mollom.getServerList', _mollom_authenticate())) {
					$mollom_error = new WP_Error();
					$mollom_error->add($mollom_client->getErrorCode(), $mollom_client->getErrorMessage());
					return $mollom_error;
				}
		
				$servers = $mollom_client->getResponse();
				update_option('mollom_servers', implode('#', $servers));				
			}
			
			// redirect to a different server
			else if ($mollom_client->getErrorCode() == MOLLOM_REDIRECT) {
				// do nothing, travel through the loop again and try the next server in the list
			}

			// Mollom triggered an error
			else if ($mollom_client->getErrorCode() == MOLLOM_ERROR) {
				// Something went wrong! Return the errorcode
				$mollom_error = new WP_Error();
				$mollom_error->add($mollom_client->getErrorCode(), $mollom_client->getErrorMessage());
				return $mollom_error;
			}
						
			// Error of a different kind (network, etc)
			else {
				$mollom_error = new WP_error();
				$mollom_error->add($mollom_client->getErrorCode(), $mollom_client->getErrorMessage());
				return $mollom_error;
			}
		} else {
			// return a response if all went well
			return $mollom_client->getResponse();
		}
	}
}

/** 
* _mollom_nonce;
* generate a random nonce 
* @return string $nonce a random generated nonce of 32 characters
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
* return string $data the BASE64 encoded authentication string need by the mollom function.
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
* @return string $ip_adress the IP of the host from which the request originates
*/
function _mollom_author_ip() {
  $ip_address = $_SERVER['REMOTE_ADDR'];
  if (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) {
    // If there are several arguments, we need to check the most
    // recently added one, ie the last one.
    $ip_address = array_pop(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
  }
  return $ip_address;
}