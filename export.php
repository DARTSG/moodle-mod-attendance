<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// BUT WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.
/**
 * Export attendance sessions - ULTRA ROBUST (multi-session lookup per time slot)
 */
define('NO_OUTPUT_BUFFERING', true);
require_once(dirname(__FILE__) . '/../../config.php');
require_once(dirname(__FILE__) . '/locallib.php');
require_once($CFG->libdir . '/formslib.php');

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('attendance', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$att = $DB->get_record('attendance', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/attendance:export', $context);

$att = new mod_attendance_structure($att, $cm, $course, $context);

$PAGE->set_url($att->url_export());
$PAGE->set_title($course->shortname . ": " . $att->name);
$PAGE->set_heading($course->fullname);

$formparams = ['course' => $course, 'cm' => $cm, 'modcontext' => $context];
$mform = new mod_attendance\form\export($att->url_export(), $formparams);

if ($formdata = $mform->get_data()) {
    \core_php_time_limit::raise();
    raise_memory_limit(MEMORY_HUGE);

    $pageparams = new mod_attendance_page_with_filter_controls();
    $pageparams->init($cm);
    $pageparams->page = 0;
    $pageparams->group = $formdata->group;

    if (isset($formdata->includeallsessions)) {
        $pageparams->view = isset($formdata->includenottaken) ? ATT_VIEW_ALL : ATT_VIEW_ALLPAST;
        $pageparams->init_start_end_date();
    } else {
        $pageparams->startdate = $formdata->sessionstartdate;
        $pageparams->enddate = $formdata->sessionenddate;
    }

    if ($formdata->selectedusers) $pageparams->userids = $formdata->users;

    $att->pageparams = $pageparams;
    $reportdata = new mod_attendance\output\report_data($att);

    if (empty($reportdata->users)) {
        throw new moodle_exception('studentsnotfound', 'mod_attendance', $att->url_manage());
    }

    $filename = clean_filename($course->shortname . '_attendance_' . userdate(time(), '%Y%m%d-%H%M'));

    $data = new stdClass();
    $data->tabhead = [get_string('lastname'), get_string('firstname'), get_string('groups')];

    require_once($CFG->dirroot . '/user/profile/lib.php');
    if (isset($formdata->ident)) {
        foreach (array_keys($formdata->ident) as $opt) {
            $data->tabhead[] = ($opt == 'id') ? get_string('studentid', 'attendance') : get_string($opt);
        }
    }

    // Build merged timeslots
    $timeslots = [];
    foreach ($reportdata->sessions as $sess) {
        $key = $sess->sessdate . '_' . $sess->duration;
        if (!isset($timeslots[$key])) {
            $timeslots[$key] = userdate($sess->sessdate, get_string('strftimedmyhm', 'attendance'));
        }
    }

    foreach ($timeslots as $date) {
        $data->tabhead[] = $date;
    }

    foreach ($reportdata->statuses as $sts) {
        $data->tabhead[] = $sts->acronym;
    }
    $data->tabhead[] = get_string('takensessions', 'attendance');
    $data->tabhead[] = get_string('points', 'attendance');
    $data->tabhead[] = '%P';
    $data->tabhead[] = '%E';
    $data->tabhead[] = '%A';
    $data->tabhead[] = '%P+E';

    $full_col_count = count($data->tabhead);

    $data->table = [];
    $group_p_counts = [];
    $group_e_counts = [];
    $all_groups = [];

    $users_with_group = [];
    foreach ($reportdata->users as $user) {
        profile_load_custom_fields($user);
        $groupsraw = groups_get_all_groups($course->id, $user->id, 0, 'g.name');
        $primary_group = !empty($groupsraw) ? reset($groupsraw)->name : 'No Group';
        if (!in_array($primary_group, $all_groups)) $all_groups[] = $primary_group;
        $user->primary_group = $primary_group;
        $users_with_group[] = $user;
    }

    usort($users_with_group, function($a, $b) {
        $cmp = strcasecmp($a->primary_group, $b->primary_group);
        return $cmp !== 0 ? $cmp : strcasecmp($a->lastname, $b->lastname);
    });

    foreach ($users_with_group as $user) {
        $row = [$user->lastname, $user->firstname, $user->primary_group];

        if (isset($formdata->ident)) {
            foreach (array_keys($formdata->ident) as $opt) {
                $row[] = $user->profile[$opt] ?? $user->$opt ?? '';
            }
        }

        // === ULTRA ROBUST LOOKUP: check ALL sessions matching the time slot ===
        $clean_cells = [];
        $p_count = 0; $e_count = 0; $a_count = 0; $total_taken = 0;

        foreach ($timeslots as $slotkey => $date) {
            $cell = '';

            // Loop through ALL sessions to find one that matches this time slot AND has a log for this student
            foreach ($reportdata->sessions as $sess) {
                if (($sess->sessdate . '_' . $sess->duration) === $slotkey) {
                    $log = $DB->get_record('attendance_log', [
                        'studentid' => $user->id,
                        'sessionid' => $sess->id
                    ]);
                    if ($log) {
                        $status = $DB->get_record('attendance_statuses', ['id' => $log->statusid]);
                        $cell = $status ? $status->acronym : '';
                        break;   // found a valid log → use it and stop
                    }
                }
            }

            $clean_cells[] = $cell;

            if ($cell === 'P') { $p_count++; $total_taken++; $group_p_counts[$user->primary_group][$slotkey] = ($group_p_counts[$user->primary_group][$slotkey] ?? 0) + 1; }
            if ($cell === 'E') { $e_count++; $total_taken++; $group_e_counts[$user->primary_group][$slotkey] = ($group_e_counts[$user->primary_group][$slotkey] ?? 0) + 1; }
            if ($cell === 'A') { $a_count++; $total_taken++; }
        }

        $row = array_merge($row, $clean_cells);

        // Original summary columns
        $usersummary = $reportdata->summary->get_taken_sessions_summary_for($user->id);
        foreach ($reportdata->statuses as $sts) {
            $row[] = $usersummary->userstakensessionsbyacronym[$sts->setnumber][$sts->acronym] ?? 0;
        }
        $row[] = $usersummary->numtakensessions;
        $row[] = $usersummary->pointssessionscompleted;

        // Four percentage columns
        $perc_p = $total_taken > 0 ? round(($p_count / $total_taken) * 100) : 0;
        $perc_e = $total_taken > 0 ? round(($e_count / $total_taken) * 100) : 0;
        $perc_a = $total_taken > 0 ? round(($a_count / $total_taken) * 100) : 0;
        $perc_pe = $total_taken > 0 ? round((($p_count + $e_count) / $total_taken) * 100) : 0;

        $row[] = $perc_p . '%';
        $row[] = $perc_e . '%';
        $row[] = $perc_a . '%';
        $row[] = $perc_pe . '%';

        $data->table[] = $row;
    }

    // Summary tables (P and E) - same as before
    sort($all_groups);
    $data->summaryrows = [];

    $left_padding = 3;

    // P Summary
    $p_header = array_fill(0, $full_col_count, '');
    $p_header[0] = 'SUMMARY: Number of Present (P) per group';
    $col = $left_padding;
    foreach ($timeslots as $date) { $p_header[$col++] = $date; }
    $data->summaryrows[] = $p_header;

    foreach ($all_groups as $gname) {
        $row = array_fill(0, $left_padding, '');
        $row[0] = $gname;
        foreach ($timeslots as $slotkey => $date) {
            $row[] = $group_p_counts[$gname][$slotkey] ?? 0;
        }
        $data->summaryrows[] = array_pad($row, $full_col_count, '');
    }
    $total_p = array_fill(0, $left_padding, '');
    $total_p[0] = 'Total P';
    foreach ($timeslots as $slotkey => $date) {
        $sum = 0;
        foreach ($all_groups as $g) $sum += $group_p_counts[$g][$slotkey] ?? 0;
        $total_p[] = $sum;
    }
    $data->summaryrows[] = array_pad($total_p, $full_col_count, '');
    $data->summaryrows[] = array_fill(0, $full_col_count, '');

    // E Summary
    $e_header = array_fill(0, $full_col_count, '');
    $e_header[0] = 'SUMMARY: Number of Exempted (E) per group';
    $col = $left_padding;
    foreach ($timeslots as $date) { $e_header[$col++] = $date; }
    $data->summaryrows[] = $e_header;

    foreach ($all_groups as $gname) {
        $row = array_fill(0, $left_padding, '');
        $row[0] = $gname;
        foreach ($timeslots as $slotkey => $date) {
            $row[] = $group_e_counts[$gname][$slotkey] ?? 0;
        }
        $data->summaryrows[] = array_pad($row, $full_col_count, '');
    }
    $total_e = array_fill(0, $left_padding, '');
    $total_e[0] = 'Total E';
    foreach ($timeslots as $slotkey => $date) {
        $sum = 0;
        foreach ($all_groups as $g) $sum += $group_e_counts[$g][$slotkey] ?? 0;
        $total_e[] = $sum;
    }
    $data->summaryrows[] = array_pad($total_e, $full_col_count, '');
    $data->summaryrows[] = array_fill(0, $full_col_count, '');

    if ($formdata->format === 'text') {
        attendance_exporttocsv($data, $filename);
    } else {
        attendance_exporttotableed($data, $filename, $formdata->format);
    }
    exit;
}

$output = $PAGE->get_renderer('mod_attendance');
echo $output->header();
$mform->display();
echo $output->footer();