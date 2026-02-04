
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
 * Export attendance sessions - FIXED: summary always at top + proper main header below
 *
 * @package mod_attendance
 * @copyright 2011 Artem Andreev <andreev.artem@gmail.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
$PAGE->force_settings_menu(true);
$PAGE->set_cacheable(true);
$PAGE->navbar->add(get_string('export', 'attendance'));

$formparams = ['course' => $course, 'cm' => $cm, 'modcontext' => $context];
$mform = new mod_attendance\form\export($att->url_export(), $formparams);

if ($formdata = $mform->get_data()) {
    \core_php_time_limit::raise();
    raise_memory_limit(MEMORY_HUGE);

    $pageparams = new mod_attendance_page_with_filter_controls();
    $pageparams->init($cm);
    $pageparams->page = 0;
    $pageparams->group = $formdata->group;
    $pageparams->set_current_sesstype($formdata->group ? $formdata->group : mod_attendance_page_with_filter_controls::SESSTYPE_ALL);

    if (isset($formdata->includeallsessions)) {
        if (isset($formdata->includenottaken)) {
            $pageparams->view = ATT_VIEW_ALL;
        } else {
            $pageparams->view = ATT_VIEW_ALLPAST;
            $pageparams->curdate = time();
        }
        $pageparams->init_start_end_date();
    } else {
        $pageparams->startdate = $formdata->sessionstartdate;
        $pageparams->enddate = $formdata->sessionenddate;
    }

    if ($formdata->selectedusers) {
        $pageparams->userids = $formdata->users;
    }

    $att->pageparams = $pageparams;
    $reportdata = new mod_attendance\output\report_data($att);

    if ($reportdata->users) {
        $filename = clean_filename($course->shortname . '_' .
            get_string('modulenameplural', 'attendance') .
            '_' . userdate(time(), '%Y%m%d-%H%M'));

        $group = $formdata->group ? $reportdata->groups[$formdata->group] : 0;
        $data = new stdClass();
        $data->tabhead = [];
        $data->course = $att->course->fullname;
        $data->group = $group ? $group->name : get_string('allparticipants');
        $data->tabhead[] = get_string('lastname');
        $data->tabhead[] = get_string('firstname');
        $groupmode = groups_get_activity_groupmode($cm, $course);
        if (!empty($groupmode)) {
            $data->tabhead[] = get_string('groups');
        }

        require_once($CFG->dirroot . '/user/profile/lib.php');
        $customfields = profile_get_custom_fields(false);

        if (isset($formdata->ident)) {
            foreach (array_keys($formdata->ident) as $opt) {
                if ($opt == 'id') {
                    $data->tabhead[] = get_string('studentid', 'attendance');
                } else if (in_array($opt, array_column($customfields, 'shortname'))) {
                    foreach ($customfields as $customfield) {
                        if ($opt == $customfield->shortname) {
                            $data->tabhead[] = format_string($customfield->name, true, ['context' => $context]);
                        }
                    }
                } else {
                    $data->tabhead[] = get_string($opt);
                }
            }
        }

        // MERGE SESSIONS BY TIME SLOT
        $timeslots = [];
        foreach ($reportdata->sessions as $sess) {
            $key = $sess->sessdate . '_' . $sess->duration;
            if (!isset($timeslots[$key])) {
                $timeslots[$key] = [
                    'sessdate' => $sess->sessdate,
                    'duration' => $sess->duration,
                    'sessions' => [],
                ];
            }
            $timeslots[$key]['sessions'][$sess->groupid] = $sess;
        }

        // Session headers (merged)
        if (!empty($timeslots)) {
            foreach ($timeslots as $slotkey => $slot) {
                $text = userdate($slot['sessdate'], get_string('strftimedmyhm', 'attendance'));
                $data->tabhead[] = $text;
                if (isset($formdata->includeremarks)) {
                    $data->tabhead[] = '';
                }
            }
        } else {
            throw new moodle_exception('sessionsnotfound', 'mod_attendance', $att->url_manage());
        }

        // Status columns
        $setnumber = -1;
        foreach ($reportdata->statuses as $sts) {
            if ($sts->setnumber != $setnumber) {
                $setnumber = $sts->setnumber;
            }
            $data->tabhead[] = $sts->acronym;
        }
        $data->tabhead[] = get_string('takensessions', 'attendance');
        $data->tabhead[] = get_string('points', 'attendance');
        $data->tabhead[] = get_string('percentage', 'attendance');

        // --- Build summary-only header: Summary |  | Class Size | sessions ---
        $summaryheader = [];

        // First column label
        $summaryheader[] = get_string('summary', 'attendance');

        // Fill unused columns with empty space
        if (!empty($groupmode)) {
            $summaryheader[] = ' ';
        }


        // Number of leading columns before sessions
        $leadcols = count($summaryheader);

        // Add Class size column header
        $summaryheader[] = 'Class size';

        // Add session headers only
        $sessioncols = [];
        foreach ($timeslots as $slot) {
            $sessioncols[] = userdate($slot['sessdate'], get_string('strftimedmyhm', 'attendance'));
            if (isset($formdata->includeremarks)) {
                $sessioncols[] = '';
            }
        }

        $summaryheader = array_merge($summaryheader, $sessioncols);

        // Pad to full width so column alignment stays intact
        $summaryheader = array_pad($summaryheader, count($data->tabhead), '');


        $data->table = [];
        $group_p_counts = [];
        $all_groups = [];

        // ---- SORT USERS BY GROUP (A-Z), THEN LAST NAME (A-Z) ----
        foreach ($reportdata->users as $u) {
            $groupsraw = groups_get_all_groups($course->id, $u->id, 0, 'g.name');
            $groupnames = [];

            if ($groupsraw) {
                foreach ($groupsraw as $g) {
                    $groupnames[] = $g->name;
                }
                sort($groupnames, SORT_NATURAL | SORT_FLAG_CASE);
                $u->_sortgroup = $groupnames[0]; // primary group
            } else {
                $u->_sortgroup = ''; // users with no group go first, case should not apply to Sentinel
            }
        }

        usort($reportdata->users, function($a, $b) {
            // 1) Group name
            $gcmp = strnatcasecmp($a->_sortgroup, $b->_sortgroup);
            if ($gcmp !== 0) {
                return $gcmp;
            }

            // 2) Last name
            return strnatcasecmp($a->lastname, $b->lastname);
        });


        foreach ($reportdata->users as $user) {
            profile_load_custom_fields($user);

            $groupsraw = groups_get_all_groups($course->id, $user->id, 0, 'g.name');
            $user_groups = [];
            foreach ($groupsraw as $g) {
                $user_groups[] = $g->name;
                if (!in_array($g->name, $all_groups)) {
                    $all_groups[] = $g->name;
                }
            }

            $row = [$user->lastname, $user->firstname];
            if (!empty($groupmode)) {
                $row[] = implode(', ', $user_groups);
            }

            if (isset($formdata->ident)) {
                foreach (array_keys($formdata->ident) as $opt) {
                    if (in_array($opt, array_column($customfields, 'shortname'))) {
                        $row[] = isset($user->profile[$opt]) ? format_string($user->profile[$opt], true, ['context' => $context]) : '';
                        continue;
                    }
                    $row[] = $user->$opt ?? '';
                }
            }

            foreach ($timeslots as $slotkey => $slot) {
                $cell = '';
                $remark = '';
                $user_group_ids = array_keys(groups_get_all_groups($course->id, $user->id, 0, 'g.id'));
                $found_session = null;

                if (isset($slot['sessions'][0])) {
                    $found_session = $slot['sessions'][0];
                } else {
                    foreach ($user_group_ids as $gid) {
                        if (isset($slot['sessions'][$gid])) {
                            $found_session = $slot['sessions'][$gid];
                            break;
                        }
                    }
                }

                if ($found_session) {
                    $log = $DB->get_record('attendance_log', [
                        'studentid' => $user->id,
                        'sessionid' => $found_session->id
                    ]);
                    if ($log) {
                        $status = $DB->get_record('attendance_statuses', ['id' => $log->statusid]);
                        $cell = $status ? $status->acronym : '';
                        if (isset($formdata->includeremarks)) {
                            $remark = $log->remarks ?? '';
                        }
                    }
                }

                $row[] = $cell;
                if (isset($formdata->includeremarks)) {
                    $row[] = $remark;
                }

                if ($cell === 'P') {
                    foreach ($user_groups as $gname) {
                        if (!isset($group_p_counts[$gname])) {
                            $group_p_counts[$gname] = [];
                        }
                        $group_p_counts[$gname][$slotkey] = ($group_p_counts[$gname][$slotkey] ?? 0) + 1;
                    }
                }
            }

            $usersummary = $reportdata->summary->get_taken_sessions_summary_for($user->id);
            foreach ($reportdata->statuses as $sts) {
                $set = $sts->setnumber;
                $acronym = $sts->acronym;
                $row[] = isset($usersummary->userstakensessionsbyacronym[$set][$acronym])
                    ? $usersummary->userstakensessionsbyacronym[$set][$acronym]
                    : 0;
            }
            $row[] = $usersummary->numtakensessions;
            $row[] = $usersummary->pointssessionscompleted;
            $row[] = format_float($usersummary->takensessionspercentage * 100);

            $data->table[] = $row;
        }

        $group_class_sizes = [];

        // Count students per group
        foreach ($reportdata->users as $user) {
            $groupsraw = groups_get_all_groups($course->id, $user->id, 0, 'g.name');
            foreach ($groupsraw as $g) {
                if (!isset($group_class_sizes[$g->name])) {
                    $group_class_sizes[$g->name] = 0;
                }
                $group_class_sizes[$g->name]++;
            }
        }


        // === SUMMARY TABLE (separate block at the top) ===
        sort($all_groups);

        $summary_rows = [];

        foreach ($all_groups as $gname) {
            $row = array_fill(0, count($data->tabhead), '');
            $row[0] = $gname;

            // Column index where summary data starts
            $col = $leadcols;

            // Class size column
            $row[$col] = $group_class_sizes[$gname] ?? 0;
            $col++;

            // Session attendance counts
            foreach ($timeslots as $slotkey => $slot) {
                $row[$col] = $group_p_counts[$gname][$slotkey] ?? 0;
                $col += isset($formdata->includeremarks) ? 2 : 1;
            }

            $summary_rows[] = $row;
        }


        $total_row = array_fill(0, count($data->tabhead), '');
        $total_row[0] = 'Total';
        
        $col = $leadcols;
        
        // Total class size
        $total_row[$col] = array_sum($group_class_sizes);
        $col++;
        
        // Session totals
        foreach ($timeslots as $slotkey => $slot) {
            $sum = 0;
            foreach ($all_groups as $gname) {
                $sum += $group_p_counts[$gname][$slotkey] ?? 0;
            }
            $total_row[$col] = $sum;
            $col += isset($formdata->includeremarks) ? 2 : 1;
        }
        
        $summary_rows[] = $total_row;
        

        // Blank separator row
        $summary_rows[] = array_fill(0, count($data->tabhead), '');

        // Build a repeated header row for the student table
        $repeatheader = $data->tabhead;
        $repeatheader['_repeatheader'] = true;


        // Prepend summary + repeated header before student rows
        $data->table = array_merge(
            $summary_rows,        // SUMMARY + group rows + totals
            [array_fill(0, count($data->tabhead), '')], // spacer
            [$repeatheader],      // bold student header
            $data->table          // student rows
        );
        
        
        $data->tabhead = $summaryheader;

        // Export
        if ($formdata->format === 'text') {
            attendance_exporttocsv($data, $filename);
        } else {
            attendance_exporttotableed($data, $filename, $formdata->format);
        }
        exit;
    } else {
        throw new moodle_exception('studentsnotfound', 'mod_attendance', $att->url_manage());
    }
}

$output = $PAGE->get_renderer('mod_attendance');
echo $output->header();
$mform->display();
echo $output->footer();