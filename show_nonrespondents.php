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
 *
 * @author Joseph Rézeau (copied from feedback plugin show_nonrespondents by original author Andreas Grabs)
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package    mod
 * @subpackage questionnaire
 *
 */

require_once("../../config.php");
require_once($CFG->dirroot.'/mod/questionnaire/locallib.php');
require_once($CFG->dirroot.'/mod/questionnaire/questionnaire.class.php');
require_once($CFG->libdir.'/tablelib.php');

// Get the params.
$id = required_param('id', PARAM_INT);
$subject = optional_param('subject', '', PARAM_CLEANHTML);
$message = optional_param('message', '', PARAM_CLEANHTML);
$format = optional_param('format', FORMAT_MOODLE, PARAM_INT);
$messageuser = optional_param_array('messageuser', false, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$perpage = optional_param('perpage', QUESTIONNAIRE_DEFAULT_PAGE_COUNT, PARAM_INT);  // How many per page.
$showall = optional_param('showall', false, PARAM_INT);  // Should we show all users?
$sid    = optional_param('sid', 0, PARAM_INT);
$qid    = optional_param('qid', 0, PARAM_INT);

$SESSION->questionnaire->current_tab = 'nonrespondents';

// Get the objects.

if ($id) {
    if (! $cm = get_coursemodule_from_id('questionnaire', $id)) {
        print_error('invalidcoursemodule');
    }

    if (! $course = $DB->get_record("course", array("id" => $cm->course))) {
        print_error('coursemisconf');
    }

    if (! $questionnaire = $DB->get_record("questionnaire", array("id" => $cm->instance))) {
        print_error('invalidcoursemodule');
    }
}

$questionnaire = new questionnaire($sid, $questionnaire, $course, $cm);
$resume = $questionnaire->resume;
$sid = $questionnaire->sid;
$url = new moodle_url('/mod/questionnaire/show_nonrespondents.php', array('id' => $cm->id));

$PAGE->set_url($url);

if (!$context = context_module::instance($cm->id)) {
        print_error('badcontext');
}

// We need the coursecontext to allow sending of mass mails.
if (!$coursecontext = context_course::instance($course->id)) {
        print_error('badcontext');
}

require_login($course, true, $cm);

if (($formdata = data_submitted()) AND !confirm_sesskey()) {
    print_error('invalidsesskey');
}

require_capability('mod/questionnaire:viewsingleresponse', $context);

if ($action == 'sendmessage') {

    $shortname = format_string($course->shortname,
                            true,
                            array('context' => context_course::instance($course->id)));
    $strquestionnaires = get_string("modulenameplural", "questionnaire");

    $htmlmessage = "<body id=\"email\">";

    $link1 = $CFG->wwwroot.'/mod/questionnaire/view.php?id='.$cm->id;

    $htmlmessage .= '<div class="navbar">'.
    '<a target="_blank" href="'.$link1.'">'.format_string($questionnaire->name, true).'</a>'.
    '</div>';

    $htmlmessage .= $message;
    $htmlmessage .= '</body>';

    $good = 1;

    if (is_array($messageuser)) {
        foreach ($messageuser as $userid) {
            $senduser = $DB->get_record('user', array('id' => $userid));
            $eventdata = new stdClass();
            $eventdata->name             = 'message';
            $eventdata->component        = 'mod_questionnaire';
            $eventdata->userfrom         = $USER;
            $eventdata->userto           = $senduser;
            $eventdata->subject          = $subject;
            $eventdata->fullmessage      = html_to_text($htmlmessage);
            $eventdata->fullmessageformat = FORMAT_PLAIN;
            $eventdata->fullmessagehtml  = $htmlmessage;
            $eventdata->smallmessage     = '';
            $good = $good && message_send($eventdata);
        }
        if (!empty($good)) {
            $msg = $OUTPUT->heading(get_string('messagedselectedusers'));
        } else {
            $msg = $OUTPUT->heading(get_string('messagedselectedusersfailed'));
        }

        $url = new moodle_url('/mod/questionnaire/view.php', array('id' => $cm->id));
        redirect($url, $msg, 4);
        exit;
    }
}

// Get the responses of given user.
// Print the page header.
$PAGE->navbar->add(get_string('show_nonrespondents', 'questionnaire'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_title(format_string($questionnaire->name));
echo $OUTPUT->header();

require('tabs.php');

// Print the main part of the page.
// Print the users with no responses
// Get the effective groupmode of this course and module.
if (isset($cm->groupmode) && empty($course->groupmodeforce)) {
    $groupmode = $cm->groupmode;
} else {
    $groupmode = $course->groupmode;
}

$groupselect = groups_print_activity_menu($cm, $url->out(), true);
$mygroupid = groups_get_activity_group($cm);

// Preparing the table for output.
$baseurl = new moodle_url('/mod/questionnaire/show_nonrespondents.php');
$baseurl->params(array('id' => $cm->id, 'showall' => $showall));

$tablecolumns = array('userpic', 'fullname');

// Extra columns copied from participants view.
$extrafields = get_extra_user_fields($context);
$tableheaders = array(get_string('userpic'), get_string('fullnameuser'));

if (in_array('email', $extrafields) || has_capability('moodle/course:viewhiddenuserfields', $context)) {
    $tablecolumns[] = 'email';
    $tableheaders[] = get_string('email');
}

if (!isset($hiddenfields['city'])) {
    $tablecolumns[] = 'city';
    $tableheaders[] = get_string('city');
}
if (!isset($hiddenfields['country'])) {
    $tablecolumns[] = 'country';
    $tableheaders[] = get_string('country');
}
if (!isset($hiddenfields['lastaccess'])) {
    $tablecolumns[] = 'lastaccess';
    $tableheaders[] = get_string('lastaccess');
}
if ($resume) {
    $tablecolumns[] = 'status';
    $tableheaders[] = get_string('status');
}
if (has_capability('moodle/course:bulkmessaging', $coursecontext)) {
    $tablecolumns[] = 'select';
    $tableheaders[] = get_string('select');
}

$table = new flexible_table('questionnaire-shownonrespondents-'.$course->id);

$table->define_columns($tablecolumns);
$table->define_headers($tableheaders);
$table->define_baseurl($baseurl);

$table->sortable(true, 'lastname', SORT_DESC);
$table->set_attribute('cellspacing', '0');
$table->set_attribute('id', 'showentrytable');
$table->set_attribute('class', 'flexible generaltable generalbox');
$table->set_control_variables(array(
            TABLE_VAR_SORT    => 'ssort',
            TABLE_VAR_IFIRST  => 'sifirst',
            TABLE_VAR_ILAST   => 'silast',
            TABLE_VAR_PAGE    => 'spage'
            ));

$table->no_sorting('status');
$table->no_sorting('select');

$table->setup();

if ($table->get_sql_sort()) {
    $sort = $table->get_sql_sort();
} else {
    $sort = '';
}

// Get students in conjunction with groupmode.
if ($groupmode > 0) {
    if ($mygroupid > 0) {
        $usedgroupid = $mygroupid;
    } else {
        $usedgroupid = false;
    }
} else {
    $usedgroupid = false;
}

$matchcount = questionnaire_count_incomplete_users($cm, $sid, $usedgroupid);

$table->initialbars(false);

if ($showall) {
    $startpage = false;
    $pagecount = false;
} else {
    $table->pagesize($perpage, $matchcount);
    $startpage = $table->get_page_start();
    $pagecount = $table->get_page_size();
}

$students = questionnaire_get_incomplete_users($cm, $sid, $usedgroupid, $sort, $startpage, $pagecount);
// Viewreports-start.
// Print the list of students.

echo isset($groupselect) ? $groupselect : '';
echo '<div class="clearer"></div>';
// JR replaced middle with left align echo $OUTPUT->box_start('mdl-align');.
echo $OUTPUT->box_start('left-align');

$countries = get_string_manager()->get_list_of_countries();

$strnever = get_string('never');

$datestring = new stdClass();
$datestring->year  = get_string('year');
$datestring->years = get_string('years');
$datestring->day   = get_string('day');
$datestring->days  = get_string('days');
$datestring->hour  = get_string('hour');
$datestring->hours = get_string('hours');
$datestring->min   = get_string('min');
$datestring->mins  = get_string('mins');
$datestring->sec   = get_string('sec');
$datestring->secs  = get_string('secs');

if (!$students) {
    echo $OUTPUT->notification(get_string('noexistingparticipants', 'enrol'));
} else {
    echo print_string('non_respondents', 'questionnaire');
    echo ' ('.$matchcount.')<hr />';
    if (has_capability('moodle/course:bulkmessaging', $coursecontext)) {
        echo '<form class="mform" action="show_nonrespondents.php" method="post" id="questionnaire_sendmessageform">';
        foreach ($students as $student) {
            $user = $DB->get_record('user', array('id' => $student));
            // Userpicture and link to the profilepage.
            $profileurl = $CFG->wwwroot.'/user/view.php?id='.$user->id.'&amp;course='.$course->id;
            $profilelink = '<strong><a href="'.$profileurl.'">'.fullname($user).'</a></strong>';
            $data = array ($OUTPUT->user_picture($user, array('courseid' => $course->id)), $profilelink);
            if (in_array('email', $tablecolumns)) {
                $data[] = $user->email;
            }
            if (!isset($hiddenfields['city'])) {
                $data[] = $user->city;
            }
            if (!isset($hiddenfields['country'])) {
                $data[] = (!empty($user->country)) ? $countries[$user->country] : '';
            }
            if ($user->lastaccess) {
                $lastaccess = format_time(time() - $user->lastaccess, $datestring);
            } else {
                $lastaccess = get_string('never');
            }
            $data[] = $lastaccess;

            // If questionnaire is set to "resume", look for saved (not completed) responses
            // we use the alt attribute of the checkboxes to store the started/not started value!
            $checkboxaltvalue = '';
            if ($resume) {
                if ($DB->get_record('questionnaire_response', array('survey_id' => $sid,
                                'username' => $student, 'complete' => 'n')) ) {
                    $data[] = get_string('started', 'questionnaire');
                    $checkboxaltvalue = 1;
                } else {
                    $data[] = get_string('not_started', 'questionnaire');
                    $checkboxaltvalue = 0;
                }
            }
            $data[] = '<input type="checkbox" class="usercheckbox" name="messageuser[]" value="'.
                $user->id.'" alt="'.$checkboxaltvalue.'" />';
            $table->add_data($data);
        }

        $table->print_html();

        $allurl = new moodle_url($baseurl);

        if ($showall) {
            $allurl->param('showall', 0);
            echo $OUTPUT->container(html_writer::link($allurl, get_string('showperpage', '', QUESTIONNAIRE_DEFAULT_PAGE_COUNT)),
                                        array(), 'showall');

        } else if ($matchcount > 0 && $perpage < $matchcount) {
            $allurl->param('showall', 1);
            echo $OUTPUT->container(html_writer::link($allurl, get_string('showall', '', $matchcount)), array(), 'showall');
        }

        echo $OUTPUT->box_start('mdl-align'); // Selection buttons container.
        echo '<div class="buttons">';
        echo '<input type="button" id="checkall" value="'.get_string('selectall').'" /> ';
        echo '<input type="button" id="checknone" value="'.get_string('deselectall').'" /> ';
        if ($resume) {
            if ($perpage >= $matchcount) {
                echo '<input type="button" id="checkstarted" value="'.get_string('checkstarted', 'questionnaire').'" />'."\n";
                echo '<input type="button" id="checknotstarted" value="'.get_string('checknotstarted', 'questionnaire').'" />'."\n";
            }
        }
        echo '</div>';
        echo $OUTPUT->box_end(); // Selection buttons container.

        // Message editor.
        // Prepare data.
        $usehtmleditor = can_use_html_editor();
        echo '<fieldset class="clearfix">';
        echo '<legend class="ftoggler">'.get_string('send_message', 'questionnaire').'</legend>';
        $id = 'message' . '_id';
        $subjecteditor = '&nbsp;&nbsp;&nbsp;<input type="text" id="questionnaire_subject" size="65"
            maxlength="255" name="subject" value="'.$subject.'" />';
        $format = '';
        if ($usehtmleditor) {
            $editor = editors_get_preferred_editor();
            $editor->use_editor($id, questionnaire_get_editor_options($context));
            $texteditor = html_writer::tag('div', html_writer::tag('textarea', '',
                            array('id' => $id, 'name' => "message", '', '')));
            echo '<input type="hidden" name="format" value="'.FORMAT_HTML.'" />';
        } else {
            $texteditor = html_writer::tag('div', html_writer::tag('textarea', '',
                            array('id' => $id, 'name' => "message", 'rows' => 10, 'cols' => 65)));
            $formatlabel = '<label for="menuformat" class="accesshide">'. get_string('format') .'</label>';
            $format = '&nbsp;&nbsp;&nbsp;'.html_writer::select(format_text_menu(), "format", $format, "");
        }

        // Print editor.
        $table = new html_table();
        $table->align = array('left', 'left');
        $table->data[] = array( '<strong>'.get_string('subject', 'questionnaire').'</strong>', $subjecteditor);
        $table->data[] = array('<strong>'.get_string('messagebody').'</strong>', $texteditor);

        if (!$usehtmleditor) {
            $table->data[] = array($formatlabel, $format);
        }

        echo html_writer::table($table);

        // Send button.
        echo $OUTPUT->box_start('mdl-left');
        echo '<div class="buttons">';
        echo '<input type="submit" name="send_message" value="'.get_string('send', 'questionnaire').'" />';
        echo '</div>';
        echo $OUTPUT->box_end();

        echo '<input type="hidden" name="sesskey" value="'.sesskey().'" />';
        echo '<input type="hidden" name="action" value="sendmessage" />';
        echo '<input type="hidden" name="id" value="'.$cm->id.'" />';

        echo '</fieldset>';
        echo '</form>';

        // Include the needed js.
        $module = array('name' => 'mod_questionnaire', 'fullpath' => '/mod/questionnaire/module.js');
        $PAGE->requires->js_init_call('M.mod_questionnaire.init_sendmessage', null, false, $module);
    }
}
echo $OUTPUT->box_end();

// Finish the page.

echo $OUTPUT->footer();