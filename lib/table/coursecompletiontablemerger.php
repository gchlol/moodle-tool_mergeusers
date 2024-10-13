<?php

/**
 * Course Completion Table Merger implementation.
 *
 * @package    tool_mergeusers
 * @subpackage mergeusers
 */

defined('MOODLE_INTERNAL') || die();

class CourseCompletionTableMerger extends GenericTableMerger {

    /**
     * Merges course completions from the old user into the new user and moves old records into local recompletion.
     *
     * @param array $data array with the necessary data for merging records.
     * @param array $actionLog list of actions performed.
     * @param array $errorMessages list of error messages.
     */
    public function merge($data, &$actionLog, &$errorMessages) {
        global $DB;

        $fromid = $data['fromid'];
        $toid = $data['toid'];

        // Fetch all course completions for the old user.
        $oldCompletions = $DB->get_records('course_completions', ['userid' => $fromid]);

        foreach ($oldCompletions as $completion) {
            // Check if the new user already has a completion record for the course.
            $existingCompletion = $DB->get_record('course_completions', [
                'userid' => $toid,
                'course' => $completion->course
            ]);

            // Handle based on completion timestamp presence.
            if ($existingCompletion) {
                $this->handle_existing_completion($completion, $existingCompletion, $fromid, $toid, $actionLog, $errorMessages);
            }

            if (!$existingCompletion) {
                // No existing completion for the toid user, transfer fromid's completion
                $this->transfer_completion($completion, $fromid, $toid, $actionLog, $errorMessages);
            }
        }

        // Delete old user's course completion records.
        $DB->delete_records('course_completions', ['userid' => $fromid]);

        $actionLog[] = get_string('mergeusers_completion_removed', 'tool_mergeusers', (object)[
            'fromid' => $fromid
        ]);
    }

    /**
     * Handles the logic for cases where both users have course completions.
     *
     * @param object $completion Course completion record for the old user.
     * @param object $existingCompletion Course completion record for the new user.
     * @param int $fromid Old user ID.
     * @param int $toid New user ID.
     * @param array $actionLog List of actions performed.
     * @param array $errorMessages List of error messages.
     */
    protected function handle_existing_completion($completion, $existingCompletion, $fromid, $toid, &$actionLog, &$errorMessages) {
        global $DB;

        // Both have timestamps: keep the latest, move the older to recompletion.
        if (!empty($completion->timecompleted) && !empty($existingCompletion->timecompleted)) {
            if ($completion->timecompleted > $existingCompletion->timecompleted) {
                // Keep fromid's (latest), move toid's to recompletion.
                $this->move_to_recompletion($existingCompletion, $toid, $actionLog, $errorMessages);
                $completion->userid = $toid;
                $DB->update_record('course_completions', $completion);
                $actionLog[] = get_string('mergeusers_completion_updated', 'tool_mergeusers', (object)[
                    'courseid' => $completion->course,
                    'fromid' => $fromid,
                    'toid' => $toid
                ]);
            }

            if ($completion->timecompleted <= $existingCompletion->timecompleted) {
                // Keep toid's, move fromid's to recompletion.
                $this->move_to_recompletion($completion, $fromid, $actionLog, $errorMessages);
            }
        }

        // fromid has timestamp, toid has none: move fromid's to recompletion.
        if (!empty($completion->timecompleted) && empty($existingCompletion->timecompleted)) {
            $this->move_to_recompletion($completion, $fromid, $actionLog, $errorMessages);
        }

        // Neither have timestamps: move fromid's to recompletion.
        if (empty($completion->timecompleted) && empty($existingCompletion->timecompleted)) {
            $this->move_to_recompletion($completion, $fromid, $actionLog, $errorMessages);
        }

        // fromid has no timestamp, toid has: move fromid's to recompletion.
        if (empty($completion->timecompleted) && !empty($existingCompletion->timecompleted)) {
            $this->move_to_recompletion($completion, $fromid, $actionLog, $errorMessages);
        }
    }

    /**
     * Transfers a completion record from old user to new user.
     *
     * @param object $completion Course completion record for the old user.
     * @param int $fromid Old user ID.
     * @param int $toid New user ID.
     * @param array $actionLog List of actions performed.
     * @param array $errorMessages List of error messages.
     */
    protected function transfer_completion($completion, $fromid, $toid, &$actionLog, &$errorMessages) {
        global $DB;

        // Transfer completion from old user to new user.
        $completion->userid = $toid;
        $DB->update_record('course_completions', $completion);
        $actionLog[] = get_string('mergeusers_completion_updated', 'tool_mergeusers', (object)[
            'courseid' => $completion->course,
            'fromid' => $fromid,
            'toid' => $toid
        ]);

        // Move fromid's record to recompletion if it has a timestamp.
        if (!empty($completion->timecompleted)) {
            $this->move_to_recompletion($completion, $fromid, $actionLog, $errorMessages);
        }
    }

    /**
     * Moves the old course completion records to local recompletion.
     *
     * @param object $completion Course completion object.
     * @param int $userid User id whose records are moved.
     * @param array $actionLog List of actions performed.
     * @param array $errorMessages List of error messages.
     */
    protected function move_to_recompletion($completion, $userid, &$actionLog, &$errorMessages) {
        global $DB;

        // Create a record in the local recompletion table (e.g., local_recompletion) for historical purposes.
        $recompletionData = new stdClass();
        $recompletionData->userid = $userid;
        $recompletionData->courseid = $completion->course;
        $recompletionData->timecompleted = $completion->timecompleted;
        $recompletionData->timemodified = time();

        try {
            $DB->insert_record('local_recompletion_cc', $recompletionData);
            $actionLog[] = get_string('mergeusers_recompletion_moved', 'tool_mergeusers', (object)[
                'courseid' => $completion->course,
                'userid' => $userid
            ]);
        } catch (Exception $e) {
            $errorMessages[] = get_string('mergeusers_recompletion_error', 'tool_mergeusers', (object)[
                'courseid' => $completion->course,
                'userid' => $userid,
                'error' => $e->getMessage()
            ]);
        }
    }
}
