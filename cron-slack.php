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

// Get authenticated user's ID to skip own messages
$authData = slackApi("https://slack.com/api/auth.test", $slackToken);
$myUserId = $authData['user_id'] ?? '';

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

    // Fetch recent messages and threads with recent replies
    $since = (string)(time() - 300);

    // Get recent top-level messages (including older ones with active threads)
    $hist = slackApi("https://slack.com/api/conversations.history?channel={$id}&limit=30", $slackToken);
    $messages = $hist['messages'] ?? [];

    // Collect all messages including thread replies from the last 5 minutes
    $allMessages = [];
    foreach ($messages as $msg) {
        $ts = $msg['ts'] ?? '';

        // Include top-level messages only if recent
        if ($ts >= $since) {
            $allMessages[] = $msg;
        }

        // Check threads: if latest reply is recent, fetch replies
        $replyCount = $msg['reply_count'] ?? 0;
        $latestReply = $msg['latest_reply'] ?? '0';
        if ($replyCount > 0 && $latestReply >= $since) {
            $threadTs = $msg['thread_ts'] ?? $ts;
            $replies = slackApi("https://slack.com/api/conversations.replies?channel={$id}&ts={$threadTs}&oldest={$since}&limit=50", $slackToken);
            foreach ($replies['messages'] ?? [] as $reply) {
                if (($reply['ts'] ?? '') === $threadTs) continue;
                $allMessages[] = $reply;
            }
        }
    }

    $prefix = $name;

    foreach ($allMessages as $msg) {
        $ts = $msg['ts'] ?? '';
        if (isset($sentIds[$ts])) {
            $newSentIds[$ts] = $sentIds[$ts];
            continue;
        }

        $text = $msg['text'] ?? '';
        if ($text === '') continue;

        // Skip own messages
        $senderId = $msg['user'] ?? '';
        if ($senderId === $myUserId) continue;

        // Resolve sender name
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

        // Build Telegram message (indicate thread replies)
        $isThread = isset($msg['thread_ts']) && $msg['ts'] !== $msg['thread_ts'];
        $label = $isThread ? "{$prefix} (thread)" : $prefix;
        $tgText = "<b>{$label}</b>\n<b>{$senderName}</b>: " . htmlspecialchars($text);

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
