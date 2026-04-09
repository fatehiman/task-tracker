<?php
/**
 * Cron job — forwards unread Slack messages to Telegram.
 * Intended to run every ~20 seconds.
 */

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/slack.php';
require_once __DIR__ . '/telegram.php';

$tmpFile = __DIR__ . '/cron-slack.tmp';

// Prevent re-entrance via flock
$fp = fopen($tmpFile, 'c+');
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    fclose($fp);
    exit;
}

// Load previously sent message IDs
$contents = stream_get_contents($fp);
$data = $contents ? json_decode($contents, true) : [];
$sentIds = $data['sent'] ?? [];

if (empty($slackToken) || empty($telegramBotToken) || empty($telegramChatId)) {
    flock($fp, LOCK_UN);
    fclose($fp);
    exit;
}

$ignoreList = array_map('trim', explode(',', $slackIgnoreChannels ?? ''));
$userCache = [];

// Get all conversations
$channels = [];
$cursor = '';
do {
    $params = http_build_query(array_filter([
        'types'            => 'public_channel,private_channel,mpim,im',
        'exclude_archived' => 'true',
        'limit'            => 200,
        'cursor'           => $cursor,
    ]));
    $resp = slackApi("https://slack.com/api/users.conversations?{$params}", $slackToken);
    if (empty($resp['ok'])) break;
    foreach ($resp['channels'] ?? [] as $ch) {
        $channels[] = $ch;
    }
    $cursor = $resp['response_metadata']['next_cursor'] ?? '';
} while ($cursor);

$newSentIds = [];

foreach ($channels as $ch) {
    $id    = $ch['id'];
    $name  = $ch['name'] ?? '';
    $isIm  = $ch['is_im'] ?? false;
    $isMpim = $ch['is_mpim'] ?? false;

    // Resolve DM display names
    if ($isIm) {
        $userId = $ch['user'] ?? '';
        if ($userId && !isset($userCache[$userId])) {
            $uData = slackApi("https://slack.com/api/users.info?user={$userId}", $slackToken);
            $userCache[$userId] = $uData['user']['profile']['display_name']
                ?? $uData['user']['profile']['real_name']
                ?? $uData['user']['name']
                ?? $userId;
        }
        $name = $userCache[$userId] ?? $userId;
    } elseif ($isMpim) {
        $name = $ch['purpose']['value'] ?? $name;
    }

    if (in_array($name, $ignoreList)) continue;

    // Get last_read timestamp
    $info = slackApi("https://slack.com/api/conversations.info?channel={$id}", $slackToken);
    if (empty($info['ok'])) continue;

    $lastRead = $info['channel']['last_read'] ?? '0';

    // Fetch messages after last_read
    $hist = slackApi("https://slack.com/api/conversations.history?channel={$id}&oldest={$lastRead}&limit=50", $slackToken);
    $messages = $hist['messages'] ?? [];

    // Filter: exclude last_read itself, already sent, and messages older than 1 minute
    $oneMinAgo = (string)(time() - 60);
    foreach ($messages as $msg) {
        $ts = $msg['ts'] ?? '';
        if ($ts === $lastRead) continue;
        if ($ts < $oneMinAgo) continue;
        if (isset($sentIds[$ts])) {
            $newSentIds[$ts] = $sentIds[$ts];
            continue;
        }

        $text = $msg['text'] ?? '';
        if ($text === '') continue;

        // Resolve sender name
        $senderId = $msg['user'] ?? '';
        $senderName = $senderId;
        if ($senderId && !isset($userCache[$senderId])) {
            $uData = slackApi("https://slack.com/api/users.info?user={$senderId}", $slackToken);
            $userCache[$senderId] = $uData['user']['profile']['display_name']
                ?? $uData['user']['profile']['real_name']
                ?? $uData['user']['name']
                ?? $senderId;
        }
        if ($senderId) {
            $senderName = $userCache[$senderId] ?? $senderId;
        }

        // Build Telegram message
        $prefix = ($isIm || $isMpim) ? $name : "#{$name}";
        $tgText = "<b>{$prefix}</b>\n<b>{$senderName}</b>: " . htmlspecialchars($text);

        telegramSendMessage($telegramBotToken, $telegramChatId, $tgText);

        $newSentIds[$ts] = time();
    }
}

// Merge and prune entries older than 24 hours
$cutoff = time() - 86400;
foreach ($sentIds as $ts => $sentAt) {
    if ($sentAt > $cutoff && !isset($newSentIds[$ts])) {
        $newSentIds[$ts] = $sentAt;
    }
}

// Write back to tmp file
ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode(['sent' => $newSentIds]));
flock($fp, LOCK_UN);
fclose($fp);
