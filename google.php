<?php
/**
 * Google Calendar integration — fetches today's and next working day's events.
 */

function googleRefreshAccessToken(string $clientId, string $clientSecret, string $refreshToken): string {
    $ch = curl_init('https://oauth2.googleapis.com/token');
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

function googleCalendarApi(string $url, string $accessToken): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$accessToken}",
            'Accept: application/json',
        ],
    ]);
    $resp = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $resp ?? [];
}

function fetchGoogleEvents(string $clientId, string $clientSecret, string $refreshToken, string $calendarId, string $ignoreEvents = ''): array {
    $ignoreList = array_map('trim', explode(',', $ignoreEvents));
    $accessToken = googleRefreshAccessToken($clientId, $clientSecret, $refreshToken);
    if (!$accessToken) return [];

    $tz = new DateTimeZone('Europe/Bucharest');
    $today = new DateTimeImmutable('today', $tz);
    $nextWork = getNextWorkingDay($today);

    $todayStr = $today->format('Y-m-d');
    $nextStr  = $nextWork->format('Y-m-d');
    $todayLabel = 'Today';
    $nextLabel  = $nextWork->format('D, M j');

    // Google Calendar API supports timeMin/timeMax and singleEvents=true expands recurring events
    $timeMin = $today->format('c');
    $timeMax = $nextWork->modify('+1 day')->format('c');

    $params = http_build_query([
        'timeMin'      => $timeMin,
        'timeMax'      => $timeMax,
        'singleEvents' => 'true',
        'orderBy'      => 'startTime',
    ]);
    $url = "https://www.googleapis.com/calendar/v3/calendars/" . urlencode($calendarId) . "/events?{$params}";
    $data = googleCalendarApi($url, $accessToken);

    $events = [];
    foreach ($data['items'] ?? [] as $evt) {
        if (($evt['status'] ?? '') === 'cancelled') continue;

        $title = $evt['summary'] ?? 'Untitled';
        if (in_array($title, $ignoreList)) continue;

        // Google returns dateTime for timed events, date for all-day events
        $startRaw = $evt['start']['dateTime'] ?? ($evt['start']['date'] ?? '');
        $endRaw   = $evt['end']['dateTime'] ?? ($evt['end']['date'] ?? '');

        if (!$startRaw) continue;

        $isAllDay = isset($evt['start']['date']) && !isset($evt['start']['dateTime']);

        if ($isAllDay) {
            $evtDate = $startRaw; // format: Y-m-d
            if ($evtDate !== $todayStr && $evtDate !== $nextStr) continue;
            $label = ($evtDate === $todayStr) ? $todayLabel : $nextLabel;
            $events[] = [
                'title' => $title,
                'start' => 'All day',
                'end'   => '',
                'date'  => $evtDate,
                'label' => $label,
            ];
        } else {
            $startDt = new DateTimeImmutable($startRaw);
            $startDt = $startDt->setTimezone($tz);
            $evtDate = $startDt->format('Y-m-d');
            if ($evtDate !== $todayStr && $evtDate !== $nextStr) continue;

            $startTime = $startDt->format('H:i');
            $endTime = '';
            if ($endRaw) {
                $endDt = new DateTimeImmutable($endRaw);
                $endTime = $endDt->setTimezone($tz)->format('H:i');
            }

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

    usort($events, fn($a, $b) => ($a['date'] . $a['start']) <=> ($b['date'] . $b['start']));
    return $events;
}
