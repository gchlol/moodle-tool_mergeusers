<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it

defined('MOODLE_INTERNAL') || die();


/**
 * TableMerger to process course_completions table.
 *
 * Handles the merge of course completion data when merging user accounts.
 *
 * @package     tool
 * @subpackage  mergeusers
 */


class CourseCompletionMerger extends GenericTableMerger
{
    /**
     * Handles the merging of course completion records for two accounts.
     *
     * Case 1: Moves course completion records from the old account to the new account.
     * Case 2: Ensures that the portfolio is updated with the latest completion date and all previous completions are kept.
     *
     * @param array $data array with the necessary data for merging records.
     * @param array $actionLog list of action performed.
     * @param array $errorMessages list of error messages.
     */
    public function merge($data, &$actionLog, &$errorMessages)
    {
        global $DB;

        // Fetch the course completion records for both users.
        $oldCompletions = $DB->get_records('course_completions', ['userid' => $data['fromid']]);
        $newCompletions = $DB->get_records('course_completions', ['userid' => $data['toid']]);

        // Process course completions for both users.
        foreach ($oldCompletions as $oldCompletion) {
            $courseId = $oldCompletion->course;

            if (isset($newCompletions[$courseId])) {
                // CASE 2: Both users have completion records for the same course.
                // Update with the latest completion date, but keep all records.
                $newCompletion = $newCompletions[$courseId];

                if ($oldCompletion->timecompleted > $newCompletion->timecompleted) {
                    // If the old completion date is more recent, update the new account.
                    $this->updateCompletion($data['toid'], $courseId, $oldCompletion, $actionLog, $errorMessages);
                }

                // Record all previous completions
                $this->logPreviousCompletion($oldCompletion, $actionLog, $errorMessages);

            } else {
                // CASE 1: The new user has no completion record, move the old completion record.
                $this->updateCompletion($data['toid'], $courseId, $oldCompletion, $actionLog, $errorMessages);

                // Remove the old completion record.
                $this->removeOldCompletion($oldCompletion, $actionLog, $errorMessages);
            }
        }
    }

    /**
     * Updates the course completion record for the new user.
     *
     * @param int $toid New user ID.
     * @param int $courseId The course ID.
     * @param object $completion The completion record from the old user.
     * @param array $actionLog List of actions performed.
     * @param array $errorMessages List of error messages.
     */
    protected function updateCompletion($toid, $courseId, $completion, &$actionLog, &$errorMessages)
    {
        global $DB;

        $completion->userid = $toid;
        $completion->timecompleted = $completion->timecompleted; // Keep the original completion time.

        try {
            $DB->update_record('course_completions', $completion);
            $actionLog[] = "Updated course completion for course {$courseId} to user {$toid}.";
        } catch (Exception $e) {
            $errorMessages[] = "Error updating course completion for course {$courseId}: " . $e->getMessage();
        }
    }

    /**
     * Removes the course completion record for the old user.
     *
     * @param object $completion The completion record to remove.
     * @param array $actionLog List of actions performed.
     * @param array $errorMessages List of error messages.
     */
    protected function removeOldCompletion($completion, &$actionLog, &$errorMessages)
    {
        global $DB;

        try {
            $DB->delete_records('course_completions', ['id' => $completion->id]);
            $actionLog[] = "Removed old course completion for course {$completion->course}.";
        } catch (Exception $e) {
            $errorMessages[] = "Error removing old course completion for course {$completion->course}: " . $e->getMessage();
        }
    }

    /**
     * Logs the previous completion record for future reference.
     * Optional method to store previous completion history.
     *
     * @param object $completion The previous completion record.
     * @param array $actionLog List of actions performed.
     * @param array $errorMessages List of error messages.
     */
    protected function logPreviousCompletion($completion, &$actionLog, &$errorMessages)
    {
        global $DB;

        // This is an optional step, depending on whether you want to keep track of previous completions.
        // One possible implementation is to create a new table, e.g. 'course_completions_history'.
        // For this example, we simply log it.

        try {
            // Assuming 'course_completions_history' exists with similar structure to 'course_completions'.
            $DB->insert_record('local_recompletion_cc', $completion);
            $actionLog[] = "Logged previous completion for course {$completion->course} in history.";
        } catch (Exception $e) {
            $errorMessages[] = "Error logging previous completion for course {$completion->course}: " . $e->getMessage();
        }
    }
}
