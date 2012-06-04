<?php

/**
 * Moodle - Modular Object-Oriented Dynamic Learning Environment
 *          http://moodle.org
 * Copyright (C) 1999 onwards Martin Dougiamas  http://dougiamas.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package block-ext_signup
 * @author     Valery Fremaux <valery@valeisti.fr>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL
 * @copyright  (C) 1999 onwards Martin Dougiamas  http://dougiamas.com
 * @copyright  (C) 2010 ValEISTI (http://www.valeisti.fr)
 *
 *
 * Authentication Plugin: External Students SignUp and Authentication
 *
 */

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.');    ///  It must be included from a Moodle page
}

require_once($CFG->libdir.'/authlib.php');
require_once($CFG->libdir.'/gdlib.php');
require_once $CFG->dirroot.'/auth/ticket/lib.php';

/**
 * External student authentication and signup plugin.
 */

class auth_plugin_ext extends auth_plugin_base {

    /**
     * Constructor.
     */
    function auth_plugin_ext() {
        $this->authtype = 'ext';
        $this->config = get_config('auth/ext');
    }

    /**
     * Returns true if the username and password work and false if they are
     * wrong or don't exist.
     *
     * @param string $username The username
     * @param string $password The password
     * @return bool Authentication success or failure.
     */
    function user_login ($username, $password) {
        global $CFG;
        if ($user = get_record('user', 'username', $username, 'mnethostid', $CFG->mnet_localhost_id)) {
            return validate_internal_user_password($user, $password);
        }
        return false;
    }

    /**
     * Updates the user's password.
     *
     * called when the user password is updated.
     *
     * @param  object  $user        User table object  (with system magic quotes)
     * @param  string  $newpassword Plaintext password (with system magic quotes)
     * @return boolean result
     *
     */
    function user_update_password($user, $newpassword) {
        $user = get_complete_user_data('id', $user->id);
        return update_internal_user_password($user, $newpassword);
    }

	// this plugin does not really allow signup
    function can_signup() {
        return false;
    }

    /**
     * Sign up a new user ready for confirmation.
     * Password is passed in plaintext.
     *
     * @param object $user new user object (with system magic quotes)
     * @param boolean $notify print notice with link and terminate
     */
    function user_signup($user, $notify=true, $blockid = 0) {
        global $CFG, $SITE;

        require_once($CFG->dirroot.'/user/profile/lib.php');
        
        $clearpassword = $user->password;
        $user->password = hash_internal_user_password($user->password);
        $usertmpid = $user->userid;
        unset($user->userid); // clean object for insertion

        // save image file name for further use
        $pictureimage = @$user->picture_image;
        unset($user->picture_image); // clean object for insertion       
        $user->picture = 1;

        if (! ($user->id = insert_record('user', $user)) ) {
            print_error('auth_emailnoinsert','auth');
        }
        
        create_context(CONTEXT_USER, $user->id);
        
        make_user_directory($user->id);

		// process uploaded image
	    if ($destination = create_profile_image_destination($user->id, 'user')){
		    process_profile_image($CFG->dataroot.'/user/0/ext_signup/'.$pictureimage, $destination);
		}

        if (!empty($user->profile_field_cv)){
	        rename($CFG->dataroot.'/user/0/ext_signup/'.$user->profile_field_cv, $CFG->dataroot.'/user/0/'.$user->id.'/'.$user->profile_field_cv);
	    }
        
        $coursenames = array();
        /// rebind course selection to new account        
        set_field_select('block_ext_signup', 'userid', $user->id, " userid = '$usertmpid' ");
        if ($coursereqs = get_records('block_ext_signup', 'userid', $user->id)){
            foreach($coursereqs as $req){
                $coursenames[] = get_field('course', 'fullname', 'id', $req->courseid);
            }
        }
        $coursereqnum = count($coursenames);
        
        /// Save any custom profile field information
        profile_save_data($user);

        $user = get_record('user', 'id', $user->id);
        events_trigger('user_created', $user);

        include_once($CFG->dirroot."/auth/ext/mailtemplatelib.php");

        if (!empty($blockid)) {
            if ($CFG->debugsmtp) echo "Sending admin notfications<br/>";
            $blockcontext = get_context_instance(CONTEXT_BLOCK, $blockid);
            if ($adminusers = get_users_by_capability($blockcontext, 'block/ext_signup:benotified', 'u.id,username,firstname,lastname,lang,email,emailstop,mailformat,mnethostid', 'lastname')){
                $vars = array('SITE' => $SITE->shortname, 
                              'FIRSTNAME' => $user->firstname, 
                              'LASTNAME' => $user->lastname, 
                              'CITY' => $user->city, 
                              'MAIL' => $user->email, 
                              'COUNTRY' => $user->country, 
                              'URL' => $CFG->wwwroot.'/login/index.php?ticket=<%%TICKET%%>', 
                              'COURSENUM' => $coursereqnum);
                foreach($adminusers as $adminuser){
                	if (has_capability('block/ext_signup:process', $blockcontext, $adminuser->id)){
	                    $notification = compile_mail_template('submission_process', $vars, 'auth_ext', $adminuser->lang);
	                    $notification_html = compile_mail_template('submission_process_html', $vars, 'auth_ext', $adminuser->lang);
	                } else {
	                    $notification = compile_mail_template('submission', $vars, 'auth_ext', $adminuser->lang);
	                    $notification_html = compile_mail_template('submission_html', $vars, 'auth_ext', $adminuser->lang);
	                }
	                if ($user->confirmed){
	                    ticket_notify($adminuser, $user, get_string('newsignup', 'auth_ext', $SITE->shortname.':'.get_string('externalsignup', 'auth_ext')), $notification, $notification_html, $CFG->wwwroot.'/blocks/ext_signup/process.php?id='.$blockid.'&userid='.$user->id, 'Ext signup');
	                }
                }
            }
        }
        
        if ($notify) {
            $emailconfirm = get_string('emailconfirm');
            $navlinks = array();
            $navlinks[] = array('name' => $emailconfirm, 'link' => null, 'type' => 'misc');
            $navigation = build_navigation($navlinks);

            print_header($emailconfirm, $emailconfirm, $navigation);

            $vars = array('SITE' => $SITE->shortname, 
                          'USERNAME' => $user->username, 
                          'PASSWORD' => $clearpassword, 
                          'FIRSTNAME' => $user->firstname, 
                          'LASTNAME' => $user->lastname, 
                          'CITY' => $user->city, 
                          'MAIL' => $user->email, 
                          'COUNTRY' => $user->country, 
                          'COURSELIST' => implode(",\n", $coursenames),
                          );
            $vars_html = array('SITE' => $SITE->shortname, 
                          'USERNAME' => $user->username, 
                          'PASSWORD' => $clearpassword, 
                          'FIRSTNAME' => $user->firstname, 
                          'LASTNAME' => $user->lastname, 
                          'CITY' => $user->city, 
                          'MAIL' => $user->email, 
                          'COUNTRY' => $user->country, 
                          'COURSELIST' => implode(",<br/>\n", $coursenames),
                          );
            $userlang = (empty($CFG->block_ext_signup_submitternotifylang)) ? $user->lang : $CFG->block_ext_signup_submitternotifylang ;
            $notification = compile_mail_template('acknowledge', $vars, 'auth_ext', $userlang);
            $notification_html = compile_mail_template('acknowledge_html', $vars_html, 'auth_ext', $userlang);
            if ($CFG->debugsmtp) echo "Sending Acnowledge Mail Notification to " . fullname($user) . '<br/>'.$notification_html.'<br/>';
            email_to_user($user, '', get_string('acknowledge', 'auth_ext', $SITE->shortname), $notification, $notification_html);

            echo '<br/>';
            notice(get_string('processhelp2', 'auth_ext', $user->email), "$CFG->wwwroot/index.php");
        } else {
            return true;
        }
    }

    /**
     * Returns true if this authentication plugin can change the user's
     * password.
     *
     * @return bool
     */
    function can_change_password() {
        return true;
    }

    /**
     * Returns the URL for changing the user's pw, or empty if the default can
     * be used.
     *
     * @return mixed
     */
    function change_password_url() {
        return ''; // use dafult internal method
    }

    /**
     * Returns true if plugin allows resetting of internal password.
     *
     * @return bool
     */
    function can_reset_password() {
        return true;
    }

    /**
     * Prints a form for configuring this authentication plugin.
     *
     * This function is called from admin/auth.php, and outputs a full page with
     * a form for configuring this plugin.
     *
     * @param array $page An object containing all the data for this page.
     */
    function config_form($config, $err, $user_fields) {
        include "config.html";
    }

    /**
     * Processes and stores configuration data for this authentication plugin.
     */
    function process_config($config) {
        // set to defaults if undefined
        if (!isset($config->recaptcha)) { 
            $config->recaptcha = false; 
        }
        
        // save settings
        set_config('recaptcha', $config->recaptcha, 'auth/ext');
        return true;
    }
    
    /**
     * Returns whether or not the captcha element is enabled, and the admin settings fulfil its requirements.
     * @abstract Implement in child classes
     * @return bool
     */
    function is_captcha_enabled() {
        return false;
    }

}

?>
