<?php
/**
 * telegram.php
 *
 * Sends a notification to your Telegram bot/chat (credentials in
 * config.php) - used for emails that don't fit the Deal/Note automation but
 * still need your attention, e.g. "QA Rejected Assignment Status" emails.
 */

function sendTelegramMessage(string $text): void
{
    $config = require __DIR__ . '/config.php';

    $url = 'https://api.telegram.org/bot' . $config['telegramBotToken'] . '/sendMessage';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'chat_id' => $config['telegramChatId'],
            'text'    => $text,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new RuntimeException("Telegram send failed (cURL error): {$error}");
    }
    if ($status >= 400) {
        throw new RuntimeException("Telegram API error ({$status}): {$response}");
    }
}
