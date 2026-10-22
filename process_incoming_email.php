<?php
/**
 * process_incoming_email.php
 *
 * Main entry point, meant to be run by a cron job every 5 minutes with no
 * arguments: scans the Emails/ folder, skips anything already recorded in
 * processed_emails.json, and dispatches each new file by type:
 *
 *   'deal'  -> processDealEmail()     (creates/reuses Client+Deal+Payment,
 *                                       records the Deal in deal_index.json)
 *   'note'  -> processNoteEmailFile() (matches to a Deal via deal_index.json,
 *                                       posts the note as a comment)
 *   'status_update' -> sendTelegramMessage() (claim reference but no "note"
 *                                       keyword, e.g. "QA Rejected Assignment
 *                                       Status" emails - sent to Telegram,
 *                                       claim number deliberately omitted)
 *   'other' -> logged and marked processed (not auto-handled - review manually)
 *
 * Note emails with no matching Deal yet are deliberately NOT marked
 * processed, so they're retried automatically on a future run once their
 * Deal shows up. Ambiguous multi-match notes ARE marked processed (retrying
 * won't resolve an ambiguity by itself) and need manual review.
 *
 * USAGE:
 *   Cron (real mode - scans the whole folder, updates the processed log):
 *     php process_incoming_email.php
 *
 *   Manual single-file test (preview only - does NOT touch the processed
 *   log, so you can safely re-run the same file over and over while testing):
 *     php process_incoming_email.php /path/to/one/email/file
 *     https://.../process_incoming_email.php?file=/path/to/one/email/file
 */

require_once __DIR__ . '/email_parser.php';
require_once __DIR__ . '/clickup_sync.php';
require_once __DIR__ . '/deal_index.php';
require_once __DIR__ . '/process_email.php';
require_once __DIR__ . '/process_note.php';
require_once __DIR__ . '/telegram.php';

define('EMAILS_FOLDER', __DIR__ . '/../../mail/bilpire.com/clickupapi/new');

/**
 * Classify and dispatch one email. Returns the outcome string to record in
 * the processed log, or null if this file should NOT be marked processed
 * (i.e. should be retried on a future run).
 */
function dispatchEmail(string $path): ?string
{
    $raw  = file_get_contents($path);
    $type = classifyEmailType($raw);
    echo "Classified as: {$type}" . PHP_EOL;

    switch ($type) {
        case 'deal':
            $result = processDealEmail($path);
            return $result !== null ? 'deal' : null; // retry on failure rather than silently dropping it

        case 'note':
            $result = processNoteEmailFile($path);
            return empty($result['retry']) ? ($result['matched'] ? 'note' : 'note_unresolved') : null;

        case 'status_update':
        case 'cancellation':
            $summary = extractStatusUpdateSummary($raw);
            try {
                sendTelegramMessage($summary);
                echo "Sent to Telegram ({$type})." . PHP_EOL;
                return $type;
            } catch (Throwable $e) {
                echo "Telegram send failed: {$e->getMessage()}" . PHP_EOL;
                return null; // retry next run rather than losing it silently
            }

        default:
            echo "'other' - not auto-processed, left for manual review." . PHP_EOL;
            return 'other';
    }
}

// ---------------------------------------------------------------------------
// Manual single-file mode (testing) - preview only, doesn't touch the log
// ---------------------------------------------------------------------------
$singleFilePath = null;
if (isset($argv[1])) {
    $singleFilePath = $argv[1];
} elseif (isset($_REQUEST['file'])) {
    $singleFilePath = $_REQUEST['file'];
}

if ($singleFilePath !== null) {
    if (!is_readable($singleFilePath)) {
        echo "Email file not found or not readable: {$singleFilePath}" . PHP_EOL;
        exit(1);
    }
    echo "--- Manual test mode: {$singleFilePath} (processed log NOT updated) ---" . PHP_EOL;
    dispatchEmail($singleFilePath);
    exit(0);
}

// ---------------------------------------------------------------------------
// Real mode (cron) - scan the whole folder, update the processed log
// ---------------------------------------------------------------------------
if (!is_dir(EMAILS_FOLDER)) {
    echo 'Emails folder not found: ' . EMAILS_FOLDER . PHP_EOL;
    exit(1);
}

$unprocessed = getUnprocessedEmailFiles(EMAILS_FOLDER);
echo count($unprocessed) . ' new email(s) to process.' . PHP_EOL;

foreach ($unprocessed as $filename) {
    echo PHP_EOL . "--- Processing: {$filename} ---" . PHP_EOL;
    $path = EMAILS_FOLDER . '/' . $filename;

    try {
        $outcome = dispatchEmail($path);
        if ($outcome !== null) {
            markEmailProcessed($filename, $outcome);
        } else {
            echo '  Leaving unmarked - will retry on the next run.' . PHP_EOL;
        }
    } catch (Throwable $e) {
        echo "  ERROR: {$e->getMessage()}" . PHP_EOL;
        error_log("process_incoming_email.php error on {$filename}: {$e->getMessage()}");
        // Don't mark as processed on an unexpected error - retry next run.
    }
}
