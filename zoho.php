<?php
/**
 * Zoho Calendar integration — fetches today's and next working day's events.
 */

function zohoRefreshAccessToken(string $clientId, string $clientSecret, string $refreshToken): string {
    $ch = curl_init('https://accounts.zoho.eu/oauth/v2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type'    => 'refresh_token',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
        ]),
    ]);
    $resp = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $resp['access_token'] ?? '';
}

function zohoCalendarApi(string $url, string $accessToken): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Zoho-oauthtoken {$accessToken}",
            'Content-Type: application/json',
        ],
    ]);
    $resp = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $resp ?? [];
}

function getNextWorkingDay(DateTimeImmutable $from): DateTimeImmutable {
    $next = $from->modify('+1 day');
    while (in_array($next->format('N'), ['6', '7'])) {
        $next = $next->modify('+1 day');
    }
    return $next;
}

/**
 * Parse Zoho datetime string like "20260408T090000+0200" into a DateTimeImmutable.
 */
function parseZohoDate(string $str): ?DateTimeImmutable {
    // Format: YYYYMMDDTHHmmSS+HHMM or YYYYMMDDTHHmmSSZ
    $str = trim($str);
    if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})(.*)$/', $str, $m)) {
        $iso = "{$m[1]}-{$m[2]}-{$m[3]}T{$m[4]}:{$m[5]}:{$m[6]}{$m[7]}";
        try {
            return new DateTimeImmutable($iso);
        } catch (Exception $e) {
            return null;
        }
    }
    return null;
}

/**
 * Check if a recurring event (with RRULE) occurs on a given date.
 */
function rruleMatchesDate(string $rrule, DateTimeImmutable $eventStart, string $targetDate): bool {
    $rules = [];
    foreach (explode(';', $rrule) as $part) {
        [$k, $v] = explode('=', $part, 2);
        $rules[strtoupper($k)] = $v;
    }

    $freq = $rules['FREQ'] ?? '';
    $interval = (int)($rules['INTERVAL'] ?? 1);
    $byDay = isset($rules['BYDAY']) ? explode(',', $rules['BYDAY']) : [];
    $until = $rules['UNTIL'] ?? null;
    $count = isset($rules['COUNT']) ? (int)$rules['COUNT'] : null;

    $target = new DateTimeImmutable($targetDate);

    // If event hasn't started yet
    if ($target < $eventStart->modify('midnight')) return false;

    // If UNTIL is set and target is past it
    if ($until) {
        $untilDate = parseZohoDate($until) ?? new DateTimeImmutable($until);
        if ($target > $untilDate) return false;
    }

    $dayMap = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];
    $targetDayNum = (int)$target->format('N');

    if ($freq === 'DAILY') {
        $daysDiff = (int)$target->diff($eventStart->modify('midnight'))->days;
        if ($interval > 1 && ($daysDiff % $interval !== 0)) return false;
        if (!empty($byDay)) {
            foreach ($byDay as $d) {
                if (($dayMap[$d] ?? 0) === $targetDayNum) return true;
            }
            return false;
        }
        return true;
    }

    if ($freq === 'WEEKLY') {
        $weeksDiff = (int)floor($target->diff($eventStart->modify('midnight'))->days / 7);
        if ($interval > 1 && ($weeksDiff % $interval !== 0)) return false;
        if (!empty($byDay)) {
            foreach ($byDay as $d) {
                if (($dayMap[$d] ?? 0) === $targetDayNum) return true;
            }
            return false;
        }
        return $target->format('N') === $eventStart->format('N');
    }

    if ($freq === 'MONTHLY') {
        if ($target->format('d') === $eventStart->format('d')) return true;
        return false;
    }

    return false;
}

function fetchZohoEvents(string $clientId, string $clientSecret, string $refreshToken, string $calendarId, string $ignoreEvents = ''): array {
    $ignoreList = array_map('trim', explode(',', $ignoreEvents));
    $accessToken = zohoRefreshAccessToken($clientId, $clientSecret, $refreshToken);
    if (!$accessToken) return [];

    $url = "https://calendar.zoho.eu/api/v1/calendars/{$calendarId}/events";
    $data = zohoCalendarApi($url, $accessToken);

    $tz = new DateTimeZone('Europe/Bucharest');
    $today = new DateTimeImmutable('today', $tz);
    $nextWork = getNextWorkingDay($today);

    $todayStr = $today->format('Y-m-d');
    $nextStr  = $nextWork->format('Y-m-d');
    $todayLabel = 'Today';
    $nextLabel  = $nextWork->format('D, M j');

    $events = [];

    foreach ($data['events'] ?? [] as $evt) {
        $title = $evt['title'] ?? 'Untitled';
        $dtInfo = $evt['dateandtime'] ?? [];
        $startStr = $dtInfo['start'] ?? '';
        $endStr   = $dtInfo['end'] ?? '';
        $rrule = $evt['rrule'] ?? '';

        $startDt = parseZohoDate($startStr);
        if (!$startDt) continue;

        $startTime = $startDt->setTimezone($tz)->format('H:i');
        $endDt = parseZohoDate($endStr);
        $endTime = $endDt ? $endDt->setTimezone($tz)->format('H:i') : '';

        if (in_array($title, $ignoreList)) continue;

        if ($rrule) {
            // Recurring event — check both days
            foreach ([$todayStr => $todayLabel, $nextStr => $nextLabel] as $dateStr => $label) {
                if (rruleMatchesDate($rrule, $startDt, $dateStr)) {
                    $events[] = [
                        'title' => $title,
                        'start' => $startTime,
                        'end'   => $endTime,
                        'date'  => $dateStr,
                        'label' => $label,
                    ];
                }
            }
        } else {
            // One-time event
            $evtDate = $startDt->setTimezone($tz)->format('Y-m-d');
            if ($evtDate === $todayStr || $evtDate === $nextStr) {
                $label = ($evtDate === $todayStr) ? $todayLabel : $nextLabel;
                $events[] = [
                    'title' => $title,
                    'start' => $startTime,
                    'end'   => $endTime,
                    'date'  => $evtDate,
                    'label' => $label,
                ];
            }
        }
    }

    usort($events, fn($a, $b) => ($a['date'] . $a['start']) <=> ($b['date'] . $b['start']));
    return $events;
}
