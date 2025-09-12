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

namespace tool_mergeusers\local\merger;

defined('MOODLE_INTERNAL') || die();

use coding_exception;
use dml_exception;
use stdClass;
use Exception;

/**
 * Course Completion Table Merger implementation.
 *
 * @package   tool_mergeusers
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_completions_table_merger extends generic_table_merger {

    /**
     * Merges course completions from the old user into the new user and moves old records into local recompletion.
     *
     * @param array $data array with the necessary data for merging records.
     * @param array $logs list of action performed.
     * @param array $errors list of error messages.
     * @return void
     * @throws dml_exception
     * @throws coding_exception
     */
    public function merge($data, &$logs, &$errors): void {
        global $DB;

        $fromid = $data['fromid'];
        $toid = $data['toid'];

        // Fetch all course completions for the old user.
        $fromcompletions = $DB->get_records('course_completions', ['userid' => $fromid]);

        foreach ($fromcompletions as $fromcompletion) {
            $logs[] = get_string('processingcompletion', 'tool_mergeusers', $fromcompletion->course);

            $tocompletion = $DB->get_record('course_completions', [
                'userid' => $toid,
                'course' => $fromcompletion->course,
            ]);

            if ($tocompletion) {
                $logs[] = get_string(
                    'existingfound',
                    'tool_mergeusers',
                    (object) [
                        'courseid' => $fromcompletion->course,
                        'toid' => $toid,
                    ]
                );

                $this->handle_existing_completion($fromcompletion, $tocompletion, $fromid, $toid, $logs, $errors);

                continue;
            }

            $this->transfer_completion($fromcompletion, $fromid, $toid, $logs, $errors);
        }

        $DB->delete_records('course_completions', ['userid' => $fromid]);

        $logs[] = get_string('completionremoved', 'tool_mergeusers', $fromid);
    }

    /**
     * Handles the logic for cases where both users have course completions.
     *
     * @param stdClass $fromcompletion Course completion record for the old user.
     * @param stdClass $tocompletion Course completion record for the new user.
     * @param int $fromid Old user ID.
     * @param int $toid New user ID.
     * @param string[] $logs List of actions performed.
     * @param string[] $errors List of error messages.
     * @return void
     */
    protected function handle_existing_completion($fromcompletion, $tocompletion,
            $fromid, $toid, &$logs, &$errors): void {
        global $DB;

        $logs[] = get_string('handlingconflict', 'tool_mergeusers', $fromcompletion->course);

        $isfromcomplete = (bool) $fromcompletion->timecompleted;
        $istocomplete = (bool) $tocompletion->timecompleted;
        $isfromnewer = ($fromcompletion->timecompleted > $tocompletion->timecompleted);

        if (!$isfromcomplete && !$istocomplete) {
            $logs[] = get_string('bothempty', 'tool_mergeusers', $fromcompletion->course);

            return;
        }

        if ($isfromcomplete && (!$istocomplete || $isfromnewer)) {
            $logs[] = get_string('existingtorecompletion', 'tool_mergeusers', $fromcompletion->course);

            $this->move_to_recompletion($tocompletion, $toid, $logs, $errors);

            $updatecompletion = clone $fromcompletion;
            $updatecompletion->id = $tocompletion->id;
            $updatecompletion->userid = $toid;
            $DB->update_record('course_completions', $updatecompletion);

            $logs[] = get_string('oldtorecompletion', 'tool_mergeusers', $fromcompletion->course);

            return;
        }

        $logs[] = get_string('oldtorecompletion', 'tool_mergeusers', $fromcompletion->course);

        $this->move_to_recompletion($fromcompletion, $fromid, $logs, $errors);
    }

    /**
     * Transfers a completion record from old user to new user.
     *
     * @param stdClass $completion Course completion record for the old user.
     * @param int $fromid Old user ID.
     * @param int $toid New user ID.
     * @param string[] $logs List of actions performed.
     * @param string[] $errors List of error messages.
     * @return void
     */
    protected function transfer_completion($completion, $fromid, $toid, &$logs, &$errors): void {
        global $DB;

        // Transfer completion from old user to new user.
        $completion->userid = $toid;
        $DB->update_record('course_completions', $completion);
        $logs[] = get_string(
            'completionupdated',
            'tool_mergeusers',
            (object)[
                'courseid' => $completion->course,
                'fromid' => $fromid,
                'toid' => $toid,
            ]
        );

        // Move fromid's record to recompletion if it has a timestamp.
        if (!empty($completion->timecompleted)) {
            $this->move_to_recompletion($completion, $fromid, $logs, $errors);
        }
    }

    /**
     * Moves the old course completion records to local recompletion.
     *
     * @param stdClass $completion Course completion object.
     * @param int $userid User id whose records are moved.
     * @param string[] $logs List of actions performed.
     * @param string[] $errors List of error messages.
     * @return void
     */
    protected function move_to_recompletion($completion, $userid, &$logs, &$errors): void {
        global $DB;

        $logs[] = get_string(
            'movingtorecompletion',
            'tool_mergeusers',
            (object) [
                'courseid' => $completion->course,
                'userid' => $userid,
            ]
        );

        $recompletiondata = new stdClass();
        $recompletiondata->userid = $userid;
        $recompletiondata->courseid = $completion->course;
        $recompletiondata->timecompleted = $completion->timecompleted;
        $recompletiondata->timemodified = time();

        try {
            $DB->insert_record('local_recompletion_cc', $recompletiondata);

            $logs[] = get_string(
                'recompletionmoved',
                'tool_mergeusers',
                (object) [
                    'courseid' => $completion->course,
                    'userid' => $userid,
                ]
            );

        } catch (Exception $e) {
            $errors[] = get_string(
                'recompletionerror',
                'tool_mergeusers',
                (object) [
                    'courseid' => $completion->course,
                    'userid' => $userid,
                    'error' => $e->getMessage(),
                ]
            );
        }
    }
}
