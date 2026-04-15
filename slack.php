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
    $ignoreList = array_filter(array_map('trim', explode(',', $ignoreChannels)), fn($v) => $v !== '');

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
    $resolveUser = function(string $userId) use ($token, &$userCache) {
        if ($userId === '') return '';
        if (!array_key_exists($userId, $userCache)) {
            $uData = slackApi("https://slack.com/api/users.info?user={$userId}", $token);
            $profile = $uData['user']['profile'] ?? [];
            $resolved = trim((string)($profile['display_name'] ?? ''));
            if ($resolved === '') $resolved = trim((string)($profile['real_name'] ?? ''));
            if ($resolved === '') $resolved = trim((string)($uData['user']['name'] ?? ''));
            if ($resolved === '') $resolved = $userId;
            $userCache[$userId] = $resolved;
        }
        return $userCache[$userId];
    };

    $unread = [];
    foreach ($channels as $ch) {
        $id   = $ch['id'];
        $name = $ch['name'] ?? '';
        $isIm = ($ch['is_im'] ?? false);
        $isMpim = ($ch['is_mpim'] ?? false);

        // For DMs, resolve the user's display name
        if ($isIm) {
            $name = $resolveUser($ch['user'] ?? '');
        } elseif ($isMpim) {
            // Group DMs: prefer purpose; fallback to resolving member display names
            $purpose = trim((string)($ch['purpose']['value'] ?? ''));
            if ($purpose !== '') {
                $name = $purpose;
            } else {
                $members = slackApi("https://slack.com/api/conversations.members?channel={$id}&limit=10", $token);
                $resolved = [];
                foreach ($members['members'] ?? [] as $uid) {
                    $n = $resolveUser($uid);
                    if ($n !== '') $resolved[] = $n;
                }
                if (!empty($resolved)) {
                    $name = implode(', ', $resolved);
                }
                // else keep the generated mpdm-... name as last-resort fallback
            }
        }

        // Skip entries with no resolvable name (prevents empty rows with just a badge)
        if (trim((string)$name) === '') continue;

        if (in_array($name, $ignoreList, true)) continue;

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
