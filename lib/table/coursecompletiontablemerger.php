<?php

// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Course Completion Table Merger implementation.
 *
 * @package    tool_mergeusers
 * @subpackage mergeusers
 */

defined('MOODLE_INTERNAL') || die();

class CourseCompletionTableMerger extends GenericTableMerger {

    /**
     * @var string current defined action.
     */
    private mixed $action;

    public function __construct()
    {
        $this->action = get_config('tool_mergeusers', 'coursecompletionaction');
    }

    /**
     * Merges course completions from the old user into the new user and moves old records into local recompletion.
     *
     * @param array $data array with the necessary data for merging records.
     * @param string[] $actionLog list of actions performed.
     * @param string[] $errorMessages list of error messages.
     * @return void
     */
    public function merge($data, &$actionLog, &$errorMessages) {
        global $DB;

        $fromid = $data['fromid'];
        $toid = $data['toid'];

        // Fetch all course completions for the old user.
        $oldCompletions = $DB->get_records('course_completions', ['userid' => $fromid]);

        foreach ($oldCompletions as $completion) {
            $actionLog[] = get_string(
                'mergeusers_processing_completion',
                'tool_mergeusers',
                (object) ['courseid' => $completion->course]
            );

            $existingCompletion = $DB->get_record('course_completions', [
                'userid' => $toid,
                'course' => $completion->course,
            ]);

            if ($existingCompletion) {
                $actionLog[] = "Transferring completion for course {$completion->course} to user {$toid}.";

                $this->handle_existing_completion($completion, $existingCompletion, $fromid, $toid, $actionLog, $errorMessages);
                continue;
            }

            $this->transfer_completion($completion, $fromid, $toid, $actionLog, $errorMessages);
        }

        $DB->delete_records('course_completions', ['userid' => $fromid]);

        $actionLog[] = get_string(
            'mergeusers_completion_removed',
            'tool_mergeusers',
            (object) ['fromid' => $fromid]
        );
    }

    /**
     * Handles the logic for cases where both users have course completions.
     *
     * @param stdClass $completion Course completion record for the old user.
     * @param stdClass $existingCompletion Course completion record for the new user.
     * @param int $fromid Old user ID.
     * @param int $toid New user ID.
     * @param string[] $actionLog List of actions performed.
     * @param string[] $errorMessages List of error messages.
     * @return void
     */
    protected function handle_existing_completion($completion, $existingCompletion, $fromid, $toid, &$actionLog, &$errorMessages): void {
        global $DB;

        $actionLog[] = get_string(
            'mergeusers_handling_conflict',
            'tool_mergeusers',
            (object) ['courseid' => $completion->course]
        );

        if (empty($completion->timecompleted) && empty($existingCompletion->timecompleted)) {
            $actionLog[] = "Skipped merge for course {$completion->course} as both completions have no timestamp.";
            return;
        }

        if (!empty($completion->timecompleted) &&
            (empty($existingCompletion->timecompleted) || $completion->timecompleted > $existingCompletion->timecompleted)) {

            $actionLog[] = get_string(
                'mergeusers_existing_to_recompletion',
                'tool_mergeusers',
                (object) ['courseid' => $completion->course]
            );

            $this->move_to_recompletion($existingCompletion, $toid, $actionLog, $errorMessages);

            $updatecompletion = clone $completion;
            $updatecompletion->userid = $toid;
            $DB->update_record('course_completions', $updatecompletion);

            $actionLog[] = get_string(
                'mergeusers_old_to_recompletion',
                'tool_mergeusers',
                (object) ['courseid' => $completion->course]
            );

            return;
        }

        $actionLog[] = get_string(
            'mergeusers_old_to_recompletion',
            'tool_mergeusers',
            (object) ['courseid' => $completion->course]
        );
        $this->move_to_recompletion($completion, $fromid, $actionLog, $errorMessages);
    }

    /**
     * Transfers a completion record from old user to new user.
     *
     * @param stdClass $completion Course completion record for the old user.
     * @param int $fromid Old user ID.
     * @param int $toid New user ID.
     * @param string[] $actionLog List of actions performed.
     * @param string[] $errorMessages List of error messages.
     * @return void
     */
    protected function transfer_completion($completion, $fromid, $toid, &$actionLog, &$errorMessages): void {
        global $DB;

        // Transfer completion from old user to new user.
        $completion->userid = $toid;
        $DB->update_record('course_completions', $completion);
        $actionLog[] = get_string('mergeusers_completion_updated', 'tool_mergeusers', (object)[
            'courseid' => $completion->course,
            'fromid' => $fromid,
            'toid' => $toid,
        ]);

        // Move fromid's record to recompletion if it has a timestamp.
        if (!empty($completion->timecompleted)) {
            $this->move_to_recompletion($completion, $fromid, $actionLog, $errorMessages);
        }
    }

    /**
     * Moves the old course completion records to local recompletion.
     *
     * @param stdClass $completion Course completion object.
     * @param int $userid User id whose records are moved.
     * @param string[] $actionLog List of actions performed.
     * @param string[] $errorMessages List of error messages.
     * @return void
     */
    protected function move_to_recompletion($completion, $userid, &$actionLog, &$errorMessages): void {
        global $DB;

        $actionLog[] = "Moving course completion (Course ID: {$completion->course}) to recompletion for user {$userid}.";

        $recompletiondata = new stdClass();
        $recompletiondata->userid = $userid;
        $recompletiondata->courseid = $completion->course;
        $recompletiondata->timecompleted = $completion->timecompleted;
        $recompletiondata->timemodified = time();

        try {
            $DB->insert_record('local_recompletion_cc', $recompletiondata);

            $actionLog[] = get_string(
                'mergeusers_recompletion_moved',
                'tool_mergeusers',
                (object) [
                    'courseid' => $completion->course,
                    'userid' => $userid,
                ]
            );

        } catch (Exception $e) {
            $errorMessages[] = get_string(
                'mergeusers_recompletion_error',
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
