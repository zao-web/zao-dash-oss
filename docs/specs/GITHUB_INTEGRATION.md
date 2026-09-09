# GitHub Integration

Zao Dash uses a dual-access architecture for GitHub to provide both organizational oversight and broad repository access for users.

---

## 1. Dual Access Architecture

The system supports two distinct methods of connecting to GitHub, each serving different purposes:

### A. GitHub App (Org-Level)
Installed by administrators at the organization or account level.
- **Access**: Only repositories where the App is explicitly installed.
- **Primary Use**: Webhooks, automated background synchronization, and organization-wide metrics.
- **Models**: `GitHubInstallation`, `GitHubRepo`, `GitHubIssue`, `GitHubPullRequest`.
- **Authentication**: Uses installation-specific JWTs to obtain short-lived access tokens.

### B. GitHub User OAuth (Personal)
Connected by individual users to grant access to their personal account.
- **Access**: Any repository the user has permission for (including those without the GitHub App).
- **Primary Use**: Repository selection for projects, personal task management, and as a fallback for API calls.
- **Models**: `GitHubCredential` (stores encrypted access and refresh tokens).
- **Authentication**: Standard OAuth2 flow with token refresh support.

---

## 2. Technical Components

### Configuration (`.env`)

| Key | Description |
|-----|-------------|
| `GITHUB_APP_ID` | The ID of the GitHub App |
| `GITHUB_APP_SLUG` | The URL slug of the GitHub App |
| `GITHUB_APP_PRIVATE_KEY` | The RSA private key (PEM format) |
| `GITHUB_WEBHOOK_SECRET` | Secret for validating webhook signatures |
| `GITHUB_CLIENT_ID` | OAuth Client ID |
| `GITHUB_CLIENT_SECRET` | OAuth Client Secret |
| `GITHUB_REDIRECT_URI` | `${APP_URL}/auth/github/user/callback` |

### Key Services

- **`GitHubAppService`**: Manages App installations and retrieves installation tokens.
- **`GitHubApiService`**: Performs API operations using App installation tokens (requires a `GitHubRepo` model).
- **`GitHubOAuthService`**: Handles the user OAuth flow, token storage, and refreshing.
- **`GitHubUserApiService`**: Performs API operations using a specific user's OAuth credentials.
- **`CommitEffortEstimationService`**: Analyzes commit patterns to estimate development effort. It attempts to use the user's OAuth token first for broader access, falling back to the App token if needed.

### Routes

- `GET /auth/github/user`: Starts the personal OAuth connection flow.
- `GET /auth/github/user/callback`: OAuth callback handler.
- `POST /api/webhooks/github`: Receives and processes events from the GitHub App.

---

## 3. Workflows

### Repository Selection
When linking a repository to a project, the system aggregates repositories from:
1. **GitHub App Installations**: Repositories where the Zao Dash App is installed.
2. **User OAuth**: All repositories accessible by the current user's personal account.

### Webhook Processing
The system listens for the following events via the GitHub App:
- `installation`: Syncs repository access when the App is installed or modified.
- `issues`: Updates `GitHubIssue` records and triggers related tasks.
- `pull_request`: Updates `GitHubPullRequest` records and triggers QA or approval workflows.
- `push`: Triggers commit analysis and effort estimation.

---

## 4. Setup Instructions

### GitHub App Setup
1. Create a new GitHub App in your organization.
2. Set permissions: `Contents: Read`, `Issues: Read/Write`, `Pull Requests: Read/Write`, `Metadata: Read`.
3. Subscribe to events: `Issues`, `Pull Request`, `Push`, `Installation`.
4. Generate a Private Key and configure `GITHUB_APP_PRIVATE_KEY`.
5. Note the App ID and Slug for your `.env` file.

### OAuth Setup
1. In the same GitHub App (or a separate OAuth App), configure the "OAuth Web Flow".
2. Set the Callback URL to your application's `/auth/github/user/callback` endpoint.
3. Configure `GITHUB_CLIENT_ID` and `GITHUB_CLIENT_SECRET`.
