<?php
/**
 * clickup_sync.php
 *
 * Pulls the field/value definitions from a "live" JSON endpoint (same shape as
 * filds_fills.js) and pushes them into ClickUp as three linked tasks:
 *
 *   1. Client  -> created first, named "Name Last Name"
 *   2. Deal    -> named "Name Last Name | ClientID", linked back to the Client
 *   3. Payment -> named "Name Last Name | ClientID", linked back to the Client
 *
 * ASSUMPTIONS (please confirm / adjust these to match your setup):
 *   - The "live fields JSON" is reachable over HTTP(S) and returns the exact
 *     same structure as filds_fills.js (Deals / Clients / Payments, each with
 *     a list_id and a fields[] array of {id, title, type, value, options?}).
 *   - The values already inside that JSON (e.g. "Allstate", "13013 Via Sur Ave")
 *     are the values that should be written to ClickUp - this script does not
 *     invent data, it only maps + sends what's in the JSON.
 *   - $id / $name / $fullName are supplied by your existing PHP app (e.g. from
 *     the email parser) and are NOT expected to already be inside the JSON.
 *   - The "🅱️ ID" field on Deals and Payments gets set to $id.
 *   - The "😎 Client" list_relationship field on Deals and Payments gets set
 *     to the newly-created Client task.
 *   - Dropdown values in the JSON are option NAMES (e.g. "Allstate") and get
 *     translated to the option's UUID using the options[] array in the same
 *     field definition.
 *   - "location" fields don't have lat/lng in the JSON, only a text address.
 *     ClickUp's location field technically wants lat/lng + formatted_address.
 *     Below we send formatted_address only with lat/lng = null; if ClickUp
 *     rejects that for your field config, you'll need to geocode the address
 *     first (e.g. Google Maps Geocoding API) - flagged with a TODO below.
 *   - "attachment" type fields (e.g. Invoice) are skipped entirely - files
 *     need a separate upload endpoint, not the field-value endpoint.
 *   - list_relationship fields that have no value in the JSON (Mitigation,
 *     Adjuster, Team, TPA, Insurance) are left untouched/skipped.
 *
 * Adjust the CONFIG block below, then call:
 *   syncToClickUp($liveFieldsJsonUrl, $id, $name, $fullName);
 */

// ---------------------------------------------------------------------------
// CONFIG - pulled from config.php (never hardcode tokens in this file)
// ---------------------------------------------------------------------------
$__config = require __DIR__ . '/config.php';

define('CLICKUP_API_TOKEN', $__config['clickup_token']);
define('CLICKUP_API_BASE', 'https://api.clickup.com/api/v2');

// NOTE: despite the key name in config.php, 'clickup_list_id' is actually
// your Workspace/Team ID (confirmed from the app.clickup.com/{team_id}/... URL),
// needed to look up Custom Task Types below.
define('CLICKUP_TEAM_ID', $__config['clickup_list_id']);

// If your Deals list uses Custom Task Types (e.g. "Deal" vs "Contracts") and
// new tasks should default to "Deal" instead of the list's own default type,
// set the type name here. Set to null to skip this and use the list default.
define('DEALS_CUSTOM_TASK_TYPE_NAME', 'Deal');

// Field ID for "🗂️ Project Status" on Deals, and the option name a brand-new
// Deal should start as. filds_fills.js's sample value is "Contracts", which
// your "Project Status Deal to Contract Automation" treats as a signed
// contract and immediately moves the task out of the Deals list. Forcing
// this to "Deal" here (like FIELD_ID_DEAL_ID) keeps new deals in Deals.
// Set NEW_DEAL_PROJECT_STATUS to null to leave whatever value is in the JSON.
define('FIELD_ID_DEAL_PROJECT_STATUS', '2dd05fe4-13f4-452b-85f5-4deb1527b391');
define('NEW_DEAL_PROJECT_STATUS', 'Deal');

// Field IDs that get special treatment (from filds_fills.js)
define('FIELD_ID_DEAL_ID',     'ca8dce5d-6a4c-4301-b325-4b8e607c8fc6'); // "🅱️ ID" on Deals
define('FIELD_ID_PAYMENT_ID',  'ca8dce5d-6a4c-4301-b325-4b8e607c8fc6'); // "🅱️ ID" on Payments (same UUID reused)
define('FIELD_ID_DEAL_CLIENT',    'abc91eb9-4236-42f3-957c-98996b570614'); // "😎 Client" on Deals
define('FIELD_ID_PAYMENT_CLIENT', '2b58f503-9fc2-4bec-b370-719279739e04'); // "😎 Client" on Payments
define('FIELD_ID_CLIENT_EMAIL',   '3c8c43ab-15f2-4f0f-b1ff-d10bf02b84bc'); // "📤 Email" on Clients - used to dedupe

// ---------------------------------------------------------------------------
// HTTP helpers
// ---------------------------------------------------------------------------

function clickupRequest(string $method, string $path, ?array $body = null): array
{
    $maxAttempts = 3;
    $lastError   = null;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $ch = curl_init(CLICKUP_API_BASE . $path);

        $headers = [
            'Authorization: ' . CLICKUP_API_TOKEN,
            'Content-Type: application/json',
            'User-Agent: BuilpireClickUpSync/1.0 (+PHP cURL)',
        ];

        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ];

        // Optional: route through a proxy if 'clickup_proxy' is set in config.php
        // (e.g. 'clickup_proxy' => 'http://user:pass@proxyhost:port') - useful if
        // your network is intermittently geo-blocked by ClickUp's CDN.
        global $__config;
        if (!empty($__config['clickup_proxy'])) {
            $opts[CURLOPT_PROXY] = $__config['clickup_proxy'];
        }

        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }

        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $lastError = new RuntimeException("cURL error calling {$path}: {$error}");
        } elseif ($status === 403 && stripos((string) $response, 'cloudfront') !== false) {
            // Transient edge-level block - worth a retry, not worth failing on immediately.
            $lastError = new RuntimeException("ClickUp API blocked at CDN edge (403 CloudFront) on {$path}");
        } else {
            $decoded = json_decode($response, true);

            if ($status >= 400) {
                $msg = $decoded['err'] ?? $response;
                throw new RuntimeException("ClickUp API error ({$status}) on {$path}: {$msg}");
            }

            return $decoded ?? [];
        }

        if ($attempt < $maxAttempts) {
            usleep(500000 * $attempt); // 0.5s, then 1s backoff
        }
    }

    throw $lastError;
}

/**
 * Fetch the live fields/values JSON (same shape as filds_fills.js).
 */
/**
 * Fetch the live fields/values JSON (same shape as filds_fills.js).
 * If $source is a local filesystem path (not http:// or https://), it's read
 * directly - this avoids the server making a self-referential HTTPS request
 * to itself, which many hosts (WAFs, Imunify360, mod_security, etc.) block.
 */
function fetchLiveFieldsJson(string $source): array
{
    $isRemote = preg_match('#^https?://#i', $source) === 1;

    if ($isRemote) {
        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => implode("\r\n", [
                    'User-Agent: BuilpireClickUpSync/1.0 (+PHP file_get_contents)',
                    'Accept: application/json, text/javascript, */*',
                ]),
                'timeout'       => 20,
                'ignore_errors' => true, // so we can inspect 4xx/5xx bodies instead of a bare warning
            ],
        ]);

        $raw = @file_get_contents($source, false, $context);

        $status = 0;
        if (isset($http_response_header)) {
            foreach ($http_response_header as $headerLine) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $headerLine, $m)) {
                    $status = (int) $m[1];
                }
            }
        }

        if ($raw === false) {
            throw new RuntimeException("Failed to fetch live fields JSON from {$source} (file_get_contents failed - check allow_url_fopen is enabled).");
        }

        if ($status >= 400 || $raw === '') {
            $snippet = substr((string) $raw, 0, 200);
            throw new RuntimeException(
                "Live fields JSON request returned HTTP {$status} with " .
                (($raw === '') ? 'an empty body' : "body: {$snippet}") .
                '. If this file lives on the same server as this script, pass a local ' .
                'filesystem path instead of an https:// URL to skip the network entirely.'
            );
        }
    } else {
        if (!is_readable($source)) {
            throw new RuntimeException("Live fields JSON file not found or not readable: {$source}");
        }
        $raw = file_get_contents($source);
        if ($raw === false) {
            throw new RuntimeException("Failed to read live fields JSON file: {$source}");
        }
    }

    // Strip a UTF-8 BOM if present - PHP's json_decode chokes on it even
    // though the rest of the file is valid JSON.
    $bom = "\xEF\xBB\xBF";
    if (substr($raw, 0, 3) === $bom) {
        $raw = substr($raw, 3);
    }
    $raw = trim($raw);

    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $snippet = substr($raw, 0, 200);
        throw new RuntimeException(
            'Live fields JSON is not valid JSON: ' . json_last_error_msg() .
            " | First 200 chars of response: " . $snippet
        );
    }

    return $data;
}

/**
 * Geocode a plain-text address to {lat, lng} using OpenStreetMap's free
 * Nominatim API (no API key needed). Returns null if it can't be resolved.
 * Note: Nominatim's usage policy requires a descriptive User-Agent and asks
 * for max ~1 request/second - fine for this low-volume use case, but swap
 * in Google's Geocoding API here instead if you need higher volume/accuracy.
 */
function geocodeAddress(string $address): ?array
{
    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q'      => $address,
        'format' => 'json',
        'limit'  => 1,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: BuilpireClickUpSync/1.0 (contact: dev@builpire.com)',
        ],
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err || !$raw) {
        return null;
    }

    $results = json_decode($raw, true);
    if (empty($results[0]['lat']) || empty($results[0]['lon'])) {
        return null;
    }

    return [
        'lat' => (float) $results[0]['lat'],
        'lng' => (float) $results[0]['lon'],
    ];
}

// ---------------------------------------------------------------------------
// Field-value conversion
// ---------------------------------------------------------------------------

/**
 * ClickUp's phone Custom Field requires a country code (e.g. "+15622983297"),
 * not human formatting like "(562) 298-3297". This strips formatting and
 * assumes US/Canada (+1) when no country code is present - adjust the
 * default-country logic here if you ever process international numbers.
 */
function normalizePhoneForClickUp(string $raw): string
{
    $trimmed = trim($raw);
    if ($trimmed === '') {
        return '';
    }

    $hasPlus = strpos($trimmed, '+') === 0;
    $digits  = preg_replace('/\D/', '', $trimmed);

    if ($hasPlus) {
        return '+' . $digits; // already had an explicit country code
    }
    if (strlen($digits) === 10) {
        return '+1' . $digits; // bare 10-digit US/Canada number
    }
    if (strlen($digits) === 11 && $digits[0] === '1') {
        return '+' . $digits; // 11 digits already starting with US/Canada's "1"
    }

    return '+' . $digits; // best-effort fallback for anything else
}

/**
 * Convert one field definition (id/title/type/value/options) from the JSON
 * into the payload ClickUp's "Set Custom Field Value" endpoint expects.
 * Returns null if the field should be skipped (empty value, attachment, etc.)
 * Throws if a value is present but can't be resolved (e.g. geocoding fails),
 * so applyFields() can log it as a warning rather than sending a bad request.
 */
function buildFieldValuePayload(array $field): ?array
{
    $type  = $field['type'];
    $value = $field['value'] ?? '';

    if ($value === '' || $value === null) {
        return null; // nothing to write
    }

    switch ($type) {
        case 'short_text':
        case 'email':
        case 'url':
            return ['value' => (string) $value];

        case 'phone':
            return ['value' => normalizePhoneForClickUp((string) $value)];

        case 'currency':
        case 'number':
            return ['value' => is_numeric($value) ? $value + 0 : null];

        case 'drop_down':
            // JSON stores the option's display name; translate to its UUID.
            foreach (($field['options'] ?? []) as $option) {
                if (trim($option['name']) === trim($value)) {
                    return ['value' => $option['id']];
                }
            }
            // No matching option found - skip rather than send garbage.
            return null;

        case 'location':
            $coords = geocodeAddress((string) $value);
            if ($coords === null) {
                throw new RuntimeException("Could not geocode address \"{$value}\"");
            }
            return [
                'value' => [
                    'location' => [
                        'lat' => $coords['lat'],
                        'lng' => $coords['lng'],
                    ],
                    'formatted_address' => (string) $value,
                ],
            ];

        case 'list_relationship':
            // Handled separately (needs a related task_id, not a raw value).
            return null;

        case 'attachment':
            // Needs the separate file-upload endpoint - not handled here.
            return null;

        default:
            // Fallback: send as plain string.
            return ['value' => (string) $value];
    }
}

/**
 * Push every fillable field on $fields to $taskId.
 * A bad value on one field (e.g. an invalid phone number) is logged and
 * skipped rather than aborting the whole task/sync.
 *
 * @return string[] List of "Field Title: error message" warnings, if any.
 */
function applyFields(string $taskId, array $fields, ?Closure $log = null): array
{
    $warnings = [];

    foreach ($fields as $field) {
        try {
            $payload = buildFieldValuePayload($field);
            if ($payload === null) {
                continue;
            }
            clickupRequest('POST', "/task/{$taskId}/field/{$field['id']}", $payload);
        } catch (Throwable $e) {
            $warning = "{$field['title']}: {$e->getMessage()}";
            $warnings[] = $warning;
            if ($log !== null) {
                $log("  WARNING - skipped field \"{$field['title']}\": {$e->getMessage()}");
            }
        }
    }

    return $warnings;
}

/**
 * Set a list_relationship field to point at one related task.
 */
function linkRelatedTask(string $taskId, string $fieldId, string $relatedTaskId): void
{
    clickupRequest('POST', "/task/{$taskId}/field/{$fieldId}", [
        'value' => [
            'add' => [$relatedTaskId],
            'rem' => [],
        ],
    ]);
}

// ---------------------------------------------------------------------------
// Task creation
// ---------------------------------------------------------------------------

function createTask(string $listId, string $name, ?int $customItemId = null): string
{
    $body = ['name' => $name];
    if ($customItemId !== null) {
        $body['custom_item_id'] = $customItemId;
    }

    $result = clickupRequest('POST', "/list/{$listId}/task", $body);

    if (empty($result['id'])) {
        throw new RuntimeException("ClickUp did not return a task id when creating '{$name}'");
    }

    return $result['id'];
}

/**
 * Fetch all Custom Task Types defined for the Workspace/Team.
 * Each item looks like: {"id": 123, "name": "Deal", "name_plural": "Deals", ...}
 */
function getCustomTaskTypes(string $teamId): array
{
    $result = clickupRequest('GET', "/team/{$teamId}/custom_item");
    return $result['custom_items'] ?? [];
}

/**
 * Find a Custom Task Type's ID by its name (case-insensitive, matches
 * singular or plural name). Returns null if not found - the task will then
 * fall back to the list's default type.
 */
function findCustomItemId(array $customTaskTypes, string $wantedName): ?int
{
    $wanted = mb_strtolower(trim($wantedName));
    foreach ($customTaskTypes as $type) {
        $singular = mb_strtolower(trim($type['name'] ?? ''));
        $plural   = mb_strtolower(trim($type['name_plural'] ?? ''));
        if ($wanted === $singular || $wanted === $plural) {
            return (int) $type['id'];
        }
    }
    return null;
}

// ---------------------------------------------------------------------------
// Duplicate detection
// ---------------------------------------------------------------------------

/**
 * Read a field's "value" out of a fields[] array (as found in the live JSON),
 * by field id. Returns '' if not present.
 */
function getFieldValue(array $fields, string $fieldId): string
{
    foreach ($fields as $field) {
        if ($field['id'] === $fieldId) {
            return (string) ($field['value'] ?? '');
        }
    }
    return '';
}

/**
 * Find tasks in a list whose custom field exactly matches a value.
 * Uses ClickUp's Get Tasks "custom_fields" filter.
 */
function clickupGetTasksByCustomField(string $listId, string $fieldId, string $value): array
{
    if ($value === '') {
        return [];
    }
    $filter = json_encode([
        ['field_id' => $fieldId, 'operator' => '=', 'value' => $value],
    ]);
    $query  = http_build_query(['custom_fields' => $filter]);
    $result = clickupRequest('GET', "/list/{$listId}/task?{$query}");
    return $result['tasks'] ?? [];
}

/**
 * Find an existing Deal/Payment task by its "🅱️ ID" custom field value.
 * Returns the task id, or null if none found.
 */
function findExistingIdFieldTaskId(string $listId, string $fieldId, string $idValue): ?string
{
    $tasks = clickupGetTasksByCustomField($listId, $fieldId, $idValue);
    return $tasks[0]['id'] ?? null;
}

/**
 * Find an existing Client task, preferring an exact Email match (reliable),
 * falling back to an exact task-name match (paginated, capped at 500 tasks)
 * if no email is available to match on.
 */
function findExistingClientTaskId(string $clientsListId, string $emailFieldId, string $email, string $clientName): ?string
{
    if ($email !== '') {
        $tasks = clickupGetTasksByCustomField($clientsListId, $emailFieldId, $email);
        if (!empty($tasks[0]['id'])) {
            return $tasks[0]['id'];
        }
    }

    // Fallback: exact name match. Capped at 5 pages (~500 tasks) as a safety limit.
    for ($page = 0; $page < 5; $page++) {
        $result = clickupRequest('GET', "/list/{$clientsListId}/task?page={$page}");
        $tasks  = $result['tasks'] ?? [];
        foreach ($tasks as $t) {
            if (($t['name'] ?? '') === $clientName) {
                return $t['id'];
            }
        }
        if (count($tasks) < 100) {
            break; // last page
        }
    }

    return null;
}

// ---------------------------------------------------------------------------
// Main orchestration
// ---------------------------------------------------------------------------

/**
 * @param string $liveFieldsJsonUrl URL that returns the same structure as filds_fills.js
 * @param string $id                Deal/Payment ID (e.g. from your email parser)
 * @param string $name              Client name only (no ID)
 * @param string $fullName          Full name used for Deal/Payment naming
 * @return array{client_task_id:string, deal_task_id:string, payment_task_id:string}
 */
function syncToClickUp(string $liveFieldsJsonUrl, string $id, string $name, string $fullName, bool $verbose = true): array
{
    $log = function (string $msg) use ($verbose) {
        if ($verbose) {
            echo $msg . PHP_EOL;
        }
    };

    $log("Fetching live fields JSON from {$liveFieldsJsonUrl} ...");
    $data = fetchLiveFieldsJson($liveFieldsJsonUrl);
    $log('Live fields JSON loaded OK.');

    foreach (['Deals', 'Clients', 'Payments'] as $section) {
        if (!isset($data[$section]['list_id'], $data[$section]['fields'])) {
            throw new RuntimeException("Live fields JSON is missing the '{$section}' section");
        }
    }

    $dealName    = "{$fullName} | {$id}";
    $paymentName = "{$fullName} | {$id}";
    $clientName  = $name;
    $warnings    = [];

    // -------------------------------------------------------------------
    // Duplicate check - reuse whatever already exists instead of recreating.
    // Deal/Payment are matched by the "🅱️ ID" field == $id. Client is
    // matched by Email (reliable) if present in the JSON, else by exact name.
    // -------------------------------------------------------------------
    $log('Checking for existing Client/Deal/Payment before creating anything...');
    $clientEmailForDedup = getFieldValue($data['Clients']['fields'], FIELD_ID_CLIENT_EMAIL);
    $existingClientId    = findExistingClientTaskId($data['Clients']['list_id'], FIELD_ID_CLIENT_EMAIL, $clientEmailForDedup, $clientName);
    $existingDealId      = findExistingIdFieldTaskId($data['Deals']['list_id'], FIELD_ID_DEAL_ID, $id);
    $existingPaymentId   = findExistingIdFieldTaskId($data['Payments']['list_id'], FIELD_ID_PAYMENT_ID, $id);

    if ($existingClientId && $existingDealId && $existingPaymentId) {
        $log('Client, Deal, and Payment all already exist for this ID - skipping creation entirely, no duplicates made.');
        return [
            'client_task_id'   => $existingClientId,
            'deal_task_id'     => $existingDealId,
            'payment_task_id'  => $existingPaymentId,
            'warnings'         => [],
            'skipped_existing' => true,
            'created'          => ['client' => false, 'deal' => false, 'payment' => false],
        ];
    }

    // 1) Client - created first (if it doesn't already exist) so Deal/Payment can link to it.
    if ($existingClientId) {
        $clientTaskId = $existingClientId;
        $log("Client already exists ({$clientTaskId}) - reusing it, not creating a duplicate.");
    } else {
        $log("Creating Client task '{$clientName}' in list {$data['Clients']['list_id']} ...");
        $clientTaskId = createTask($data['Clients']['list_id'], $clientName);
        $log("Client task created: {$clientTaskId}");
        $warnings = array_merge($warnings, applyFields($clientTaskId, $data['Clients']['fields'], $log));
        $log('Client fields applied.');
    }

    // 2) Deal (if it doesn't already exist)
    if ($existingDealId) {
        $dealTaskId = $existingDealId;
        $log("Deal already exists ({$dealTaskId}) - reusing it, not creating a duplicate.");
    } else {
        $dealFields = $data['Deals']['fields'];
        foreach ($dealFields as &$f) {
            if ($f['id'] === FIELD_ID_DEAL_ID) {
                $f['value'] = $id;
            }
            if ($f['id'] === FIELD_ID_DEAL_PROJECT_STATUS && NEW_DEAL_PROJECT_STATUS !== null) {
                $f['value'] = NEW_DEAL_PROJECT_STATUS;
            }
        }
        unset($f);
        $log("Creating Deal task '{$dealName}' in list {$data['Deals']['list_id']} ...");
        $dealCustomItemId = null;
        if (DEALS_CUSTOM_TASK_TYPE_NAME !== null) {
            try {
                $taskTypes = getCustomTaskTypes(CLICKUP_TEAM_ID);
                $dealCustomItemId = findCustomItemId($taskTypes, DEALS_CUSTOM_TASK_TYPE_NAME);
                if ($dealCustomItemId === null) {
                    $log('  NOTE: Could not find a "' . DEALS_CUSTOM_TASK_TYPE_NAME . '" task type - using list default.');
                } else {
                    $log('  Using "' . DEALS_CUSTOM_TASK_TYPE_NAME . '" task type (id ' . $dealCustomItemId . ') for the Deal task.');
                }
            } catch (Throwable $e) {
                $log('  NOTE: Could not look up Custom Task Types (' . $e->getMessage() . ') - using list default.');
            }
        }
        $dealTaskId = createTask($data['Deals']['list_id'], $dealName, $dealCustomItemId);
        $log("Deal task created: {$dealTaskId}");
        $warnings = array_merge($warnings, applyFields($dealTaskId, $dealFields, $log));
    }

    // Project is the hub: keep the project visible with its client, but link
    // both the payment and client side back to the project instead of making
    // the project depend on the client.
    try {
        linkRelatedTask($dealTaskId, FIELD_ID_DEAL_CLIENT, $clientTaskId);
        $log('Project fields applied and linked to Client.');
    } catch (Throwable $e) {
        $warnings[] = "Deal->Client link: {$e->getMessage()}";
        $log("  WARNING - could not link Project to Client: {$e->getMessage()}");
    }

    // 3) Payment (if it doesn't already exist)
    if ($existingPaymentId) {
        $paymentTaskId = $existingPaymentId;
        $log("Payment already exists ({$paymentTaskId}) - reusing it, not creating a duplicate.");
    } else {
        $paymentFields = $data['Payments']['fields'];
        foreach ($paymentFields as &$f) {
            if ($f['id'] === FIELD_ID_PAYMENT_ID) {
                $f['value'] = $id;
            }
        }
        unset($f);
        $log("Creating Payment task '{$paymentName}' in list {$data['Payments']['list_id']} ...");
        $paymentTaskId = createTask($data['Payments']['list_id'], $paymentName);
        $log("Payment task created: {$paymentTaskId}");
        $warnings = array_merge($warnings, applyFields($paymentTaskId, $paymentFields, $log));
    }

    try {
        // Centralize the relationship on the Project: payment belongs to the
        // project, not the client.
        linkRelatedTask($paymentTaskId, FIELD_ID_PAYMENT_CLIENT, $dealTaskId);
        $log('Payment fields applied and linked to Project.');
    } catch (Throwable $e) {
        $warnings[] = "Payment->Project link: {$e->getMessage()}";
        $log("  WARNING - could not link Payment to Project: {$e->getMessage()}");
    }

    if (!empty($warnings)) {
        $log('Completed with ' . count($warnings) . ' field warning(s) - see above.');
    }

    return [
        'client_task_id'  => $clientTaskId,
        'deal_task_id'    => $dealTaskId,
        'payment_task_id' => $paymentTaskId,
        'warnings'        => $warnings,
        // Which of these three were NEWLY created by this run (vs. found
        // and reused via the dedup check above). Critical for
        // reset_test_data.php: it must ONLY ever delete tasks this software
        // itself created, never a pre-existing task it merely matched/reused.
        'created'         => [
            'client'  => $existingClientId === null,
            'deal'    => $existingDealId === null,
            'payment' => $existingPaymentId === null,
        ],
    ];
}

/**
 * Add a comment to a task.
 */
function addCommentToTask(string $taskId, string $commentText): void
{
    clickupRequest('POST', "/task/{$taskId}/comment", [
        'comment_text' => $commentText,
    ]);
}

/**
 * Post a note as a comment on an already-identified Deal task. The caller
 * (process_note.php) is responsible for resolving which Deal task id this
 * note belongs to (via the local deal index, not an API lookup - see
 * deal_index.php) - this function just posts it.
 */
function attachNoteToDeal(string $dealTaskId, string $noteText, string $sourceLink = ''): void
{
    $commentText = $noteText;
    if ($sourceLink !== '') {
        $commentText .= "\n\n" . $sourceLink;
    }
    addCommentToTask($dealTaskId, $commentText);
}

/**
 * Permanently delete a task from ClickUp. Used by reset_test_data.php to
 * clean up after a test batch - there is no undo beyond ClickUp's own Trash
 * retention, so callers should confirm with the user before calling this.
 */
function deleteTaskById(string $taskId): void
{
    clickupRequest('DELETE', "/task/{$taskId}");
}

// ---------------------------------------------------------------------------
// End of library.
//
// Do NOT add a require of this file, or any test/example code, below this
// line. That causes clickup_sync.php to require itself and triggers
// "Cannot redeclare clickupRequest()" fatal errors.
//
// To test this library, use a SEPARATE file (e.g. filing_sample_1.php) that
// does:
//   require __DIR__ . '/clickup_sync.php';
//   syncToClickUp($liveFieldsJsonUrl, $id, $name, $fullName);
// ---------------------------------------------------------------------------
