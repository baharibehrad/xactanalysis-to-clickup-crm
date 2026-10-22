<?php
/**
 * process_email.php
 *
 * Full Deal pipeline: raw email file -> extracted fields -> filled
 * filds_fills.js -> ClickUp (Client + Deal + Payment via syncToClickUp()).
 *
 * This is now a library file (like clickup_sync.php / email_parser.php) -
 * it defines processDealEmail() but doesn't run anything on its own.
 * The actual entry point to run is process_incoming_email.php, which
 * classifies an email first and dispatches here for 'deal' emails.
 *
 * To test this in isolation, use a separate runner file, e.g.:
 *   require __DIR__ . '/process_email.php';
 *   print_r(processDealEmail(__DIR__ . '/Emails/some-deal-email'));
 */

require_once __DIR__ . '/email_parser.php';
require_once __DIR__ . '/clickup_sync.php';
require_once __DIR__ . '/deal_index.php';

/**
 * @return array{client_task_id:string, deal_task_id:string, payment_task_id:string}|null
 *         null if the email couldn't be processed (missing required fields).
 */
function processDealEmail(string $emailFilePath): ?array
{
    // -------------------------------------------------------------------
    // 1) Parse the email
    // -------------------------------------------------------------------
    $parsed = parseXactAnalysisEmail($emailFilePath);

    echo 'Parsed fields:' . PHP_EOL;
    print_r($parsed);

    // -------------------------------------------------------------------
    // 2) Build the $replace_values map for filds_fills.js's [[tokens]]
    // -------------------------------------------------------------------
    // GAPS: these aren't present anywhere in this email format. Left blank
    // on purpose rather than guessed - dropdown fields with no matching
    // option name are safely skipped by clickup_sync.php, so this just means
    // those fields stay empty on the created tasks until there's a real
    // source for them (manual entry, a different feed, or a business rule).
    $Insurance_Carrier = $parsed['insurance_carrier']; // detected from subject/body, or "Direct (No Insurance)✅" if none found
    $TPA                = '';   // not stated in this email format
    $Payment_Source     = '';   // not stated (candidate default "Insurance" for claims - your call)
    $Telegram           = '';   // not an email-derived field

    $Xact_Link       = $parsed['xact_link'];
    $New_Build       = ''; // Project Type left blank - not auto-guessed from Type of Loss
    $location        = $parsed['location_of_property'];
    $Email            = $parsed['email_address'];
    $Primary_Phone   = $parsed['mobile_phone'];
    // Alternate Phone only gets filled if a genuinely separate number is
    // found. This email format doesn't provide a second number, so it stays
    // blank - if a future format has a real alternate number, extract it
    // above and assign it here instead of ''.
    $Alternate_Phone = '';

    $replace_values = array(
        '[[Xact_Link]]'         => jsonEscapeForTemplate($Xact_Link),
        '[[Insurance_Carrier]]' => jsonEscapeForTemplate($Insurance_Carrier),
        '[[New_Build]]'         => jsonEscapeForTemplate($New_Build),
        '[[TPA]]'                => jsonEscapeForTemplate($TPA),
        // filds_fills.js currently has "[[Adjuster]]" on the Deals "🥕 TPA"
        // field (same field id ClickUp-side as Payments' "[[TPA]]") - looks
        // like a copy/paste typo when the tokens were added, so mapped to
        // the same $TPA value here. Flag me if "Adjuster" was meant to be
        // separate data.
        '[[Adjuster]]'           => jsonEscapeForTemplate($TPA),
        '[[location]]'           => jsonEscapeForTemplate($location),
        '[[Email]]'               => jsonEscapeForTemplate($Email),
        '[[Telegram]]'            => jsonEscapeForTemplate($Telegram),
        '[[Primary_Phone]]'      => jsonEscapeForTemplate($Primary_Phone),
        '[[Alternate_Phone]]'    => jsonEscapeForTemplate($Alternate_Phone),
        '[[Payment_Source]]'     => jsonEscapeForTemplate($Payment_Source),
    );

    // -------------------------------------------------------------------
    // 3) Fill filds_fills.js's placeholders, write result to a temp file
    // -------------------------------------------------------------------
    $templatePath = __DIR__ . '/filds_fills.js';
    $template     = file_get_contents($templatePath);
    if ($template === false) {
        throw new RuntimeException("Could not read template: {$templatePath}");
    }

    $filled = strtr($template, $replace_values);

    // Sanity check: make sure the substitution produced valid JSON before we
    // hand it to syncToClickUp (which would otherwise fail with a less
    // obvious "Syntax error" deep inside fetchLiveFieldsJson).
    json_decode($filled);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Filled template is not valid JSON: ' . json_last_error_msg());
    }

    $filledPath = __DIR__ . '/filds_fills_filled.json';
    file_put_contents($filledPath, $filled);
    echo "Filled template written to: {$filledPath}" . PHP_EOL;

    // -------------------------------------------------------------------
    // 4) Push to ClickUp
    // -------------------------------------------------------------------
    // ASSUMPTION: using Claim Number as the "🅱️ ID" field / task-naming ID,
    // since it's the standard business identifier for a claim (falls back
    // to XA ID if Claim Number is ever missing). To use XA ID instead, swap
    // the order below.
    $id = $parsed['claim_number'] !== '' ? $parsed['claim_number'] : $parsed['xa_id'];

    $name     = $parsed['insured_name']; // Client task name (no ID)
    $fullName = $parsed['insured_name']; // Deal/Payment naming: "{fullName} | {id}"

    if ($id === '' || $name === '') {
        echo 'Could not extract a Claim Number/XA ID or an Insured Name from this email - aborting rather than creating a blank/misnamed task.' . PHP_EOL;
        return null;
    }

    try {
        $result = syncToClickUp($filledPath, $id, $name, $fullName);
        echo PHP_EOL . 'Done:' . PHP_EOL;
        print_r($result);

        // Keep the local index in sync with ClickUp - used to match future
        // Note emails to this Deal without hitting the API each time.
        recordDeal($id, $result, basename($emailFilePath));

        return $result;
    } catch (Throwable $e) {
        echo 'ClickUp sync failed: ' . $e->getMessage() . PHP_EOL;
        error_log('ClickUp sync failed: ' . $e->getMessage());
        return null;
    }
}
