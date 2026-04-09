<?php
/**
 * Slack integration — fetches channels with unread messages.
 */

function slackApi(string $url, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$token}",
            'Content-Type: application/x-www-form-urlencoded',
        ],
    ]);
    $resp = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $resp ?? [];
}

function fetchSlackUnread(string $token, string $ignoreChannels = ''): array {
    $ignoreList = array_map('trim', explode(',', $ignoreChannels));

    // Get all conversations the user is a member of
    $channels = [];
    $cursor = '';
    do {
        $params = http_build_query(array_filter([
            'types'            => 'public_channel,private_channel,mpim,im',
            'exclude_archived' => 'true',
            'limit'            => 200,
            'cursor'           => $cursor,
        ]));
        $data = slackApi("https://slack.com/api/users.conversations?{$params}", $token);
        if (empty($data['ok'])) break;

        foreach ($data['channels'] ?? [] as $ch) {
            $channels[] = $ch;
        }
        $cursor = $data['response_metadata']['next_cursor'] ?? '';
    } while ($cursor);

    // For IMs/MPIMs, resolve display names
    $userCache = [];

    $unread = [];
    foreach ($channels as $ch) {
        $id   = $ch['id'];
        $name = $ch['name'] ?? '';
        $isIm = ($ch['is_im'] ?? false);
        $isMpim = ($ch['is_mpim'] ?? false);

        // For DMs, resolve the user's display name
        if ($isIm) {
            $userId = $ch['user'] ?? '';
            if ($userId && !isset($userCache[$userId])) {
                $uData = slackApi("https://slack.com/api/users.info?user={$userId}", $token);
                $userCache[$userId] = $uData['user']['profile']['display_name']
                    ?? $uData['user']['profile']['real_name']
                    ?? $uData['user']['name']
                    ?? $userId;
            }
            $name = $userCache[$userId] ?? $userId;
        } elseif ($isMpim) {
            // Group DMs have a generated name like "mpdm-user1--user2--user3-1"
            $name = $ch['purpose']['value'] ?? $name;
        }

        if (in_array($name, $ignoreList)) continue;

        // Get channel info for last_read, then compare with latest message
        $info = slackApi("https://slack.com/api/conversations.info?channel={$id}", $token);
        if (empty($info['ok'])) continue;

        $chInfo = $info['channel'];
        $unreadCount = $chInfo['unread_count_display'] ?? null;

        if ($unreadCount === null) {
            // For channels: compare last_read with latest message timestamp
            $lastRead = $chInfo['last_read'] ?? '0';
            $hist = slackApi("https://slack.com/api/conversations.history?channel={$id}&oldest={$lastRead}&limit=100", $token);
            // Exclude the message at exactly last_read (oldest is inclusive)
            $msgs = array_filter($hist['messages'] ?? [], fn($m) => ($m['ts'] ?? '') !== $lastRead);
            $unreadCount = count($msgs);
        }

        if ($unreadCount > 0) {
            $unread[] = [
                'channel_id'   => $id,
                'name'         => $name,
                'unread_count' => $unreadCount,
                'is_im'        => $isIm,
                'is_mpim'      => $isMpim,
            ];
        }
    }

    // Sort: DMs first, then by unread count descending
    usort($unread, function($a, $b) {
        $aIsDm = $a['is_im'] || $a['is_mpim'];
        $bIsDm = $b['is_im'] || $b['is_mpim'];
        if ($aIsDm !== $bIsDm) return $bIsDm <=> $aIsDm;
        return $b['unread_count'] <=> $a['unread_count'];
    });

    return $unread;
}
