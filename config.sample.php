<?php
/**
 * config.sample.php
 *
 * Copy this file to `config.php` and fill in your own credentials.
 * `config.php` is git-ignored so your real tokens never get committed.
 */

return [
    // ─── Database ───────────────────────────────────────
    'db_host'     => 'localhost',
    'db_name'     => 'clickup_crm',
    'db_user'     => 'root',
    'db_password' => '',

    // ─── ClickUp ────────────────────────────────────────
    // Create a personal token at: ClickUp → Settings → Apps → API Token
    'clickup_token'   => 'pk_XXXXXXXX_YOUR_CLICKUP_PERSONAL_TOKEN',
    // Your Workspace/Team ID (the number in app.clickup.com/{team_id}/...)
    'clickup_list_id' => 'YOUR_TEAM_ID',

    // ─── Telegram (optional notifications) ──────────────
    'telegramBotToken' => 'YOUR_TELEGRAM_BOT_TOKEN',
    'telegramChatId'   => 'YOUR_TELEGRAM_CHAT_ID',

    // ─── Verisk / XactAnalysis (optional) ───────────────
    'xa_token'    => 'YOUR_XA_TOKEN',
    'xa_base_url' => 'https://api.verisk.com',
];
