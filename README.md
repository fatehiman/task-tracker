# PR & Task Tracker

A PHP dashboard that integrates **GitHub** and **Jira** APIs to provide a unified view of pull requests, code reviews, and sprint tasks.

## Features

### 1. My PRs

A table listing all your authored pull requests with:

| Column | Description |
|---|---|
| **PR No.** | Clickable link to the PR on GitHub |
| **PR Title** | Title with a red **C** badge if the PR has merge conflicts |
| **Jira Ticket** | Extracted `ED-XXXX` number with parent/child ticket hierarchy (shown as arrows with tooltip statuses) |
| **Jira Status** | Current Jira status badge; `WORKING ON` is highlighted with a warning background |
| **Task cmnts** | Jira issue comments — replied/total (red if unreplied comments exist) |
| **PR cmnts** | GitHub review thread comments — resolved/total; green if all threads are replied to (even if not formally resolved by the reviewer), red if unreplied threads remain |
| **Approvals** | Approval count vs total reviewers (e.g. `2/3`); green when >= 2 approvals, red otherwise; hover to see each reviewer's name and state; shows a re-request icon when a reviewer left "changes requested" but hasn't been re-assigned. Only definitive review states (`APPROVED`, `CHANGES_REQUESTED`, `DISMISSED`) are tracked — intermediate `COMMENTED` reviews do not overwrite a prior approval |
| **PR Status** | Merged (purple), Open (green), or Closed (red) badge |

**Row highlighting:** Rows are highlighted in dark red when either condition is met:
- **2+ approvals** and Jira status is still `READY FOR CODE REVIEW` or `READY FOR CODING` (Jira ticket needs updating)
- **All PR comments replied** (at least one comment thread exists) and Jira status is not `READY FOR CODE REVIEW`, `READY FOR TEST`, or `READY FOR DEPLOY` (PR is ready to advance but Jira doesn't reflect it)

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
| **Task cmnts** | Replied/total Jira comments |

### 4. Zoho Calendar Events

A floating notification box (top-right corner) showing today's and the next working day's events from your Zoho Calendar. Weekends are automatically skipped. The box can be dismissed with the close button.

Events can be filtered out via the `$zohoIgnoreEvents` CSV setting in `env.php` (e.g. `'EBP Daily,Sprint Planning'`).

## Tech Stack

- **PHP 8.x** with cURL
- **GitHub REST API v3** — PR search, merge status
- **GitHub GraphQL API** — mergeable state, reviews (fetches latest 100 to handle PRs with many review events), review requests, review threads, timeline events
- **Jira REST API v3** — issue search (`POST /rest/api/3/search/jql`), issue details, comments, user identity
- **Zoho Calendar API** — OAuth2 token refresh, calendar event listing (EU datacenter)

## Project Structure

```
public_html/
  index.php          # Main dashboard — all PHP logic and HTML rendering
  zoho.php           # Zoho Calendar integration (OAuth, event fetching, RRULE parsing)
  style.css          # All CSS styles (dark theme)
  env.php            # Credentials (gitignored)
  env.sample.php     # Credential template
  .gitignore         # Excludes env.php
```

## Setup

1. Copy `env.sample.php` to `env.php`:

   ```bash
   cp env.sample.php env.php
   ```

2. Fill in your credentials in `env.php`:

   ```php
   $githubToken = 'your-github-token';       // OAuth or PAT with repo scope
   $githubRepo  = 'owner/repo';              // e.g. 'myorg/myrepo'
   $githubUser  = 'your-github-username';

   $jiraDomain = 'yourteam.atlassian.net';
   $jiraEmail  = 'you@example.com';
   $jiraToken  = 'your-jira-api-token';

   $zohoClientId     = '';     // from api-console.zoho.eu (Self Client)
   $zohoClientSecret = '';
   $zohoRefreshToken = '';
   $zohoCalendarId   = '';     // Zoho Calendar UID
   $zohoIgnoreEvents = '';     // CSV: 'EBP Daily,Some Event'
   ```

3. Serve with any PHP-capable web server (Apache, Nginx, or PHP built-in server):

   ```bash
   php -S localhost:8080
   ```

4. Open `http://localhost:8080/index.php` in your browser.

## API Requirements

- **GitHub token** must have `repo` scope for the target repository
- **Jira token** is an [API token](https://id.atlassian.com/manage-profile/security/api-tokens) used with Basic Auth (email:token)
- **Zoho Calendar** requires a Self Client app from [api-console.zoho.eu](https://api-console.zoho.eu) with scopes `ZohoCalendar.calendar.READ` and `ZohoCalendar.event.READ`
- The dashboard uses `set_time_limit(180)` since it makes many sequential API calls

## Notes

- No caching — every page load fetches fresh data from both APIs
- Filters (merged/closed) use POST and are not persisted between sessions
- Jira ticket numbers are extracted from PR titles matching the pattern `[ED-XXXX]`
- Parent/child Jira ticket relationships are displayed with arrow indicators and status tooltips
- The `cubic-dev-ai` bot reviewer is automatically excluded from the "PRs Awaiting My Review" reviewers column
- Zoho Calendar events are optional — if credentials are not set, the notification box is hidden
- The `env.php` file is gitignored to prevent credential leaks
