<?php
/**
 * Telegram Bot integration — send messages via Bot API.
 */

function telegramApi(string $botToken, string $method, array $params = []): array {
    $url = "https://api.telegram.org/bot{$botToken}/{$method}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($params),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    $resp = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $resp ?? [];
}

function telegramSendMessage(string $botToken, string $chatId, string $text, string $parseMode = 'HTML', bool $silent = false): array {
    return telegramApi($botToken, 'sendMessage', [
        'chat_id'             => $chatId,
        'text'                => $text,
        'parse_mode'          => $parseMode,
        'disable_notification' => $silent,
    ]);
}
