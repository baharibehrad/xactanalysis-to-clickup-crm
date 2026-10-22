<?php
/**
 * email_parser.php
 *
 * Parses a raw MIME email file (as stored by cPanel mail delivery) from an
 * XactAnalysis assignment notification, and extracts the structured fields
 * needed to fill filds_fills.js's [[placeholder]] tokens.
 *
 * NOTE: This is built around the "Assignment Profile" format in your sample
 * email (Claim Number / Insured Name / Mobile Phone / etc. as plain-text
 * "Label:  value" lines). If Notes/Others emails use a different format,
 * they'll need their own parser - this one assumes a Deal/claim-style email.
 */

/**
 * Extract and decode the text/plain part of a multipart MIME email.
 */
function extractPlainTextBody(string $rawEmail): string
{
    if (!preg_match('/^Content-Type:\s*multipart\/[^;]+;\s*boundary="?([^"\r\n]+)"?/mi', $rawEmail, $m)) {
        // Not multipart - treat everything after the header block as the
        // body, decoding per the top-level Content-Transfer-Encoding header
        // (single-part HTML notification emails are often quoted-printable,
        // e.g. "<!-- end_html_note -->" can get split across a QP soft line
        // break as "end=\n_html_note" if this isn't decoded).
        $parts        = preg_split('/\r?\n\r?\n/', $rawEmail, 2);
        $headerBlock  = $parts[0] ?? '';
        $body         = $parts[1] ?? '';

        if (preg_match('/^Content-Transfer-Encoding:\s*quoted-printable/mi', $headerBlock)) {
            $body = quoted_printable_decode($body);
        } elseif (preg_match('/^Content-Transfer-Encoding:\s*base64/mi', $headerBlock)) {
            $body = base64_decode($body);
        }

        return $body;
    }
    $boundary = $m[1];
    $segments = explode('--' . $boundary, $rawEmail);

    foreach ($segments as $segment) {
        if (stripos($segment, 'Content-Type: text/plain') === false) {
            continue;
        }

        $segParts = preg_split('/\r?\n\r?\n/', $segment, 2);
        if (count($segParts) < 2) {
            continue;
        }
        [$segHeaders, $segBody] = $segParts;

        if (stripos($segHeaders, 'quoted-printable') !== false) {
            $segBody = quoted_printable_decode($segBody);
        } elseif (stripos($segHeaders, 'base64') !== false) {
            $segBody = base64_decode($segBody);
        }

        return trim($segBody);
    }

    return '';
}

/**
 * Pull one "Label:  value" line out of the decoded plain-text body.
 * Returns '' if the label isn't found.
 */
function extractLabeledField(string $body, string $label): string
{
    if (preg_match('/' . preg_quote($label, '/') . '\s*:\s*(.+)/i', $body, $m)) {
        return trim($m[1]);
    }
    return '';
}

/**
 * Parse an XactAnalysis assignment notification email into a flat array.
 * Missing fields come back as '' rather than throwing, so the caller decides
 * what to do about gaps.
 */
function parseXactAnalysisEmail(string $emailFilePath): array
{
    if (!is_readable($emailFilePath)) {
        throw new RuntimeException("Email file not found or not readable: {$emailFilePath}");
    }

    $raw     = file_get_contents($emailFilePath);
    $rawBody = extractPlainTextBody($raw);

    // Extract the link BEFORE cleaning - htmlToPlainText's tag-stripping
    // would otherwise mangle bare "<https://...>" citation-style URLs too.
    $xactLink = '';
    if (preg_match('#<(https://xactanalysis\.com/[^>\s]+)>#i', $rawBody, $m)) {
        $xactLink = $m[1];
    } elseif (preg_match('#href="(https://xactanalysis\.com/[^"]+)"#i', $rawBody, $m)) {
        $xactLink = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
    }

    $subject = '';
    if (preg_match('/^Subject:\s*(.+)$/mi', $raw, $m)) {
        $subject = trim($m[1]);
    }

    // Clean for field extraction - handles both the old plain-text format
    // ("Insured Name:  X" on its own line) and HTML-table formats some
    // sources use ("<tr><td>Insured Name:</td><td>X</td></tr>").
    $body = htmlToPlainText($rawBody);

    return [
        'claim_number'          => extractLabeledField($body, 'Claim Number'),
        'insured_name'          => extractLabeledField($body, 'Insured Name'),
        'mobile_phone'          => extractLabeledField($body, 'Mobile Phone'),
        'email_address'         => extractLabeledField($body, 'Email Address'),
        'type_of_loss'          => extractLabeledField($body, 'Type of Loss'),
        'date_of_loss'          => extractLabeledField($body, 'Date of Loss'),
        'xa_id'                 => extractLabeledField($body, 'XA ID'),
        'location_of_property'  => extractLabeledField($body, 'Location of Property'),
        'xact_link'             => $xactLink,
        'insurance_carrier'     => detectInsuranceCarrier($subject . "\n" . $body),
    ];
}

/**
 * Classify a raw email as:
 *   'deal'          - "Insured Name:" AND "Claim Number:" both present -
 *                     checked FIRST so a Deal's own "Notes :..." Instructions
 *                     text doesn't false-match the 'note' check below.
 *   'note'          - mentions both "claim" and "note" somewhere - Note
 *                     emails come in several inconsistent formats, so this
 *                     is deliberately broad.
 *   'cancellation'  - mentions "cancel"/"canceled"/"cancellation" - e.g.
 *                     assignment cancellation notices (which use "Member
 *                     Number"/"Member Name" rather than "Claim Number"/
 *                     "Insured Name", so the 'deal' check above won't catch
 *                     them). Sent to Telegram immediately, same as
 *                     'status_update' - these can be time-sensitive
 *                     ("stop all work immediately").
 *   'status_update' - has a labeled claim reference ("Claim #:" / "Claim
 *                     Number:") but NOT the word "note" - e.g. "QA Rejected
 *                     Assignment Status" emails. Sent to Telegram rather
 *                     than posted to ClickUp.
 *   'other'         - matches none of the above, left for manual review.
 */
function classifyEmailType(string $rawEmail): string
{
    $subject = '';
    if (preg_match('/^Subject:\s*(.+)$/mi', $rawEmail, $m)) {
        $subject = trim($m[1]);
    }
    $body = extractPlainTextBody($rawEmail);

    if (stripos($body, 'Insured Name:') !== false && stripos($body, 'Claim Number:') !== false) {
        return 'deal';
    }

    $combined = $subject . "\n" . $body;
    if (stripos($combined, 'claim') !== false && stripos($combined, 'note') !== false) {
        return 'note';
    }

    if (preg_match('/\bcancell?ed\b|\bcancellation\b/i', $combined)) {
        return 'cancellation';
    }

    if (preg_match('/\bclaim\s*#?\s*:/i', $combined)) {
        return 'status_update';
    }

    return 'other';
}

/**
 * Small HTML->plain-text helper for note bodies: turns <br>/<BR> tags into
 * newlines, strips any remaining tags, decodes entities, collapses the runs
 * of blank lines these note emails tend to have, and trims.
 */
/**
 * Small HTML->plain-text helper: converts block-level boundaries (table
 * rows, <br>, paragraphs) into line breaks and table cells into
 * space-separated text BEFORE stripping tags - otherwise an HTML table like
 * "<tr><td>Insured Name:</td><td>RONALD SPRINGSTON</td></tr>" gets
 * flattened into unreadable "Insured Name:</td><td>RONALD SPRINGSTON</td>"
 * runs once tags are naively stripped. Safe to run on already-plain text
 * too (no-op if there are no tags).
 */
function htmlToPlainText(string $html): string
{
    $text = preg_replace('/<!--.*?-->/s', '', $html);
    $text = preg_replace('/<\/(tr|p|div|li)\s*>/i', "\n", $text);
    $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
    $text = preg_replace('/<\/td>\s*<td[^>]*>/i', ' ', $text);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
    // Normalize non-breaking spaces (however they arrived: &nbsp;, &#160;,
    // &#xA0;, or the raw U+00A0 byte sequence after decoding) to a real
    // space - PCRE's \s does NOT match U+00A0 without the /u modifier,
    // which would otherwise silently break "Label:\s*(.+)" extraction.
    $text = str_replace("\xC2\xA0", ' ', $text);
    $text = preg_replace('/[ \t]{2,}/', ' ', $text);
    $text = preg_replace('/\n{2,}/', "\n", $text);
    return trim($text);
}

/**
 * Extract the note's text content from a Note email's body. Tries known
 * formats in order and returns '' if none match:
 *
 *   Format A: "Assignment Note Has Been Added" emails - HTML body, note
 *             wrapped in <!-- html_note -->...<!-- end_html_note --> comments.
 *
 *   Format B: "Personal Rule ... Note Added to Assignment" emails - plain
 *             text, a "note: ..." paragraph ending before the auto-generated
 *             sign-off/boilerplate that follows it.
 *   Format C: Same "Assignment Note" family as Format A, but missing the
 *             <!-- html_note --> comment markers - bounded instead by the
 *             stable "To view the detailed information" trailer that all
 *             these XactAnalysis notification emails share.
 *
 * More formats can be added here as new samples come in - this is exactly
 * the kind of per-format variation Dean flagged, so expect this list to grow.
 */
function extractNoteText(string $body): string
{
    // Format A
    if (preg_match('/<!--\s*html_note\s*-->(.*?)<!--\s*end_html_note\s*-->/is', $body, $m)) {
        return htmlToPlainText($m[1]);
    }

    // Format C - check before Format B, since Format C's "<b>Note:</b>"
    // label would otherwise get caught by Format B's looser "note:" match
    // and include the "</b><br />" HTML remnant in the captured text.
    if (preg_match('/<b>\s*Note:\s*<\/b>\s*<br\s*\/?>\s*(.*?)(?=To view the detailed information)/is', $body, $m)) {
        $text = htmlToPlainText($m[1]);
        if ($text !== '') {
            return $text;
        }
    }

    // Format B
    $stopPhrases = ['Thank you,', 'This rule may have been set up', 'To view the detailed information'];
    $stopPattern = implode('|', array_map(fn($p) => preg_quote($p, '/'), $stopPhrases));
    if (preg_match('/\bnote:\s*(.*?)(?:\n\s*\n\s*(?:' . $stopPattern . ')|\z)/is', $body, $m)) {
        $text = trim($m[1]);
        if ($text !== '') {
            return preg_replace('/\n{3,}/', "\n\n", $text);
        }
    }

    return '';
}

/**
 * Extract an XactAnalysis link from either HTML (href="...") or plain-text
 * (bare URL, optionally wrapped in <...>) email bodies.
 */
function extractXactLink(string $body): string
{
    if (preg_match('#href="(https://xactanalysis\.com/[^"]+)"#i', $body, $m)) {
        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
    }
    if (preg_match('#<(https://xactanalysis\.com/[^>\s]+)>#i', $body, $m)) {
        return $m[1];
    }
    if (preg_match('#(https://xactanalysis\.com/\S+)#i', $body, $m)) {
        return rtrim($m[1], '.,;');
    }
    return '';
}

/**
 * Scan $text (typically subject+body combined) for any already-known claim
 * number appearing anywhere in it. This is the core trick for matching Note
 * emails to their Deal despite having no consistent format to regex a claim
 * number out of: if a known claim number's exact string shows up anywhere,
 * that's a reliable enough signal.
 *
 * @param string[] $knownClaimNumbers
 * @return string[] Every known claim number found (usually 0 or 1 - more
 *                   than 1 is an ambiguous case for the caller to handle)
 */
function findKnownClaimNumbersInText(string $text, array $knownClaimNumbers): array
{
    $found = [];
    foreach ($knownClaimNumbers as $claimNumber) {
        if ($claimNumber !== '' && strpos($text, $claimNumber) !== false) {
            $found[] = $claimNumber;
        }
    }
    return $found;
}

/**
 * Build a Telegram message for a 'status_update' email (has a claim
 * reference but no "note" keyword - e.g. "QA Rejected Assignment Status").
 * Per instruction, the claim number itself is deliberately left OUT of the
 * message - just the subject + descriptive body content.
 */
function extractStatusUpdateSummary(string $rawEmail): string
{
    $subject = '';
    if (preg_match('/^Subject:\s*(.+)$/mi', $rawEmail, $m)) {
        $subject = trim($m[1]);
    }

    $body = htmlToPlainText(extractPlainTextBody($rawEmail));

    // Remove "Claim #: XXXX" / "Claim Number: XXXX" style references.
    $body = preg_replace('/\bclaim\s*#?\s*:?\s*[\w\-]+/i', '', $body);
    $body = preg_replace('/[ \t]{2,}/', ' ', $body);
    $body = preg_replace('/\n{2,}/', "\n", trim($body));

    return trim($subject . "\n\n" . $body);
}

/**
 * Rough Type-of-Loss -> "⛑️ Project Type" mapping (Restoration / Remodeling /
 * Repair / New Build / RSG). Any claim with a stated loss type is, by
 * definition, damage restoration work - so this defaults to "Restoration".
 * Adjust if your business rules split loss types across other categories.
 */
function mapTypeOfLossToProjectType(string $typeOfLoss): string
{
    return $typeOfLoss !== '' ? 'Restoration' : '';
}

/**
 * Known "☂️ Insurance Carrier" dropdown option names from filds_fills.js
 * (excluding the fallback default, which isn't something to search for).
 * Keep this in sync if carrier options are ever added/renamed in ClickUp.
 */
const KNOWN_INSURANCE_CARRIERS = [
    'Travelers', 'Farmers', 'State Farm', 'Liberty Mutual', 'Bamboo', 'USAA', 'ACE', 'Allstate',
];

/**
 * Search email text (subject + body) for a known carrier name and return the
 * exact dropdown option name to use. Uses word-boundary matching (not plain
 * substring) so short names like "ACE" don't false-match inside other words.
 * Falls back to "Direct (No Insurance)✅" if nothing is found - per your
 * instruction, an unrecognized/absent carrier defaults to Direct.
 */
function detectInsuranceCarrier(string $text): string
{
    foreach (KNOWN_INSURANCE_CARRIERS as $carrier) {
        if (preg_match('/\b' . preg_quote($carrier, '/') . '\b/i', $text)) {
            return $carrier;
        }
    }
    return 'Direct (No Insurance)✅';
}

/**
 * Safely escape a raw string for insertion into a JSON string value via
 * strtr()/str_replace() token substitution (handles quotes, backslashes,
 * unicode, etc. so a name/address containing a `"` doesn't break the JSON).
 */
function jsonEscapeForTemplate(string $value): string
{
    return substr(json_encode($value, JSON_UNESCAPED_UNICODE), 1, -1);
}
