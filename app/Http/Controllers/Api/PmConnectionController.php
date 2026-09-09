<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncClickUpJob;
use App\Models\ExternalTaskSource;
use App\Models\PmConnection;
use App\Models\Project;
use App\Services\ClickUp\ClickUpApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PmConnectionController extends Controller
{
    public function __construct(
        protected ClickUpApiService $clickUpService
    ) {}

    /**
     * Connect a ClickUp workspace
     */
    public function connectClickUp(Request $request): JsonResponse
    {
        $request->validate([
            'access_token' => 'required|string',
            'client_id' => 'nullable|integer|exists:clients,id',
        ]);

        $user = $request->user();

        // Create temporary connection to test
        $connection = new PmConnection([
            'user_id' => $user->id,
            'platform' => PmConnection::PLATFORM_CLICKUP,
            'access_token' => $request->access_token,
        ]);

        // Validate the token by getting user info
        try {
            $clickUpUser = $this->clickUpService->getAuthorizedUser($connection);
            $teams = $this->clickUpService->getTeams($connection);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Invalid API token. Please check your ClickUp personal token.',
                'error' => $e->getMessage(),
            ], 400);
        }

        if (empty($teams)) {
            return response()->json([
                'message' => 'No workspaces found. Make sure you have access to at least one ClickUp workspace.',
            ], 400);
        }

        // For now, connect to the first workspace (could allow selection later)
        $team = $teams[0];

        // Check if already connected to this workspace
        $existing = PmConnection::where('user_id', $user->id)
            ->where('platform', PmConnection::PLATFORM_CLICKUP)
            ->where('workspace_id', $team['id'])
            ->first();

        if ($existing) {
            // Update existing connection
            $existing->update([
                'access_token' => $request->access_token,
                'workspace_name' => $team['name'],
                'is_active' => true,
            ]);
            $connection = $existing;
        } else {
            // Create new connection
            $connection = PmConnection::create([
                'user_id' => $user->id,
                'client_id' => $request->client_id,
                'platform' => PmConnection::PLATFORM_CLICKUP,
                'workspace_id' => $team['id'],
                'workspace_name' => $team['name'],
                'access_token' => $request->access_token,
                'is_active' => true,
            ]);
        }

        return response()->json([
            'message' => 'ClickUp connected successfully',
            'connection' => [
                'id' => $connection->id,
                'workspace_name' => $connection->workspace_name,
                'workspace_id' => $connection->workspace_id,
            ],
            'workspaces' => collect($teams)->map(fn ($t) => [
                'id' => $t['id'],
                'name' => $t['name'],
            ]),
        ]);
    }

    /**
     * Get available spaces/lists for a connection
     */
    public function getClickUpStructure(PmConnection $connection): JsonResponse
    {
        if ($connection->platform !== PmConnection::PLATFORM_CLICKUP) {
            return response()->json(['message' => 'Not a ClickUp connection'], 400);
        }

        try {
            $spaces = $this->clickUpService->getSpaces($connection, $connection->workspace_id);

            // If no spaces (likely a guest user), try the shared endpoint
            if (empty($spaces)) {
                return $this->getSharedStructure($connection);
            }

            $structure = [];
            foreach ($spaces as $space) {
                $spaceData = [
                    'id' => $space['id'],
                    'name' => $space['name'],
                    'type' => 'space',
                    'folders' => [],
                    'lists' => [],
                ];

                // Get folders
                $folders = $this->clickUpService->getFolders($connection, $space['id']);
                foreach ($folders as $folder) {
                    $folderData = [
                        'id' => $folder['id'],
                        'name' => $folder['name'],
                        'type' => 'folder',
                        'lists' => [],
                    ];

                    // Get lists in folder
                    $lists = $this->clickUpService->getListsInFolder($connection, $folder['id']);
                    foreach ($lists as $list) {
                        $folderData['lists'][] = [
                            'id' => $list['id'],
                            'name' => $list['name'],
                            'type' => 'list',
                            'task_count' => $list['task_count'] ?? 0,
                        ];
                    }

                    $spaceData['folders'][] = $folderData;
                }

                // Get folderless lists
                $folderlessLists = $this->clickUpService->getListsInSpace($connection, $space['id']);
                foreach ($folderlessLists as $list) {
                    $spaceData['lists'][] = [
                        'id' => $list['id'],
                        'name' => $list['name'],
                        'type' => 'list',
                        'task_count' => $list['task_count'] ?? 0,
                    ];
                }

                $structure[] = $spaceData;
            }

            return response()->json([
                'structure' => $structure,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch ClickUp structure',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get shared content structure (for guest users)
     */
    protected function getSharedStructure(PmConnection $connection): JsonResponse
    {
        $shared = $this->clickUpService->getSharedContent($connection, $connection->workspace_id);

        // Build a flat structure with shared lists and folders
        $structure = [];

        // Create a "Shared With Me" pseudo-space
        $sharedSpace = [
            'id' => 'shared',
            'name' => 'Shared With Me',
            'type' => 'space',
            'folders' => [],
            'lists' => [],
        ];

        // Add shared folders
        foreach ($shared['folders'] ?? [] as $folder) {
            $folderData = [
                'id' => $folder['id'],
                'name' => $folder['name'],
                'type' => 'folder',
                'lists' => [],
            ];

            // Get lists in this folder
            try {
                $lists = $this->clickUpService->getListsInFolder($connection, $folder['id']);
                foreach ($lists as $list) {
                    $folderData['lists'][] = [
                        'id' => $list['id'],
                        'name' => $list['name'],
                        'type' => 'list',
                        'task_count' => $list['task_count'] ?? 0,
                    ];
                }
            } catch (\Exception $e) {
                // If we can't get lists in folder, skip
            }

            $sharedSpace['folders'][] = $folderData;
        }

        // Add shared lists directly
        foreach ($shared['lists'] ?? [] as $list) {
            $sharedSpace['lists'][] = [
                'id' => $list['id'],
                'name' => $list['name'],
                'type' => 'list',
                'task_count' => $list['task_count'] ?? 0,
            ];
        }

        if (! empty($sharedSpace['folders']) || ! empty($sharedSpace['lists'])) {
            $structure[] = $sharedSpace;
        }

        return response()->json([
            'structure' => $structure,
        ]);
    }

    /**
     * Disconnect a ClickUp workspace
     */
    public function disconnectClickUp(PmConnection $connection): JsonResponse
    {
        if ($connection->platform !== PmConnection::PLATFORM_CLICKUP) {
            return response()->json(['message' => 'Not a ClickUp connection'], 400);
        }

        $connection->delete();

        return response()->json([
            'message' => 'ClickUp disconnected successfully',
        ]);
    }

    /**
     * Trigger a manual sync
     */
    public function syncClickUp(PmConnection $connection): JsonResponse
    {
        if ($connection->platform !== PmConnection::PLATFORM_CLICKUP) {
            return response()->json(['message' => 'Not a ClickUp connection'], 400);
        }

        SyncClickUpJob::dispatch($connection->id);

        return response()->json([
            'message' => 'Sync started',
        ]);
    }

    /**
     * Update connection (e.g., assign to client)
     */
    public function updateConnection(Request $request, PmConnection $connection): JsonResponse
    {
        $request->validate([
            'client_id' => 'nullable|integer|exists:clients,id',
        ]);

        $connection->update([
            'client_id' => $request->client_id,
        ]);

        return response()->json([
            'message' => 'Connection updated',
            'connection' => [
                'id' => $connection->id,
                'client_id' => $connection->client_id,
            ],
        ]);
    }

    /**
     * Get sources for a connection
     */
    public function getSources(PmConnection $connection): JsonResponse
    {
        $sources = $connection->taskSources()->with('project')->get();

        return response()->json([
            'sources' => $sources->map(fn ($s) => [
                'id' => $s->id,
                'external_id' => $s->external_id,
                'name' => $s->name,
                'type' => $s->type,
                'project_id' => $s->project_id,
                'project_name' => $s->project?->name,
                'auto_import' => $s->auto_import,
                'sync_back' => $s->sync_back,
                'last_synced_at' => $s->last_synced_at?->diffForHumans(),
            ]),
        ]);
    }

    /**
     * Create a new source (link a ClickUp list to a project)
     */
    public function createSource(Request $request, PmConnection $connection): JsonResponse
    {
        $request->validate([
            'external_id' => 'required|string',
            'name' => 'required|string',
            'type' => 'required|string|in:list,folder,space',
            'project_id' => 'required|integer|exists:projects,id',
            'auto_import' => 'boolean',
            'sync_back' => 'boolean',
        ]);

        // Verify project belongs to same client
        if ($connection->client_id) {
            $project = Project::find($request->project_id);
            if ($project->client_id !== $connection->client_id) {
                return response()->json([
                    'message' => 'Project must belong to the same client as the connection',
                ], 400);
            }
        }

        // Check for duplicate
        $existing = ExternalTaskSource::where('pm_connection_id', $connection->id)
            ->where('external_id', $request->external_id)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'This list is already configured',
            ], 400);
        }

        $source = ExternalTaskSource::create([
            'pm_connection_id' => $connection->id,
            'project_id' => $request->project_id,
            'external_id' => $request->external_id,
            'name' => $request->name,
            'type' => $request->type,
            'auto_import' => $request->auto_import ?? true,
            'sync_back' => $request->sync_back ?? false,
        ]);

        return response()->json([
            'message' => 'Source created',
            'source' => [
                'id' => $source->id,
                'name' => $source->name,
                'external_id' => $source->external_id,
            ],
        ], 201);
    }

    /**
     * Delete a source
     */
    public function deleteSource(PmConnection $connection, ExternalTaskSource $source): JsonResponse
    {
        if ($source->pm_connection_id !== $connection->id) {
            return response()->json(['message' => 'Source not found'], 404);
        }

        $source->delete();

        return response()->json([
            'message' => 'Source deleted',
        ]);
    }
}
