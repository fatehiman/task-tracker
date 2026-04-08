<?php
set_time_limit(180);
require_once __DIR__ . '/env.php';

// Filters from POST (unchecked checkboxes are absent from POST)
$showMerged = isset($_POST['show_merged']);
$showClosed = isset($_POST['show_closed']);

// Get my Jira account ID once
$jiraMe = jiraApi("https://{$jiraDomain}/rest/api/3/myself", $jiraEmail, $jiraToken);
$jiraMyAccountId = $jiraMe['accountId'] ?? '';

function githubApi(string $url, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: token {$token}",
            'Accept: application/vnd.github.v3+json',
            'User-Agent: PHP-PR-Dashboard',
        ],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp, true) ?: [];
}

function githubGraphql(string $query, string $token): array {
    $ch = curl_init('https://api.github.com/graphql');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['query' => $query]),
        CURLOPT_HTTPHEADER => [
            "Authorization: token {$token}",
            'Content-Type: application/json',
            'User-Agent: PHP-PR-Dashboard',
        ],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp, true) ?: [];
}

function jiraApi(string $url, string $email, string $token, ?array $postData = null): array {
    $ch = curl_init($url);
    $headers = [
        'Authorization: Basic ' . base64_encode("{$email}:{$token}"),
        'Accept: application/json',
        'User-Agent: PHP-PR-Dashboard',
    ];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($postData !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($postData);
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER] = $headers;
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp, true) ?: [];
}

function jiraCommentStats(string $key, string $domain, string $email, string $token, string $myAccountId): array {
    if (!$key) return ['total' => 0, 'replied' => 0];
    $data = jiraApi("https://{$domain}/rest/api/3/issue/{$key}/comment", $email, $token);
    $comments = $data['comments'] ?? [];
    $otherComments = 0;
    $replied = 0;
    foreach ($comments as $i => $c) {
        $authorId = $c['author']['accountId'] ?? '';
        if ($authorId !== $myAccountId) {
            $otherComments++;
            for ($j = $i + 1; $j < count($comments); $j++) {
                if (($comments[$j]['author']['accountId'] ?? '') === $myAccountId) {
                    $replied++;
                    break;
                }
            }
        }
    }
    return ['total' => $otherComments, 'replied' => $replied];
}

function jiraTicketNum(string $key): string {
    return preg_match('/ED-(\d+)/', $key, $m) ? $m[1] : $key;
}

function renderTicketCell(string $num, string $key, string $parent, string $child, string $domain, string $parentStatus = '', string $childStatus = ''): string {
    $mainLink = $key ? '<a href="https://' . htmlspecialchars($domain) . '/browse/' . htmlspecialchars($key) . '" target="_blank">' . htmlspecialchars($num) . '</a>' : '-';

    if (!$parent && !$child) return $mainLink;

    $parentTitle = $parentStatus ? ' title="' . htmlspecialchars($parentStatus) . '"' : '';
    $childTitle  = $childStatus  ? ' title="' . htmlspecialchars($childStatus) . '"'  : '';
    $parentLink = $parent ? '<a class="ticket-rel"' . $parentTitle . ' href="https://' . htmlspecialchars($domain) . '/browse/' . htmlspecialchars($parent) . '" target="_blank">' . htmlspecialchars(jiraTicketNum($parent)) . '</a>' : '';
    $childLink  = $child  ? '<a class="ticket-rel"' . $childTitle . ' href="https://' . htmlspecialchars($domain) . '/browse/' . htmlspecialchars($child) . '" target="_blank">' . htmlspecialchars(jiraTicketNum($child)) . '</a>' : '';

    if ($parent && $child) {
        return $parentLink . ' <span class="ticket-arrow">&#10132;</span> ' . $mainLink . ' <span class="ticket-arrow">&#10132;</span> ' . $childLink;
    } elseif ($parent) {
        return $mainLink . ' <span class="ticket-arrow">&#10132;</span> ' . $parentLink;
    } else {
        return $mainLink . ' <span class="ticket-arrow">&#10132;</span> ' . $childLink;
    }
}

// Build search query based on filters
// GitHub search: "is:open" = open only, "is:closed" = closed+merged, no filter = all
// We always want open PRs. Merged and closed-unmerged are both "closed" in GitHub,
// so if neither filter is checked we restrict to open only.
$stateFilter = '';
if ($showMerged || $showClosed) {
    // Need closed PRs too (merged is a subset of closed) — fetch all, filter in PHP
    $stateFilter = '';
} else {
    $stateFilter = ' is:open';
}

$prs = [];
$page = 1;
do {
    $url = "https://api.github.com/search/issues?q=" . urlencode("repo:{$githubRepo} type:pr author:{$githubUser}{$stateFilter}") . "&sort=updated&order=desc&per_page=100&page={$page}";
    $result = githubApi($url, $githubToken);
    $items = $result['items'] ?? [];
    $prs = array_merge($prs, $items);
    $page++;
} while (count($items) === 100);

// For each PR, get merge status first to apply filters early, then fetch details
$rows = [];
$repoOwner = explode('/', $githubRepo)[0];
$repoName  = explode('/', $githubRepo)[1];

foreach ($prs as $pr) {
    $number = $pr['number'];
    $title  = $pr['title'];
    $state  = $pr['state']; // open or closed

    // For closed PRs, get detail to check if merged — skip early if filtered out
    $merged = false;
    if ($state === 'closed') {
        $detail = githubApi("https://api.github.com/repos/{$githubRepo}/pulls/{$number}", $githubToken);
        $merged = !empty($detail['merged']);
        if ($merged && !$showMerged) continue;
        if (!$merged && !$showClosed) continue;
    }

    $prStatus = $merged ? 'Merged' : ($state === 'open' ? 'Open' : 'Closed');

    // Extract ED ticket number from title like [ED-1234]
    $jiraKey = '';
    $jiraNum = '';
    if (preg_match('/\[ED-(\d+)\]/', $title, $m)) {
        $jiraNum = $m[1];
        $jiraKey = 'ED-' . $jiraNum;
    }

    // Single GraphQL call for reviews, review requests, and comment threads
    $gql = sprintf(
        '{ repository(owner:"%s", name:"%s") { pullRequest(number:%d) { mergeable author { login } reviewRequests(first:50) { nodes { requestedReviewer { ... on User { login } ... on Team { name } } } } reviews(first:100) { nodes { author { login } state } } reviewThreads(first:100) { totalCount nodes { isResolved comments(last:1) { nodes { author { login } } } } } } } }',
        $repoOwner, $repoName, $number
    );
    $gqlResult = githubGraphql($gql, $githubToken);
    $prData = $gqlResult['data']['repository']['pullRequest'] ?? [];

    // Check for merge conflicts
    $hasConflict = ($prData['mergeable'] ?? '') === 'CONFLICTING';

    // Count approvals: track latest review state per user
    $prAuthor = $prData['author']['login'] ?? '';
    $reviewerStates = [];
    foreach ($prData['reviews']['nodes'] ?? [] as $review) {
        $login = $review['author']['login'] ?? '';
        if ($login) {
            $reviewerStates[$login] = $review['state'];
        }
    }
    $approvalCount = count(array_filter($reviewerStates, fn($s) => $s === 'APPROVED'));

    // Total requested reviewers = unique users who reviewed + still-pending requests (exclude PR author)
    $allReviewers = [];
    foreach ($reviewerStates as $login => $s) {
        if ($login !== $prAuthor) {
            $allReviewers[$login] = true;
        }
    }
    foreach ($prData['reviewRequests']['nodes'] ?? [] as $rr) {
        $login = $rr['requestedReviewer']['login'] ?? ($rr['requestedReviewer']['name'] ?? '');
        if ($login && $login !== $prAuthor) {
            $allReviewers[$login] = true;
        }
    }
    $totalReviewers = count($allReviewers);

    // Detect reviewers who requested changes but haven't been re-requested
    $pendingLogins = [];
    foreach ($prData['reviewRequests']['nodes'] ?? [] as $rr) {
        $login = $rr['requestedReviewer']['login'] ?? ($rr['requestedReviewer']['name'] ?? '');
        if ($login) $pendingLogins[$login] = true;
    }
    $needsRerequest = false;
    foreach ($reviewerStates as $login => $s) {
        if ($s === 'CHANGES_REQUESTED' && !isset($pendingLogins[$login])) {
            $needsRerequest = true;
            break;
        }
    }

    // Comment threads
    $threads = $prData['reviewThreads'] ?? ['totalCount' => 0, 'nodes' => []];
    $totalComments = $threads['totalCount'];
    $resolvedComments = 0;
    $repliedComments = 0;
    foreach ($threads['nodes'] as $thread) {
        if (!empty($thread['isResolved'])) {
            $resolvedComments++;
            $repliedComments++;
        } else {
            $lastAuthor = $thread['comments']['nodes'][0]['author']['login'] ?? '';
            if ($lastAuthor === $prAuthor) {
                $repliedComments++;
            }
        }
    }

    // Get Jira status + parent/child
    $jiraStatus = '-';
    $jiraParent = '';
    $jiraChild  = '';
    $jiraParentStatus = '';
    $jiraChildStatus  = '';
    if ($jiraKey) {
        $jiraData = jiraApi("https://{$jiraDomain}/rest/api/3/issue/{$jiraKey}?fields=status,parent,subtasks", $jiraEmail, $jiraToken);
        $jiraStatus = $jiraData['fields']['status']['name'] ?? '-';
        $jiraParent = $jiraData['fields']['parent']['key'] ?? '';
        $jiraParentStatus = $jiraData['fields']['parent']['fields']['status']['name'] ?? '';
        $subtasks = $jiraData['fields']['subtasks'] ?? [];
        $jiraChild = !empty($subtasks) ? $subtasks[0]['key'] : '';
        $jiraChildStatus = !empty($subtasks) ? ($subtasks[0]['fields']['status']['name'] ?? '') : '';
    }

    // Jira comment stats
    $jiraCmts = jiraCommentStats($jiraKey, $jiraDomain, $jiraEmail, $jiraToken, $jiraMyAccountId);

    // Highlight flag (any condition true => highlight)
    $allReplied = ($totalComments === 0 || $repliedComments >= $totalComments);
    $highlight = ($approvalCount >= 2 && in_array(strtoupper($jiraStatus), ['READY FOR CODE REVIEW', 'READY FOR CODING']))
              || ($allReplied && $totalComments > 0 && !in_array(strtoupper($jiraStatus), ['READY FOR CODE REVIEW', 'READY FOR TEST', 'READY FOR DEPLOY']));

    $rows[] = [
        'number'            => $number,
        'title'             => $title,
        'jira_num'          => $jiraNum ?: '-',
        'jira_key'          => $jiraKey,
        'jira_status'       => $jiraStatus,
        'jira_parent'       => $jiraParent,
        'jira_parent_status'=> $jiraParentStatus,
        'jira_child'        => $jiraChild,
        'jira_child_status' => $jiraChildStatus,
        'jira_cmts_replied' => $jiraCmts['replied'],
        'jira_cmts_total'   => $jiraCmts['total'],
        'approvals'         => $approvalCount,
        'total_reviewers'   => $totalReviewers,
        'reviewer_names'    => $allReviewers ? implode(', ', array_map(fn($login) => $login . ' (' . ($reviewerStates[$login] ?? 'PENDING') . ')', array_keys($allReviewers))) : '',
        'pr_status'         => $prStatus,
        'comments_resolved'    => $resolvedComments,
        'comments_total'       => $totalComments,
        'comments_all_replied' => ($totalComments === 0 || $repliedComments >= $totalComments),
        'needs_rerequest'   => $needsRerequest,
        'has_conflict'      => $hasConflict,
        'highlight'         => $highlight,
    ];
}

// Collect Jira keys from ALL PRs (not just displayed rows) to exclude from sprint tasks.
// If we only fetched open PRs, do a quick search for closed ones too (titles only, no detail calls).
$prJiraKeys = [];
$allPrTitles = array_column($prs, 'title');
if (!$showMerged || !$showClosed) {
    $cPage = 1;
    do {
        $cUrl = "https://api.github.com/search/issues?q=" . urlencode("repo:{$githubRepo} type:pr author:{$githubUser} is:closed") . "&per_page=100&page={$cPage}";
        $cResult = githubApi($cUrl, $githubToken);
        $cItems = $cResult['items'] ?? [];
        foreach ($cItems as $ci) {
            $allPrTitles[] = $ci['title'];
        }
        $cPage++;
    } while (count($cItems) === 100);
}
foreach ($allPrTitles as $t) {
    if (preg_match('/\[ED-(\d+)\]/', $t, $m)) {
        $prJiraKeys['ED-' . $m[1]] = true;
    }
}

// Fetch open PRs where I'm a requested reviewer (not yet reviewed)
$reviewPrs = [];
$rvPage = 1;
do {
    $rvUrl = "https://api.github.com/search/issues?q=" . urlencode("repo:{$githubRepo} type:pr is:open review-requested:{$githubUser}") . "&sort=created&order=desc&per_page=100&page={$rvPage}";
    $rvResult = githubApi($rvUrl, $githubToken);
    $rvItems = $rvResult['items'] ?? [];
    foreach ($rvItems as $rvPr) {
        $rvNum = $rvPr['number'];
        // Single GraphQL call for author, reviewers, review states, comments, and review request date
        $rvGql = sprintf(
            '{ repository(owner:"%s", name:"%s") { pullRequest(number:%d) { author { login } reviewRequests(first:50) { nodes { requestedReviewer { ... on User { login } ... on Team { name } } } } reviews(first:100) { nodes { author { login } state } } reviewThreads(first:100) { totalCount nodes { isResolved } } timelineItems(itemTypes: REVIEW_REQUESTED_EVENT, first: 50) { nodes { ... on ReviewRequestedEvent { createdAt requestedReviewer { ... on User { login } } } } } } } }',
            $repoOwner, $repoName, $rvNum
        );
        $rvGqlResult = githubGraphql($rvGql, $githubToken);
        $rvData = $rvGqlResult['data']['repository']['pullRequest'] ?? [];

        // Author
        $rvAuthor = $rvData['author']['login'] ?? '';

        // Other reviewers (not me): combine pending requests + those who reviewed
        $otherReviewers = [];
        // From reviews: track latest state per user
        foreach ($rvData['reviews']['nodes'] ?? [] as $rv) {
            $login = $rv['author']['login'] ?? '';
            if ($login && $login !== $githubUser && $login !== $rvAuthor && $login !== 'cubic-dev-ai') {
                $otherReviewers[$login] = $rv['state'];
            }
        }
        // From pending requests
        foreach ($rvData['reviewRequests']['nodes'] ?? [] as $rr) {
            $login = $rr['requestedReviewer']['login'] ?? ($rr['requestedReviewer']['name'] ?? '');
            if ($login && $login !== $githubUser && $login !== $rvAuthor && $login !== 'cubic-dev-ai' && !isset($otherReviewers[$login])) {
                $otherReviewers[$login] = 'PENDING';
            }
        }

        // Comment threads
        $rvThreads = $rvData['reviewThreads'] ?? ['totalCount' => 0, 'nodes' => []];
        $rvTotalComments = $rvThreads['totalCount'];
        $rvResolvedComments = 0;
        foreach ($rvThreads['nodes'] as $t) {
            if (!empty($t['isResolved'])) $rvResolvedComments++;
        }

        // Find when I was requested as reviewer (latest event for me)
        $myRequestDate = '';
        foreach ($rvData['timelineItems']['nodes'] ?? [] as $evt) {
            $evtLogin = $evt['requestedReviewer']['login'] ?? '';
            if ($evtLogin === $githubUser) {
                $myRequestDate = $evt['createdAt'] ?? '';
            }
        }
        $daysAgo = 0;
        if ($myRequestDate) {
            $reqTime = new DateTimeImmutable($myRequestDate);
            $now = new DateTimeImmutable('now', $reqTime->getTimezone());
            $daysAgo = (int) $now->diff($reqTime)->days;
        }

        $reviewPrs[] = [
            'number'            => $rvNum,
            'title'             => $rvPr['title'],
            'author'            => $rvAuthor,
            'other_reviewers'   => $otherReviewers,
            'comments_resolved' => $rvResolvedComments,
            'comments_total'    => $rvTotalComments,
            'days_ago'          => $daysAgo,
        ];
    }
    $rvPage++;
} while (count($rvItems) === 100);

// Fetch current sprint tasks assigned to me
$sprintTasks = [];
$jiraSearch = jiraApi(
    "https://{$jiraDomain}/rest/api/3/search/jql",
    $jiraEmail,
    $jiraToken,
    [
        'jql'        => 'assignee=currentUser() AND sprint in openSprints() ORDER BY status ASC',
        'fields'     => ['key', 'summary', 'status', 'parent', 'subtasks'],
        'maxResults' => 50,
    ]
);
foreach ($jiraSearch['issues'] ?? [] as $issue) {
    $key = $issue['key'];
    if (isset($prJiraKeys[$key])) continue; // already has a PR
    $num = '';
    if (preg_match('/ED-(\d+)/', $key, $m)) {
        $num = $m[1];
    }
    $parentKey    = $issue['fields']['parent']['key'] ?? '';
    $parentStatus = $issue['fields']['parent']['fields']['status']['name'] ?? '';
    $subtasks     = $issue['fields']['subtasks'] ?? [];
    $childKey     = !empty($subtasks) ? $subtasks[0]['key'] : '';
    $childStatus  = !empty($subtasks) ? ($subtasks[0]['fields']['status']['name'] ?? '') : '';

    $taskCmts = jiraCommentStats($key, $jiraDomain, $jiraEmail, $jiraToken, $jiraMyAccountId);

    $sprintTasks[] = [
        'key'                => $key,
        'num'                => $num,
        'title'              => $issue['fields']['summary'] ?? '',
        'status'             => $issue['fields']['status']['name'] ?? '-',
        'jira_parent'        => $parentKey,
        'jira_parent_status' => $parentStatus,
        'jira_child'         => $childKey,
        'jira_child_status'  => $childStatus,
        'jira_cmts_replied'  => $taskCmts['replied'],
        'jira_cmts_total'    => $taskCmts['total'],
    ];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PR &amp; Task Tracker</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <h1>PR &amp; Task Tracker</h1>
    <form method="POST" class="filters">
        <label><input type="checkbox" name="show_merged" value="1"<?= $showMerged ? ' checked' : '' ?> onchange="this.form.submit()"> Show merged</label>
        <label><input type="checkbox" name="show_closed" value="1"<?= $showClosed ? ' checked' : '' ?> onchange="this.form.submit()"> Show closed</label>
    </form>
    <p class="meta"><?= count($rows) ?> PRs shown from <?= htmlspecialchars($githubRepo) ?></p>
    <table>
        <thead>
            <tr>
                <th>PR No.</th>
                <th>PR Title</th>
                <th>Jira Ticket</th>
                <th>Jira Status</th>
                <th>Task cmnts</th>
                <th>PR cmnts</th>
                <th>Approvals</th>
                <th>PR Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $row): ?>
            <tr<?= $row['highlight'] ? ' class="highlight"' : '' ?>>
                <td><a href="https://github.com/<?= htmlspecialchars($githubRepo) ?>/pull/<?= $row['number'] ?>" target="_blank">#<?= $row['number'] ?></a></td>
                <td><?= htmlspecialchars($row['title']) ?><?php if ($row['has_conflict']): ?><span class="conflict-badge" title="Conflict">C</span><?php endif; ?></td>
                <td><?= renderTicketCell($row['jira_num'], $row['jira_key'], $row['jira_parent'], $row['jira_child'], $jiraDomain, $row['jira_parent_status'], $row['jira_child_status']) ?></td>
                <?php $jiraBadge = strtoupper($row['jira_status']) === 'WORKING ON' ? 'badge-jira-warn' : 'badge-jira'; ?>
                <td><span class="badge <?= $jiraBadge ?>"><?= htmlspecialchars($row['jira_status']) ?></span></td>
                <?php $jCmtClass = ($row['jira_cmts_total'] > $row['jira_cmts_replied']) ? 'comments-unresolved' : 'comments-resolved'; ?>
                <td><span class="<?= $jCmtClass ?>"><?= $row['jira_cmts_replied'] ?>/<?= $row['jira_cmts_total'] ?></span></td>
                <?php $cmtClass = $row['comments_all_replied'] ? 'comments-resolved' : 'comments-unresolved'; ?>
                <td><span class="<?= $cmtClass ?>"><?= $row['comments_resolved'] ?>/<?= $row['comments_total'] ?></span></td>
                <td>
                    <span class="approvals <?= $row['approvals'] >= 2 ? 'approvals-green' : 'approvals-red' ?>" title="<?= htmlspecialchars($row['reviewer_names']) ?>"><?= $row['approvals'] ?>/<?= $row['total_reviewers'] ?></span><?php if ($row['needs_rerequest']): ?><span class="rerequest-icon" title="Reviewer needs to be re-requested">&#8635;</span><?php endif; ?>
                </td>
                <td>
                    <?php
                        $badgeClass = match($row['pr_status']) {
                            'Merged' => 'badge-merged',
                            'Open'   => 'badge-open',
                            default  => 'badge-closed',
                        };
                    ?>
                    <span class="badge <?= $badgeClass ?>"><?= $row['pr_status'] ?></span>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($reviewPrs): ?>
    <h2>PRs Awaiting My Review</h2>
    <p class="meta"><?= count($reviewPrs) ?> open PRs requesting my review</p>
    <table>
        <thead>
            <tr>
                <th>PR No.</th>
                <th>PR Title</th>
                <th>Author</th>
                <th>Reviewers</th>
                <th>PR cmnts</th>
                <th>Days</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($reviewPrs as $rv): ?>
            <tr>
                <td><a href="https://github.com/<?= htmlspecialchars($githubRepo) ?>/pull/<?= $rv['number'] ?>" target="_blank">#<?= $rv['number'] ?></a></td>
                <td><?= htmlspecialchars($rv['title']) ?></td>
                <td><?= htmlspecialchars($rv['author']) ?></td>
                <td>
                    <div class="reviewer-list">
                    <?php foreach ($rv['other_reviewers'] as $login => $state): ?>
                        <span class="<?= $state === 'APPROVED' ? 'reviewer-approved' : 'reviewer-pending' ?>"><?= htmlspecialchars($login) ?></span>
                    <?php endforeach; ?>
                    </div>
                </td>
                <?php $rvCmtClass = ($rv['comments_total'] > $rv['comments_resolved']) ? 'comments-unresolved' : 'comments-resolved'; ?>
                <td><span class="<?= $rvCmtClass ?>"><?= $rv['comments_resolved'] ?>/<?= $rv['comments_total'] ?></span></td>
                <td><span class="days-old <?= $rv['days_ago'] >= 3 ? 'days-urgent' : ($rv['days_ago'] >= 1 ? 'days-warn' : '') ?>"><?= $rv['days_ago'] ?></span></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if ($sprintTasks): ?>
    <h2>Sprint Tasks Without PR</h2>
    <p class="meta"><?= count($sprintTasks) ?> tasks in current sprint with no linked PR</p>
    <table>
        <thead>
            <tr>
                <th>Jira Ticket</th>
                <th>Title</th>
                <th>Jira Status</th>
                <th>Task cmnts</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($sprintTasks as $task): ?>
            <tr>
                <td><?= renderTicketCell($task['num'], $task['key'], $task['jira_parent'], $task['jira_child'], $jiraDomain, $task['jira_parent_status'], $task['jira_child_status']) ?></td>
                <td><?= htmlspecialchars($task['title']) ?></td>
                <?php $taskBadge = strtoupper($task['status']) === 'WORKING ON' ? 'badge-jira-warn' : 'badge-jira'; ?>
                <td><span class="badge <?= $taskBadge ?>"><?= htmlspecialchars($task['status']) ?></span></td>
                <?php $tCmtClass = ($task['jira_cmts_total'] > $task['jira_cmts_replied']) ? 'comments-unresolved' : 'comments-resolved'; ?>
                <td><span class="<?= $tCmtClass ?>"><?= $task['jira_cmts_replied'] ?>/<?= $task['jira_cmts_total'] ?></span></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</body>
</html>
