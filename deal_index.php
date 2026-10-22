<?php
/**
 * deal_index.php
 *
 * Persistent local index (JSON file, safe for concurrent cron access via
 * flock) tracking:
 *   - Every claim's Client/Deal/Payment ClickUp task IDs, so Note emails can
 *     be matched to the right Deal without re-querying ClickUp's API every
 *     time (and without depending on any one fixed "Claim #:" label format,
 *     since notes get scanned for these known claim numbers as substrings).
 *   - Which email filenames have already been processed, so the cron job
 *     (running every 5 minutes) doesn't reprocess the same email twice.
 *
 * Storage file: deal_index.json, next to this script.
 */

define('DEAL_INDEX_PATH', __DIR__ . '/deal_index.json');

function dealIndexDefault(): array
{
    return ['deals' => [], 'processed_emails' => []];
}

/**
 * Open the index file with an exclusive lock and return [$fileHandle, $index].
 * Caller MUST eventually call saveDealIndexLocked() (to write+unlock) or
 * unlockDealIndexOnly() (to unlock without writing, for read-only callers).
 */
function loadDealIndexLocked(): array
{
    $fh = fopen(DEAL_INDEX_PATH, 'c+');
    if ($fh === false) {
        throw new RuntimeException('Could not open deal index file: ' . DEAL_INDEX_PATH);
    }
    flock($fh, LOCK_EX);
    $raw   = stream_get_contents($fh);
    $index = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($index)) {
        $index = dealIndexDefault();
    }
    return [$fh, $index];
}

function saveDealIndexLocked($fh, array $index): void
{
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
}

function unlockDealIndexOnly($fh): void
{
    flock($fh, LOCK_UN);
    fclose($fh);
}

/**
 * Record (or update) a claim's Client/Deal/Payment task IDs after
 * syncToClickUp() has run - whether the tasks were newly created or reused
 * via the existing-task dedup check, the index should reflect current
 * reality either way.
 *
 * Separately tracks WHICH of the three were actually created by this
 * software (vs. matched/reused pre-existing tasks) - this is what
 * reset_test_data.php uses to make sure it only ever deletes tasks this
 * system created, never a real pre-existing task it happened to reuse.
 * Once something is recorded as created-by-us, a later run reusing it
 * can never un-flag it (OR-merge with the prior value).
 *
 * @param array $result The array returned by syncToClickUp(): expects
 *                       'deal_task_id', 'client_task_id', 'payment_task_id',
 *                       and 'created' (['client'=>bool,'deal'=>bool,'payment'=>bool]) keys.
 */
function recordDeal(string $claimNumber, array $result, string $emailFile): void
{
    [$fh, $index] = loadDealIndexLocked();

    $priorCreated = $index['deals'][$claimNumber]['created_by_software'] ?? [];
    $newCreated   = $result['created'] ?? [];

    $index['deals'][$claimNumber] = [
        'deal_task_id'    => $result['deal_task_id'] ?? '',
        'client_task_id'  => $result['client_task_id'] ?? '',
        'payment_task_id' => $result['payment_task_id'] ?? '',
        'created_by_software' => [
            'client'  => !empty($priorCreated['client'])  || !empty($newCreated['client']),
            'deal'    => !empty($priorCreated['deal'])    || !empty($newCreated['deal']),
            'payment' => !empty($priorCreated['payment']) || !empty($newCreated['payment']),
        ],
        'email_file'      => $emailFile,
        'recorded_at'     => date('c'),
    ];
    saveDealIndexLocked($fh, $index);
}

function findDealByClaimNumber(string $claimNumber): ?array
{
    [$fh, $index] = loadDealIndexLocked();
    unlockDealIndexOnly($fh);
    return $index['deals'][$claimNumber] ?? null;
}

/**
 * All claim numbers currently in the index - pass this to
 * findKnownClaimNumbersInText() (in email_parser.php) to check a Note
 * email's raw text against them.
 */
function getKnownClaimNumbers(): array
{
    [$fh, $index] = loadDealIndexLocked();
    unlockDealIndexOnly($fh);
    return array_keys($index['deals']);
}

/**
 * The Deal task id recorded for a given claim number, or null if unknown.
 */
function getDealTaskIdForClaim(string $claimNumber): ?string
{
    [$fh, $index] = loadDealIndexLocked();
    unlockDealIndexOnly($fh);
    $dealTaskId = $index['deals'][$claimNumber]['deal_task_id'] ?? '';
    return $dealTaskId !== '' ? $dealTaskId : null;
}

function isEmailProcessed(string $emailFilename): bool
{
    [$fh, $index] = loadDealIndexLocked();
    unlockDealIndexOnly($fh);
    return isset($index['processed_emails'][$emailFilename]);
}

/**
 * List filenames in $emailsDir that are NOT yet marked processed, sorted
 * (these filenames start with a Unix timestamp, so sorting is also
 * chronological - Deals end up processed before their related Notes within
 * the same run).
 */
function getUnprocessedEmailFiles(string $emailsDir): array
{
    [$fh, $index] = loadDealIndexLocked();
    unlockDealIndexOnly($fh);

    $files = array_values(array_filter(scandir($emailsDir), function ($f) use ($emailsDir) {
        return is_file($emailsDir . '/' . $f);
    }));
    sort($files);

    return array_values(array_filter($files, function ($f) use ($index) {
        return !isset($index['processed_emails'][$f]);
    }));
}

function markEmailProcessed(string $emailFilename, string $outcome, array $extra = []): void
{
    [$fh, $index] = loadDealIndexLocked();
    $index['processed_emails'][$emailFilename] = array_merge([
        'outcome'      => $outcome,
        'processed_at' => date('c'),
    ], $extra);
    saveDealIndexLocked($fh, $index);
}

/**
 * Every unique task_id this software has ACTUALLY CREATED - used by
 * reset_test_data.php to clean up after a test batch. Deliberately
 * excludes any task that was only matched/reused via the dedup check
 * (created_by_software = false), so a real pre-existing task can never be
 * deleted by the cleanup script just because it happened to get reused.
 *
 * NOTE: entries recorded before this created_by_software tracking existed
 * have no such flag at all, which is treated as "not created by us" (safe
 * default - excluded from deletion). Those older entries need manual
 * cleanup in ClickUp if you want them gone; this function will not touch them.
 *
 * Deduplicated by task_id (the same Client task can legitimately be shared
 * across multiple claims for the same person).
 */
function getAllTrackedTaskIds(): array
{
    [$fh, $index] = loadDealIndexLocked();
    unlockDealIndexOnly($fh);

    $fieldToFlag = ['client_task_id' => 'client', 'deal_task_id' => 'deal', 'payment_task_id' => 'payment'];

    $seen = [];
    foreach ($index['deals'] as $claimNumber => $rec) {
        $createdFlags = $rec['created_by_software'] ?? [];
        foreach ($fieldToFlag as $field => $flagKey) {
            $taskId = $rec[$field] ?? '';
            if ($taskId === '' || isset($seen[$taskId])) {
                continue;
            }
            if (empty($createdFlags[$flagKey])) {
                continue; // not created by us - never eligible for deletion
            }
            $seen[$taskId] = ['claim_number' => $claimNumber, 'field' => $field, 'task_id' => $taskId];
        }
    }
    return array_values($seen);
}

function resetDealIndex(): void
{
    [$fh, ] = loadDealIndexLocked();
    saveDealIndexLocked($fh, dealIndexDefault());
}
