<?php

namespace App\Services\ClickUp;

use App\Models\PmConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class ClickUpApiService
{
    protected const BASE_URL = 'https://api.clickup.com/api/v2';

    protected function client(PmConnection $connection): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => $connection->access_token,
            'Content-Type' => 'application/json',
        ])->baseUrl(self::BASE_URL);
    }

    /**
     * Get authorized user info
     */
    public function getAuthorizedUser(PmConnection $connection): array
    {
        $response = $this->client($connection)->get('/user');

        if (! $response->successful()) {
            throw new \Exception('Failed to get user: '.$response->body());
        }

        return $response->json('user');
    }

    /**
     * Get all workspaces (teams) the user has access to
     */
    public function getTeams(PmConnection $connection): array
    {
        $response = $this->client($connection)->get('/team');

        if (! $response->successful()) {
            throw new \Exception('Failed to get teams: '.$response->body());
        }

        return $response->json('teams') ?? [];
    }

    /**
     * Get spaces in a workspace
     */
    public function getSpaces(PmConnection $connection, string $teamId, bool $archived = false): array
    {
        $response = $this->client($connection)->get("/team/{$teamId}/space", [
            'archived' => $archived ? 'true' : 'false',
        ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to get spaces: '.$response->body());
        }

        return $response->json('spaces') ?? [];
    }

    /**
     * Get folders in a space
     */
    public function getFolders(PmConnection $connection, string $spaceId, bool $archived = false): array
    {
        $response = $this->client($connection)->get("/space/{$spaceId}/folder", [
            'archived' => $archived ? 'true' : 'false',
        ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to get folders: '.$response->body());
        }

        return $response->json('folders') ?? [];
    }

    /**
     * Get lists in a folder
     */
    public function getListsInFolder(PmConnection $connection, string $folderId, bool $archived = false): array
    {
        $response = $this->client($connection)->get("/folder/{$folderId}/list", [
            'archived' => $archived ? 'true' : 'false',
        ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to get lists: '.$response->body());
        }

        return $response->json('lists') ?? [];
    }

    /**
     * Get folderless lists in a space
     */
    public function getListsInSpace(PmConnection $connection, string $spaceId, bool $archived = false): array
    {
        $response = $this->client($connection)->get("/space/{$spaceId}/list", [
            'archived' => $archived ? 'true' : 'false',
        ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to get lists: '.$response->body());
        }

        return $response->json('lists') ?? [];
    }

    /**
     * Get tasks from a list with pagination
     */
    public function getTasksFromList(
        PmConnection $connection,
        string $listId,
        bool $archived = false,
        bool $includeSubtasks = true,
        ?int $dateUpdatedGt = null
    ): array {
        $tasks = [];
        $page = 0;

        do {
            $params = [
                'archived' => $archived ? 'true' : 'false',
                'include_closed' => 'true',
                'subtasks' => $includeSubtasks ? 'true' : 'false',
                'page' => $page,
            ];

            if ($dateUpdatedGt) {
                $params['date_updated_gt'] = $dateUpdatedGt;
            }

            $response = $this->client($connection)->get("/list/{$listId}/task", $params);

            if (! $response->successful()) {
                throw new \Exception('Failed to get tasks: '.$response->body());
            }

            $data = $response->json();
            $tasks = array_merge($tasks, $data['tasks'] ?? []);
            $page++;

            // ClickUp returns max 100 per page, if less than 100 we're done
        } while (count($data['tasks'] ?? []) >= 100);

        return $tasks;
    }

    /**
     * Get a single task by ID
     */
    public function getTask(PmConnection $connection, string $taskId, bool $includeSubtasks = true): array
    {
        $response = $this->client($connection)->get("/task/{$taskId}", [
            'include_subtasks' => $includeSubtasks ? 'true' : 'false',
        ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to get task: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Get task comments
     */
    public function getTaskComments(PmConnection $connection, string $taskId): array
    {
        $response = $this->client($connection)->get("/task/{$taskId}/comment");

        if (! $response->successful()) {
            throw new \Exception('Failed to get comments: '.$response->body());
        }

        return $response->json('comments') ?? [];
    }

    /**
     * Update a task (for sync-back)
     */
    public function updateTask(PmConnection $connection, string $taskId, array $data): array
    {
        $response = $this->client($connection)->put("/task/{$taskId}", $data);

        if (! $response->successful()) {
            throw new \Exception('Failed to update task: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Get workspace members
     */
    public function getTeamMembers(PmConnection $connection, string $teamId): array
    {
        // Members are included in team response, but we can fetch separately
        $response = $this->client($connection)->get("/team/{$teamId}");

        if (! $response->successful()) {
            throw new \Exception('Failed to get team members: '.$response->body());
        }

        return $response->json('team.members') ?? [];
    }

    /**
     * Get list details (includes statuses)
     */
    public function getList(PmConnection $connection, string $listId): array
    {
        $response = $this->client($connection)->get("/list/{$listId}");

        if (! $response->successful()) {
            throw new \Exception('Failed to get list: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Get shared content (for guest users)
     * Returns lists and folders that the user has been shared on
     */
    public function getSharedContent(PmConnection $connection, string $teamId): array
    {
        $response = $this->client($connection)->get("/team/{$teamId}/shared");

        if (! $response->successful()) {
            throw new \Exception('Failed to get shared content: '.$response->body());
        }

        return $response->json('shared') ?? [];
    }

    /**
     * Test connection by getting user info
     */
    public function testConnection(PmConnection $connection): bool
    {
        try {
            $user = $this->getAuthorizedUser($connection);

            return ! empty($user['id']);
        } catch (\Exception $e) {
            return false;
        }
    }
}
