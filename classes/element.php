<?php
// This file is part of the customcert module for Moodle - http://moodle.org/
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
 * This file contains the customcert element facetofacedate's core interaction API.
 *
 * @package    customcertelement_facetofacedate
 * @copyright  2022 Leon Stringer <leon.stringer@ntlworld.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace customcertelement_facetofacedate;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/lib/grade/constants.php');

/**
 * The customcert element face-to-face session date's core interaction API.
 *
 * @package    customcertelement_facetofacedate
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class element extends \mod_customcert\element {

    /**
     * This function renders the form elements when adding a customcert element.
     *
     * @param \MoodleQuickForm $mform the edit_form instance
     */
    public function render_form_elements($mform) {
        global $CFG, $COURSE, $DB;
        $sessions = array();
        $sql = "SELECT ff.id, ff.name
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {facetoface} ff ON ff.id = cm.instance
                  JOIN {course} c ON c.id = cm.course
                 WHERE m.name = 'facetoface' AND c.id = ?";
        $params = array($COURSE->id);
        $records = $DB->get_records_sql($sql, $params);
        foreach ($records as $record) {
            $sessions[$record->id] = $record->name;
        }

        $mform->addElement('select', 'f2finstance', get_string('f2finstance', 'customcertelement_facetofacedate'), $sessions);
        $mform->addHelpButton('f2finstance', 'f2finstance', 'customcertelement_facetofacedate');

        $datefields = array();
        $datefields['timestart'] = get_string('timestart', 'customcertelement_facetofacedate');
        $datefields['timefinish'] = get_string('timefinish', 'customcertelement_facetofacedate');
        $mform->addElement('select', 'datefield', get_string('datefield', 'customcertelement_facetofacedate'), $datefields);
        $mform->addHelpButton('datefield', 'datefield', 'customcertelement_facetofacedate');


        $mform->addElement('select', 'dateformat', get_string('dateformat', 'customcertelement_facetofacedate'), self::get_date_formats());
        $mform->addHelpButton('dateformat', 'dateformat', 'customcertelement_facetofacedate');

        parent::render_form_elements($mform);
    }

    /**
     * This will handle how form data will be saved into the data column in the
     * customcert_elements table.
     *
     * @param \stdClass $data the form data
     * @return string the json encoded array
     */
    public function save_unique_data($data) {
        // Array of data we will be storing in the database.
        $arrtostore = array(
            'f2finstance' => $data->f2finstance,
            'datefield' => $data->datefield,
            'dateformat' => $data->dateformat
        );

        // Encode these variables before saving into the DB.
        return json_encode($arrtostore);
    }

    /**
     * Handles rendering the element on the pdf.
     *
     * @param \pdf $pdf the pdf object
     * @param bool $preview true if it is a preview, false otherwise
     * @param \stdClass $user the user we are rendering this for
     */
    public function render($pdf, $preview, $user) {
        global $DB;

        // If there is no element data, we have nothing to display.
        if (empty($this->get_data())) {
            return;
        }

        $courseid = \mod_customcert\element_helper::get_courseid($this->id);

        // Decode the information stored in the database.
        $dateinfo = json_decode($this->get_data());
        $f2finstance = $dateinfo->f2finstance;
        $datefield = $dateinfo->datefield;
        $dateformat = $dateinfo->dateformat;

        // If we are previewing this certificate then just show a demonstration date.
        if ($preview) {
            $date = time();
        } else {
            // Get the page.
            $page = $DB->get_record('customcert_pages', array('id' => $this->get_pageid()), '*', MUST_EXIST);
            // Get the customcert this page belongs to.
            $customcert = $DB->get_record('customcert', array('templateid' => $page->templateid), '*', MUST_EXIST);
            // Now we can get the issue for this user.
            $issue = $DB->get_record('customcert_issues', array('userid' => $user->id, 'customcertid' => $customcert->id),
                '*', IGNORE_MULTIPLE);

            $date = '';
            // Face-to-face activity instance may have multiple sessions
            // which this user is signed up to so we use ORDER BY timefinish
            // DESC LIMIT 1 to get only the most recent.
            $sql = "SELECT f2su.id, timestart, timefinish
                      FROM {facetoface} f2f
                      JOIN {facetoface_sessions} f2s ON f2s.facetoface = f2f.id
                      JOIN {facetoface_sessions_dates} f2sd
                           ON f2sd.sessionid = f2s.id
                      JOIN {facetoface_signups} f2su ON f2su.sessionid = f2s.id
                      JOIN {facetoface_signups_status} f2sus
                           ON f2sus.signupid = f2su.id
                      JOIN {user} u ON u.id = f2su.userid
                     WHERE f2f.id = ? AND u.id = ? AND f2sus.superceded = 0
                           AND f2sus.statuscode = ?
                  ORDER BY timefinish DESC
                     LIMIT 1";

            // Only possible to get a date if fully attended
            $params = array($f2finstance, $user->id,
                            MDL_F2F_STATUS_FULLY_ATTENDED);
            $record = $DB->get_record_sql($sql, $params);

            if ($record) {
                $date = $record->$datefield;
            }
        }

        // Ensure that a date has been set.
        if (!empty($date)) {
            \mod_customcert\element_helper::render_content($pdf, $this, $this->get_date_format_string($date, $dateformat));
        }
    }

    /**
     * Render the element in html.
     *
     * This function is used to render the element when we are using the
     * drag and drop interface to position it.
     *
     * @return string the html
     */
    public function render_html() {
        // If there is no element data, we have nothing to display.
        if (empty($this->get_data())) {
            return;
        }

        // Decode the information stored in the database.
        $dateinfo = json_decode($this->get_data());
        $dateformat = $dateinfo->dateformat;

        return \mod_customcert\element_helper::render_html_content($this, $this->get_date_format_string(time(), $dateformat));
    }

    /**
     * Sets the data on the form when editing an element.
     *
     * @param \MoodleQuickForm $mform the edit_form instance
     */
    public function definition_after_data($mform) {
        // Set the item and format for this element.
        if (!empty($this->get_data())) {
            $dateinfo = json_decode($this->get_data());

            $element = $mform->getElement('f2finstance');
            $element->setValue($dateinfo->f2finstance);

            $element = $mform->getElement('datefield');
            $element->setValue($dateinfo->datefield);

            $element = $mform->getElement('dateformat');
            $element->setValue($dateinfo->dateformat);
        }

        parent::definition_after_data($mform);
    }

    /**
     * This function is responsible for handling the restoration process of the element.
     *
     * We will want to update the course module the date element is pointing to as it will
     * have changed in the course restore.
     *
     * @param \restore_customcert_activity_task $restore
     */
    public function after_restore($restore) {
        global $DB;

        $dateinfo = json_decode($this->get_data());
        if ($newitem = \restore_dbops::get_backup_ids_record($restore->get_restoreid(), 'course_module', $dateinfo->dateitem)) {
            $dateinfo->dateitem = $newitem->newitemid;
            $DB->set_field('customcert_elements', 'data', $this->save_unique_data($dateinfo), array('id' => $this->get_id()));
        }
    }

    /**
     * Helper function to return all the date formats.
     *
     * @return array the list of date formats
     */
    public static function get_date_formats() {
        // Hard-code date so users can see the difference between short dates with and without the leading zero.
        // Eg. 06/07/18 vs 6/07/18.
        $date = 1530849658;

        $suffix = self::get_ordinal_number_suffix(userdate($date, '%d'));

        $dateformats = [
            1 => userdate($date, '%B %d, %Y'),
            2 => userdate($date, '%B %d' . $suffix . ', %Y')
        ];

        $strdateformats = [
            'strftimedate',
            'strftimedatefullshort',
            'strftimedatefullshortwleadingzero',
            'strftimedateshort',
            'strftimedatetime',
            'strftimedatetimeshort',
            'strftimedatetimeshortwleadingzero',
            'strftimedaydate',
            'strftimedaydatetime',
            'strftimedayshort',
            'strftimedaytime',
            'strftimemonthyear',
            'strftimerecent',
            'strftimerecentfull',
            'strftimetime'
        ];

        foreach ($strdateformats as $strdateformat) {
            if ($strdateformat == 'strftimedatefullshortwleadingzero') {
                $dateformats[$strdateformat] = userdate($date, get_string('strftimedatefullshort', 'langconfig'), 99, false);
            } else if ($strdateformat == 'strftimedatetimeshortwleadingzero') {
                $dateformats[$strdateformat] = userdate($date, get_string('strftimedatetimeshort', 'langconfig'), 99, false);
            } else {
                $dateformats[$strdateformat] = userdate($date, get_string($strdateformat, 'langconfig'));
            }
        }

        return $dateformats;
    }

    /**
     * Returns the date in a readable format.
     *
     * @param int $date
     * @param string $dateformat
     * @return string
     */
    protected function get_date_format_string($date, $dateformat) {
        // Keeping for backwards compatibility.
        if (is_number($dateformat)) {
            switch ($dateformat) {
                case 1:
                    $certificatedate = userdate($date, '%B %d, %Y');
                    break;
                case 2:
                    $suffix = self::get_ordinal_number_suffix(userdate($date, '%d'));
                    $certificatedate = userdate($date, '%B %d' . $suffix . ', %Y');
                    break;
                case 3:
                    $certificatedate = userdate($date, '%d %B %Y');
                    break;
                case 4:
                    $certificatedate = userdate($date, '%B %Y');
                    break;
                default:
                    $certificatedate = userdate($date, get_string('strftimedate', 'langconfig'));
            }
        }

        // Ok, so we must have been passed the actual format in the lang file.
        if (!isset($certificatedate)) {
            if ($dateformat == 'strftimedatefullshortwleadingzero') {
                $certificatedate = userdate($date, get_string('strftimedatefullshort', 'langconfig'), 99, false);
            } else if ($dateformat == 'strftimedatetimeshortwleadingzero') {
                $certificatedate = userdate($date, get_string('strftimedatetimeshort', 'langconfig'), 99, false);
            } else {
                $certificatedate = userdate($date, get_string($dateformat, 'langconfig'));
            }
        }

        return $certificatedate;
    }

    /**
     * Helper function to return the suffix of the day of
     * the month, eg 'st' if it is the 1st of the month.
     *
     * @param int $day the day of the month
     * @return string the suffix.
     */
    protected static function get_ordinal_number_suffix($day) {
        if (!in_array(($day % 100), array(11, 12, 13))) {
            switch ($day % 10) {
                // Handle 1st, 2nd, 3rd.
                case 1:
                    return get_string('numbersuffix_st_as_in_first', 'customcertelement_facetofacedate');
                case 2:
                    return get_string('numbersuffix_nd_as_in_second', 'customcertelement_facetofacedate');
                case 3:
                    return get_string('numbersuffix_rd_as_in_third', 'customcertelement_facetofacedate');
            }
        }
        return 'th';
    }

    /**
     * Determine if this element is available to be added to a certificate.  It
     * is only available if there is a Face-to-Face activity in this course.
     * @return true if element is available, false otherwise.
     */
    public static function can_add() {
        global $COURSE, $DB;

        // Check if mod_facetoface plugin is installed.
        if (!function_exists('facetoface_add_instance')) {
            return false;
        }

        $sql = "SELECT ff.id, ff.name
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {facetoface} ff ON ff.id = cm.instance
                  JOIN {course} c ON c.id = cm.course
                 WHERE m.name = 'facetoface' AND c.id = ?";
        $params = array($COURSE->id);
        $records = $DB->get_records_sql($sql, $params);

        if (empty($records)) {
            return false;
        } else {
            return true;
        }
    }
}
