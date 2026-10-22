<?php
/**
 * process_note.php
 *
 * Handles a Note email: extracts its note text, then figures out which Deal
 * it belongs to by scanning the email's subject+body for any claim number
 * already known in the local deal index (see deal_index.php) - Note emails
 * don't have a consistent enough format to reliably regex a claim number
 * out of directly, but a known claim number's exact string showing up
 * anywhere in the email is a reliable enough match.
 *
 * Library file (like clickup_sync.php / email_parser.php / process_email.php)
 * - defines processNoteEmailFile() but doesn't run anything on its own.
 * The entry point to run is process_incoming_email.php.
 */

require_once __DIR__ . '/email_parser.php';
require_once __DIR__ . '/clickup_sync.php';
require_once __DIR__ . '/deal_index.php';

/**
 * @return array{
 *   matched: bool,
 *   deal_task_id: ?string,
 *   comment_posted: bool,
 *   message: string,
 *   retry: bool   - true if this should be attempted again on a future run
 *                   (e.g. no matching Deal yet), false if this is a final
 *                   outcome (matched + posted, or an unresolvable ambiguity)
 * }
 */
function processNoteEmailFile(string $emailFilePath): array
{
    $raw     = file_get_contents($emailFilePath);
    $body    = extractPlainTextBody($raw);
    $subject = '';
    if (preg_match('/^Subject:\s*(.+)$/mi', $raw, $m)) {
        $subject = trim($m[1]);
    }
    $combinedText = $subject . "\n" . $body;

    $noteText = extractNoteText($body);
    $xactLink = extractXactLink($body);

    echo 'Note text: ' . ($noteText !== '' ? $noteText : '(none extracted)') . PHP_EOL;

    $knownClaims = getKnownClaimNumbers();
    $matches     = findKnownClaimNumbersInText($combinedText, $knownClaims);

    if (count($matches) === 0) {
        $message = 'No known Claim Number found in this note - the matching Deal may not exist yet.';
        echo $message . PHP_EOL;
        return [
            'matched'        => false,
            'deal_task_id'   => null,
            'comment_posted' => false,
            'message'        => $message,
            'retry'          => true, // the Deal might come in later - try again next run
        ];
    }

    if (count($matches) > 1) {
        $message = 'Note matched MULTIPLE known Claim Numbers (' . implode(', ', $matches) . ') - ambiguous, needs manual review.';
        echo $message . PHP_EOL;
        return [
            'matched'        => false,
            'deal_task_id'   => null,
            'comment_posted' => false,
            'message'        => $message,
            'retry'          => false, // ambiguity won't resolve itself by waiting
        ];
    }

    $claimNumber = $matches[0];
    $dealTaskId  = getDealTaskIdForClaim($claimNumber);

    if ($dealTaskId === null) {
        // Defensive - shouldn't normally happen (a "known" claim with no task id).
        $message = "Claim {$claimNumber} is known but has no recorded Deal task id - skipping.";
        echo $message . PHP_EOL;
        return [
            'matched'        => false,
            'deal_task_id'   => null,
            'comment_posted' => false,
            'message'        => $message,
            'retry'          => true,
        ];
    }

    if ($noteText === '') {
        $message = "Matched Claim {$claimNumber} (Deal {$dealTaskId}) but could not extract any note text from this email format - needs manual review.";
        echo $message . PHP_EOL;
        return [
            'matched'        => true,
            'deal_task_id'   => $dealTaskId,
            'comment_posted' => false,
            'message'        => $message,
            'retry'          => false, // a human needs to add the missing extraction format, not just wait
        ];
    }

    attachNoteToDeal($dealTaskId, $noteText, $xactLink);
    $message = "Note posted on Deal {$dealTaskId} (Claim {$claimNumber}).";
    echo $message . PHP_EOL;

    return [
        'matched'        => true,
        'deal_task_id'   => $dealTaskId,
        'comment_posted' => true,
        'message'        => $message,
        'retry'          => false,
    ];
}
