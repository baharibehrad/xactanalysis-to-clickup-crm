<?php
/**
 * filing_sample_1.php
 *
 * Simple test/demo script for syncToClickUp().
 * This file should stay small — all the actual logic lives in clickup_sync.php.
 * Do NOT paste the contents of clickup_sync.php in here again; just require it.
 */

require __DIR__ . '/clickup_sync.php';

try {
 
    $result = syncToClickUp(
        __DIR__ . '/filds_fills.js',   // local file path, not an https:// URL
        '12345',
        'Jaaaane Dooooe',
        'Jaaaane Dooooe'
    );
    print_r($result);
} catch (Throwable $e) {
    echo 'ClickUp sync failed: ' . $e->getMessage() . PHP_EOL; // visible while debugging
    error_log('ClickUp sync failed: ' . $e->getMessage());     // also logged for production
}