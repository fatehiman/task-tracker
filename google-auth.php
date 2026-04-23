<?php
/**
 * One-off helper: obtain a Google refresh token using the configured OAuth client.
 *
 * Usage:
 *   1. Ensure http://localhost:8888 is in the OAuth client's Authorized redirect URIs.
 *   2. Run:  php google-auth.php
 *   3. Open the printed URL in a browser, sign in, grant access.
 *   4. Script prints the refresh token.
 */

require __DIR__ . '/env.php';

if (empty($googleClientId) || empty($googleClientSecret)) {
    fwrite(STDERR, "googleClientId / googleClientSecret not set in env.php\n");
    exit(1);
}

$redirectUri = 'http://localhost:8888';
$scope = 'https://www.googleapis.com/auth/calendar.readonly';

$authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id'     => $googleClientId,
    'redirect_uri'  => $redirectUri,
    'response_type' => 'code',
    'scope'         => $scope,
    'access_type'   => 'offline',
    'prompt'        => 'consent',
]);

echo "\nOpen this URL in your browser (signed in as the Google account whose calendar you want):\n\n";
echo "$authUrl\n\n";
echo "Listening on $redirectUri ...\n";

$server = stream_socket_server('tcp://127.0.0.1:8888', $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "Cannot bind port 8888: $errstr\n");
    exit(1);
}

$code = null;
while ($conn = stream_socket_accept($server, 120)) {
    $request = '';
    while (($line = fgets($conn)) !== false) {
        $request .= $line;
        if (rtrim($line) === '') break;
    }
    if (preg_match('#GET\s+/\?([^\s]+)\s+HTTP#', $request, $m)) {
        parse_str($m[1], $params);
        if (isset($params['error'])) {
            $body = "<h1>Authorization failed</h1><p>{$params['error']}</p>";
            fwrite($conn, "HTTP/1.1 400 Bad Request\r\nContent-Type: text/html\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n$body");
            fclose($conn);
            fclose($server);
            fwrite(STDERR, "Auth error: {$params['error']}\n");
            exit(1);
        }
        if (isset($params['code'])) {
            $code = $params['code'];
            $body = "<h1>Done</h1><p>You can close this tab.</p>";
            fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n$body");
            fclose($conn);
            break;
        }
    }
    $body = "Waiting for ?code=... redirect";
    fwrite($conn, "HTTP/1.1 404 Not Found\r\nContent-Type: text/plain\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n$body");
    fclose($conn);
}
fclose($server);

if (!$code) {
    fwrite(STDERR, "No authorization code received.\n");
    exit(1);
}

echo "\nAuth code received. Exchanging for tokens...\n";

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'code'          => $code,
        'client_id'     => $googleClientId,
        'client_secret' => $googleClientSecret,
        'redirect_uri'  => $redirectUri,
        'grant_type'    => 'authorization_code',
    ]),
]);
$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($resp, true);
if ($http !== 200 || empty($data['refresh_token'])) {
    fwrite(STDERR, "Token exchange failed (HTTP $http):\n$resp\n");
    exit(1);
}

echo "\n=== SUCCESS ===\n";
echo "Refresh token:\n{$data['refresh_token']}\n\n";
echo "Access token (for verification, expires in {$data['expires_in']}s):\n{$data['access_token']}\n";
