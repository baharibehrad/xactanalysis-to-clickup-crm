<?php
/**
 * reset_test_data.php
 *
 * TESTING/DEV UTILITY ONLY. Deletes ClickUp tasks this software actually
 * CREATED (tracked in deal_index.json with created_by_software = true),
 * then resets the index (and the processed-emails log) so a fresh batch of
 * test emails can be re-run from scratch.
 *
 * SAFETY GUARANTEE: this will NEVER delete a task that already existed in
 * ClickUp before this software touched it. If a Client/Deal/Payment was
 * found and REUSED via the dedup check (rather than newly created),
 * deal_index.php excludes it from getAllTrackedTaskIds() entirely - only
 * genuinely-created tasks are ever eligible for deletion here.
 *
 * Usage (CLI):     php reset_test_data.php yes
 * Usage (browser): reset_test_data.php?confirm=yes
 * (running with no confirmation just prints what it WOULD do)
 */

require_once __DIR__ . '/clickup_sync.php';
require_once __DIR__ . '/deal_index.php';

$confirmed = (isset($argv[1]) && $argv[1] === 'yes')
    || (isset($_REQUEST['confirm']) && $_REQUEST['confirm'] === 'yes');

$tracked = getAllTrackedTaskIds();

echo 'Safety check: only tasks flagged created_by_software=true are considered - pre-existing/reused tasks are never included.' . PHP_EOL;

if (!$confirmed) {
    echo 'This will PERMANENTLY DELETE ' . count($tracked) . ' task(s) tracked in deal_index.json from ClickUp.' . PHP_EOL;
    echo 'Re-run with confirmation to proceed:' . PHP_EOL;
    echo '  CLI:     php reset_test_data.php yes' . PHP_EOL;
    echo '  Browser: reset_test_data.php?confirm=yes' . PHP_EOL;
    exit(0);
}

echo 'Deleting ' . count($tracked) . ' tracked task(s)...' . PHP_EOL;

$deleted = 0;
$failed  = 0;

foreach ($tracked as $entry) {
    try {
        deleteTaskById($entry['task_id']);
        echo "Deleted {$entry['field']} {$entry['task_id']} (claim {$entry['claim_number']})" . PHP_EOL;
        $deleted++;
    } catch (Throwable $e) {
        echo "FAILED to delete {$entry['task_id']} (claim {$entry['claim_number']}): {$e->getMessage()}" . PHP_EOL;
        $failed++;
    }
}

resetDealIndex();

echo PHP_EOL . "Done. Deleted: {$deleted}, Failed: {$failed}." . PHP_EOL;
echo 'deal_index.json has been reset - next run will treat all emails as new.' . PHP_EOL;
