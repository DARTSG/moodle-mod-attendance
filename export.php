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
 * Export attendance sessions - MODIFIED: merged sessions + sorted by group (A-Z) then lastname (A-Z)
 * + TOTAL ROW: shows "Status: count/total (percentage)" vertically per session column
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

        // Group sessions by time slot
        $timeslots = [];
        foreach ($reportdata->sessions as $sess) {
            $key = $sess->sessdate . '_' . $sess->duration;
            if (!isset($timeslots[$key])) {
                $timeslots[$key] = [
                    'sessdate' => $sess->sessdate,
                    'duration' => $sess->duration,
                    'groups' => [],
                    'sessions' => [],
                ];
            }
            if ($sess->groupid && !empty($reportdata->groups[$sess->groupid])) {
                $timeslots[$key]['groups'][] = $reportdata->groups[$sess->groupid]->name;
            } else {
                $timeslots[$key]['groups'][] = get_string('commonsession', 'attendance');
            }
            $timeslots[$key]['sessions'][$sess->groupid] = $sess;
        }

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

        $status_counts_per_slot = [];
        foreach (array_keys($timeslots) as $slotkey) {
            $status_counts_per_slot[$slotkey] = [];
            foreach ($reportdata->statuses as $sts) {
                $status_counts_per_slot[$slotkey][$sts->acronym] = 0;
            }
        }

        $i = 0;
        $data->table = [];

        $users_with_group = [];
        foreach ($reportdata->users as $user) {
            profile_load_custom_fields($user);
            $groupnames = [];
            $groupsraw = groups_get_all_groups($course->id, $user->id, 0, 'g.name');
            foreach ($groupsraw as $g) {
                $groupnames[] = $g->name;
            }
            sort($groupnames);
            $sortgroup = !empty($groupnames) ? $groupnames[0] : 'No Group';
            $sortlastname = $user->lastname ?? '';
            $users_with_group[] = [
                'user' => $user,
                'sortgroup' => $sortgroup,
                'sortlastname' => $sortlastname,
                'grouptext' => implode(', ', $groupnames),
            ];
        }

        usort($users_with_group, function($a, $b) {
            $group_cmp = strcasecmp($a['sortgroup'], $b['sortgroup']);
            if ($group_cmp !== 0) return $group_cmp;
            return strcasecmp($a['sortlastname'], $b['sortlastname']);
        });

        foreach ($users_with_group as $item) {
            $user = $item['user'];
            $grouptext = $item['grouptext'];
            $data->table[$i][] = $user->lastname;
            $data->table[$i][] = $user->firstname;
            if (!empty($groupmode)) {
                $data->table[$i][] = $grouptext;
            }
            if (isset($formdata->ident)) {
                foreach (array_keys($formdata->ident) as $opt) {
                    if (in_array($opt, array_column($customfields, 'shortname'))) {
                        $data->table[$i][] = isset($user->profile[$opt]) ? format_string($user->profile[$opt], true, ['context' => $context]) : '';
                        continue;
                    }
                    $data->table[$i][] = $user->$opt ?? '';
                }
            }

            foreach ($timeslots as $slotkey => $slot) {
                $cell = '';
                $remark = '';
                $user_groups = groups_get_all_groups($course->id, $user->id, 0, 'g.id');
                $user_group_ids = array_keys($user_groups);
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
                $data->table[$i][] = $cell;
                if (isset($formdata->includeremarks)) {
                    $data->table[$i][] = $remark;
                }

                if ($cell !== '') {
                    $status_counts_per_slot[$slotkey][$cell]++;
                }
            }

            $usersummary = $reportdata->summary->get_taken_sessions_summary_for($user->id);
            foreach ($reportdata->statuses as $sts) {
                $set = $sts->setnumber;
                $acronym = $sts->acronym;
                $data->table[$i][] = isset($usersummary->userstakensessionsbyacronym[$set][$acronym])
                    ? $usersummary->userstakensessionsbyacronym[$set][$acronym]
                    : 0;
            }
            $data->table[$i][] = $usersummary->numtakensessions;
            $data->table[$i][] = $usersummary->pointssessionscompleted;
            $data->table[$i][] = format_float($usersummary->takensessionspercentage * 100);

            $i++;
        }

        // === ADD TOTAL ROW ===
        $total_row = array_fill(0, count($data->tabhead), '');

        $total_row[0] = 'Total';

        $session_start_col = 2;
        if (!empty($groupmode)) $session_start_col++;
        if (isset($formdata->ident)) $session_start_col += count(array_keys($formdata->ident));

        $col = $session_start_col;

        foreach ($timeslots as $slotkey => $slot) {
            $summary_parts = [];
            $total_marked = array_sum($status_counts_per_slot[$slotkey] ?? []);

            if ($total_marked === 0) {
                $summary_parts[] = '0/0 (0%)';
            } else {
                foreach ($reportdata->statuses as $sts) {
                    $ac = $sts->acronym;
                    $cnt = $status_counts_per_slot[$slotkey][$ac] ?? 0;
                    if ($cnt > 0) {  // only show statuses that actually occurred
                        $perc = round(($cnt / $total_marked) * 100);
                        $summary_parts[] = $ac . ': ' . $cnt . '/' . $total_marked . ' (' . $perc . '%)';
                    }
                }
            }

            $cell_content = implode("\n", $summary_parts);
            $total_row[$col] = $cell_content;

            $col++;
            if (isset($formdata->includeremarks)) {
                $col++;
            }
        }

        $data->table[] = $total_row;

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