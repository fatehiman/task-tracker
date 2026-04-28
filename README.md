# PR & Task Tracker

A PHP dashboard that integrates **GitHub**, **Jira**, **Zoho Calendar**, **Google Calendar**, **Slack**, and **Telegram** APIs to provide a unified view of pull requests, code reviews, sprint tasks, calendar events, and unread messages.

## Features

### 1. My PRs

A table listing all your authored pull requests with:

| Column | Description |
|---|---|
| **PR No.** | Clickable link to the PR on GitHub |
| **PR Title** | Title with a red **C** badge if the PR has merge conflicts |
| **Jira Ticket** | Extracted `ED-XXXX` number with parent/child ticket hierarchy (shown as arrows with tooltip statuses) |
| **Jira Status** | Current Jira status badge; `WORKING ON` is highlighted with a warning background |
| **Task cmnts** | Jira issue comments — replied/total (red if unreplied comments exist). When the total is non-zero, a checkbox appears next to the count: tick it to mark the ticket as "handled". The checkbox auto-unchecks on the next page refresh if the total comment count has changed since last load (a new comment arrived). State is persisted in `temp-data.json` |
| **PR cmnts** | GitHub review thread comments — resolved/total; green if all threads are replied to (even if not formally resolved by the reviewer), red if unreplied threads remain |
| **Approvals** | Approval count vs total reviewers (e.g. `2/3`); green when >= 2 approvals, red otherwise; hover to see each reviewer's name and state; shows a re-request icon when a reviewer left "changes requested" but hasn't been re-assigned. Only definitive review states (`APPROVED`, `CHANGES_REQUESTED`, `DISMISSED`) are tracked — intermediate `COMMENTED` reviews do not overwrite a prior approval |
| **PR Status** | Merged (purple), Open (green), or Closed (red) badge |

**Row highlighting:** Rows are highlighted in dark red when either condition is met:
- **2+ approvals** and Jira status is still `READY FOR CODE REVIEW` or `READY FOR CODING` (Jira ticket needs updating)
- **All PR comments replied** (at least one comment thread exists) and Jira status is not `READY FOR CODE REVIEW`, `READY FOR TEST`, `READY FOR DEPLOY`, or `OPEN` (PR is ready to advance but Jira doesn't reflect it)

**Filters:** Toggle checkboxes for "Show merged" and "Show closed" PRs. By default, only open PRs are displayed.

### 2. PRs Awaiting My Review

Open PRs from other team members where you are a requested reviewer:

| Column | Description |
|---|---|
| **PR No.** | Clickable link |
| **PR Title** | Title of the PR |
| **Author** | PR author's GitHub username |
| **Reviewers** | Other reviewers shown in green (approved) or orange (pending) |
| **PR cmnts** | Resolved/total review comments |
| **Days** | Days since you were requested as reviewer (yellow >= 1 day, red >= 3 days) |

### 3. Sprint Tasks Without PR

Jira tasks in the current sprint assigned to you that don't have a linked PR yet:

| Column | Description |
|---|---|
| **Jira Ticket** | Ticket number with parent/child hierarchy |
| **Title** | Task summary |
| **Jira Status** | Current status badge |
| **Task cmnts** | Replied/total Jira comments. Same checkbox behavior as the My PRs table — tick to mark "handled", auto-unchecks if the total changes |

### 4. Calendar Events

A floating notification box (top-right corner) showing today's and the next working day's events from **Zoho Calendar** and/or **Google Calendar**. Events from both sources are merged and sorted by date and time. Weekends are automatically skipped. The box can be dismissed with the close button.

Events can be filtered out per provider via CSV settings in `env.php`:
- `$zohoIgnoreEvents` — e.g. `'EBP Daily,Sprint Planning'`
- `$googleIgnoreEvents` — e.g. `'Daily Standup,Some Event'`

### 5. Slack Unread Messages

A floating notification box (top-right, beside the calendar events box) showing channels and DMs with unread messages. Each entry shows the channel name (prefixed with `#` for channels) or DM contact name (italicized) along with an unread count badge. The box can be dismissed with the close button.

Channels can be excluded via the `$slackIgnoreChannels` CSV setting in `env.php`.

### 6. Slack-to-Telegram Forwarder

A cron-based service (`cron-slack.php`) that polls Slack every 20 seconds and forwards new messages to a Telegram group/chat via bot. Features:

- Fetches messages from the last N minutes across all channels (public, private, DMs, group DMs) — configurable via `$slackFetchMinutes` in `env.php` (default: 5)
- Uses a temp file (`cron-slack.tmp`) to track sent messages and prevent duplicates
- Prevents re-entrance via file locking
- Automatically prunes sent message history after 24 hours
- Respects `$slackIgnoreChannels` exclusion list

## Tech Stack

- **PHP 8.x** with cURL
- **GitHub REST API v3** — PR search, merge status
- **GitHub GraphQL API** — mergeable state, reviews (fetches latest 100 to handle PRs with many review events), review requests, review threads, timeline events
- **Jira REST API v3** — issue search (`POST /rest/api/3/search/jql`), issue details, comments, user identity
- **Zoho Calendar API** — OAuth2 token refresh, calendar event listing (EU datacenter)
- **Google Calendar API** — OAuth2 token refresh, calendar event listing
- **Slack API** — `users.conversations`, `conversations.info`, `conversations.history`, `users.info`
- **Telegram Bot API** — `sendMessage` with HTML formatting

## Project Structure

```
task-tracker/
  index.php          # Main dashboard — all PHP logic and HTML rendering
  zoho.php           # Zoho Calendar integration (OAuth, event fetching, RRULE parsing)
  google.php         # Google Calendar integration (OAuth, event fetching)
  slack.php          # Slack integration (unread message detection across all channel types)
  telegram.php       # Telegram Bot API wrapper (sendMessage)
  cron-slack.php     # Cron job — forwards Slack messages to Telegram every 20s
  style.css          # All CSS styles (dark theme)
  env.php            # Credentials (gitignored)
  env.sample.php     # Credential template
  cron-slack.tmp     # Sent message tracking (gitignored)
  temp-data.json     # Per-ticket Task-cmnts checkbox state (gitignored)
  .gitignore         # Excludes env.php, *.tmp, and temp-data.json
```

## Setup

1. Copy `env.sample.php` to `env.php`:

   ```bash
   cp env.sample.php env.php
   ```

2. Fill in your credentials in `env.php`:

   ```php
   // GitHub
   $githubToken = 'your-github-token';       // OAuth or PAT with repo scope
   $githubRepo  = 'owner/repo';              // e.g. 'myorg/myrepo'
   $githubUser  = 'your-github-username';

   // Jira
   $jiraDomain = 'yourteam.atlassian.net';
   $jiraEmail  = 'you@example.com';
   $jiraToken  = 'your-jira-api-token';

   // Zoho Calendar (optional)
   $zohoClientId     = '';     // from api-console.zoho.eu (Self Client)
   $zohoClientSecret = '';
   $zohoRefreshToken = '';
   $zohoCalendarId   = '';     // Zoho Calendar UID
   $zohoIgnoreEvents = '';     // CSV: 'EBP Daily,Some Event'

   // Google Calendar (optional)
   $googleClientId     = '';     // from Google Cloud Console (OAuth 2.0 Client)
   $googleClientSecret = '';
   $googleRefreshToken = '';
   $googleCalendarId   = '';     // 'primary' or calendar email address
   $googleIgnoreEvents = '';     // CSV: 'Daily Standup,Some Event'

   // Slack (optional)
   $slackToken          = '';     // Slack user token (xoxp-...)
   $slackIgnoreChannels = '';     // CSV: 'general,random'

   // Telegram (optional — required for Slack-to-Telegram forwarder)
   $telegramBotToken = '';     // from @BotFather
   $telegramChatId   = '';     // your chat ID (use @userinfobot to find it)

   $slackFetchMinutes = 5;     // how far back to fetch Slack messages (in minutes)
   ```

3. Serve with any PHP-capable web server (Apache, Nginx, or PHP built-in server):

   ```bash
   php -S localhost:8080
   ```

4. Open `http://localhost:8080/index.php` in your browser.

5. (Optional) Set up the Slack-to-Telegram cron (runs every 20 seconds):

   ```
   * * * * * php /path/to/task-tracker/cron-slack.php
   * * * * * sleep 20 && php /path/to/task-tracker/cron-slack.php
   * * * * * sleep 40 && php /path/to/task-tracker/cron-slack.php
   ```

## API Requirements

- **GitHub token** must have `repo` scope for the target repository
- **Jira token** is an [API token](https://id.atlassian.com/manage-profile/security/api-tokens) used with Basic Auth (email:token)
- **Zoho Calendar** requires a Self Client app from [api-console.zoho.eu](https://api-console.zoho.eu) with scopes `ZohoCalendar.calendar.READ` and `ZohoCalendar.event.READ`
- **Google Calendar** requires an OAuth 2.0 Client from Google Cloud Console with scope `https://www.googleapis.com/auth/calendar.readonly`
- **Slack** requires a Slack App with User Token Scopes: `channels:read`, `channels:history`, `groups:read`, `groups:history`, `im:read`, `im:history`, `mpim:read`, `mpim:history`, `users:read`
- **Telegram** requires a bot created via [@BotFather](https://t.me/BotFather) and added to the target chat/group

## Notes

- No caching — every page load fetches fresh data from all APIs
- Filters (merged/closed) use POST and are not persisted between sessions
- Jira ticket numbers are extracted from PR titles matching the pattern `[ED-XXXX]`
- Parent/child Jira ticket relationships are displayed with arrow indicators and status tooltips
- The `cubic-dev-ai` bot reviewer is automatically excluded from the "PRs Awaiting My Review" reviewers column
- Calendar events and Slack notifications are optional — if credentials are not set, the respective UI elements are hidden
- The `env.php` file is gitignored to prevent credential leaks
- The dashboard uses `set_time_limit(180)` since it makes many sequential API calls
