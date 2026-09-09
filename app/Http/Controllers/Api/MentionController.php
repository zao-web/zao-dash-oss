<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MentionController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $query = $request->input('q', '');
        $limit = 10;

        if (strlen($query) < 1) {
            return response()->json(['items' => []]);
        }

        $items = collect();

        // Search users
        $users = User::where('name', 'like', "%{$query}%")
            ->limit($limit)
            ->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'label' => $u->name,
                'type' => 'user',
                'url' => "/team#user-{$u->id}",
            ]);
        $items = $items->concat($users);

        // Search projects
        $projects = Project::where('name', 'like', "%{$query}%")
            ->limit($limit)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'label' => $p->name,
                'type' => 'project',
                'url' => "/projects/{$p->slug}",
            ]);
        $items = $items->concat($projects);

        // Search tasks
        $tasks = Task::where('title', 'like', "%{$query}%")
            ->limit($limit)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'label' => $t->title,
                'type' => 'task',
                'url' => "/tasks#task-{$t->id}",
            ]);
        $items = $items->concat($tasks);

        // Search clients
        $clients = Client::where('name', 'like', "%{$query}%")
            ->limit($limit)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'label' => $c->name,
                'type' => 'client',
                'url' => "/clients/{$c->slug}",
            ]);
        $items = $items->concat($clients);

        // Search leads
        if (class_exists(Lead::class)) {
            $leads = Lead::where('company_name', 'like', "%{$query}%")
                ->orWhere('contact_name', 'like', "%{$query}%")
                ->limit($limit)
                ->get()
                ->map(fn ($l) => [
                    'id' => $l->id,
                    'label' => $l->company_name ?: $l->contact_name,
                    'type' => 'lead',
                    'url' => "/leads#lead-{$l->id}",
                ]);
            $items = $items->concat($leads);
        }

        // Sort by relevance (exact matches first) and limit total
        $items = $items->sortBy(function ($item) use ($query) {
            $label = strtolower($item['label']);
            $q = strtolower($query);

            if ($label === $q) {
                return 0;
            }
            if (str_starts_with($label, $q)) {
                return 1;
            }

            return 2;
        })->take($limit)->values();

        return response()->json(['items' => $items]);
    }
}
