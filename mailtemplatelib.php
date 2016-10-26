<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
* This library is a third-party proposal for standardizing mail
* message constitution for third party modules. It is actually used
* by all ethnoinformatique.fr module. It relies on mail and message content
* templates tha should reside in a mail/{$lang}_utf8 directory within the 
* module space.
*
* @package extralibs
* @category third-party libs
* @author Valery Fremaux (France) (valery@valeisti.fr)
* @date 2008/03/03
* @license http://www.gnu.org/copyleft/gpl.html GNU Public License
*/
defined('MOODLE_INTERNAL') || die;

if (!function_exists('compile_mail_template')) {

    /**
    * useful templating functions from an older project of mine, hacked for Moodle
    * @param template the template's file name from $CFG->sitedir
    * @param infomap a hash containing pairs of parm => data to replace in template
    * @return a fully resolved template where all data has been injected
    */
    function compile_mail_template($template, $infomap, $module, $lang = '') {
        global $USER;
        
        if (empty($lang)) $lang = $USER->lang; 
        
        $notification = implode('', get_mail_template($template, $module, $lang));
        foreach($infomap as $aKey => $aValue){
            $notification = str_replace("<%%$aKey%%>", $aValue, $notification);
        }
        return $notification;
    }
}

if (!function_exists('get_mail_template')){
    /*
    * resolves and get the content of a Mail template, acoording to the user's current language.
    * @param virtual the virtual mail template name
    * @param module the current module
    * @param lang if default language must be overriden
    * @return string the template's content or false if no template file is available
    */
    function get_mail_template($virtual, $modulename, $lang = ''){
        global $CFG;

        if ($lang == '') {
            $lang = $CFG->lang;
        }
        if (preg_match('/^auth_/', $modulename)){
            $location = 'auth';
            $modulename = str_replace('auth_', '', $modulename);
        } elseif (preg_match('/^block_/', $modulename)){
            $location = 'blocks';
            $modulename = str_replace('block_', '', $modulename);
        } else {
            $location = 'mod';
        }
        $templateName = "{$CFG->dirroot}/{$location}/{$modulename}/mails/{$lang}/{$virtual}.tpl";
        if (file_exists($templateName))
            return file($templateName);

        notice("template $templateName not found");
        return array();
    }
}
