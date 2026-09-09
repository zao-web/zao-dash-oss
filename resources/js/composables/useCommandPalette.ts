import { ref, computed, watch, onMounted } from 'vue';
import Fuse from 'fuse.js';
import { router } from '@inertiajs/vue3';

export interface Command {
    id: string;
    name: string;
    description: string;
    keywords: string[];
    shortcut?: string;
    category: 'navigation' | 'action' | 'agent' | 'ai' | 'object';
    icon?: string;
    handler: () => void | Promise<void>;
    url?: string;
    metadata?: Record<string, any>;
    type?: string;
}

export interface AgentInfo {
    id: number;
    name: string;
    slug: string;
    description: string;
    requires_approval: boolean;
}

export interface RecentCommand {
    command_id: string;
    command_type: string;
    query?: string;
    executed_at: string;
}

// Recent commands cache
const recentCommands = ref<RecentCommand[]>([]);
const frequentCommands = ref<{ command_id: string; command_type: string; count: number }[]>([]);
const recentLoaded = ref(false);

/**
 * Navigation commands for the app.
 */
const navigationCommands: Command[] = [
    {
        id: 'nav-dashboard',
        name: 'Go to Dashboard',
        description: 'Navigate to the dashboard',
        keywords: ['home', 'main', 'overview'],
        shortcut: '⌘D',
        category: 'navigation',
        icon: 'grid',
        handler: () => router.visit('/'),
    },
    {
        id: 'nav-projects',
        name: 'Go to Projects',
        description: 'View all projects',
        keywords: ['work', 'project', 'list'],
        category: 'navigation',
        icon: 'folder',
        handler: () => router.visit('/projects'),
    },
    {
        id: 'nav-tasks',
        name: 'Go to Tasks',
        description: 'View all tasks',
        keywords: ['todo', 'work', 'kanban'],
        category: 'navigation',
        icon: 'check-square',
        handler: () => router.visit('/tasks'),
    },
    {
        id: 'nav-clients',
        name: 'Go to Clients',
        description: 'View all clients',
        keywords: ['customer', 'company', 'contact'],
        category: 'navigation',
        icon: 'users',
        handler: () => router.visit('/clients'),
    },
    {
        id: 'nav-pipeline',
        name: 'Go to Pipeline',
        description: 'View sales pipeline (leads)',
        keywords: ['leads', 'sales', 'opportunities'],
        category: 'navigation',
        icon: 'trending-up',
        handler: () => router.visit('/leads'),
    },
    {
        id: 'nav-team',
        name: 'Go to Team',
        description: 'View team members',
        keywords: ['staff', 'people', 'members'],
        category: 'navigation',
        icon: 'user',
        handler: () => router.visit('/team'),
    },
    {
        id: 'nav-agents',
        name: 'Go to Agents',
        description: 'View AI agents',
        keywords: ['ai', 'automation', 'bot'],
        category: 'navigation',
        icon: 'cpu',
        handler: () => router.visit('/agents'),
    },
    {
        id: 'nav-site-builder',
        name: 'Go to Site Builder',
        description: 'Build websites with AI agents',
        keywords: ['website', 'site', 'build', 'ollie', 'wordpress'],
        category: 'navigation',
        icon: 'cog',
        handler: () => router.visit('/site-builder'),
    },
    {
        id: 'nav-approvals',
        name: 'Go to Approvals',
        description: 'View pending approvals',
        keywords: ['approve', 'review', 'pending'],
        category: 'navigation',
        icon: 'shield',
        handler: () => router.visit('/approvals'),
    },
    {
        id: 'nav-vault',
        name: 'Go to Vault',
        description: 'Manage secrets and credentials',
        keywords: ['secrets', 'keys', 'credentials', 'password'],
        category: 'navigation',
        icon: 'lock',
        handler: () => router.visit('/vault'),
    },
    {
        id: 'nav-contractors',
        name: 'Go to Contractors',
        description: 'View and manage contractors',
        keywords: ['contractors', 'freelancers', 'payments', 'wise'],
        category: 'navigation',
        icon: 'wallet',
        handler: () => router.visit('/contractors'),
    },
    {
        id: 'nav-github',
        name: 'Go to GitHub',
        description: 'View GitHub repos, issues, and pull requests',
        keywords: ['github', 'repos', 'issues', 'prs', 'pull requests', 'code'],
        category: 'navigation',
        icon: 'github',
        handler: () => router.visit('/github'),
    },
    {
        id: 'nav-contractor-invoices',
        name: 'Go to Contractor Invoices',
        description: 'Approve and manage contractor invoices',
        keywords: ['invoices', 'billing', 'approve', 'pay'],
        category: 'navigation',
        icon: 'document',
        handler: () => router.visit('/contractor-invoices/pending'),
    },
    {
        id: 'nav-my-payments',
        name: 'Go to My Payments',
        description: 'View your payment history',
        keywords: ['payments', 'history', 'received', 'earnings'],
        category: 'navigation',
        icon: 'wallet',
        handler: () => router.visit('/my/payments'),
    },
    {
        id: 'nav-my-invoices',
        name: 'Go to My Invoices',
        description: 'Submit invoices for payment',
        keywords: ['invoices', 'submit', 'bill', 'invoice'],
        category: 'navigation',
        icon: 'document',
        handler: () => router.visit('/my/invoices'),
    },
    {
        id: 'nav-invoices',
        name: 'Go to Invoices',
        description: 'View and manage client invoices',
        keywords: ['invoices', 'billing', 'payments', 'accounts receivable', 'ar'],
        category: 'navigation',
        icon: 'document',
        handler: () => router.visit('/invoices'),
    },
];

/**
 * Action commands.
 */
const actionCommands: Command[] = [
    {
        id: 'action-new-task',
        name: 'Create New Task',
        description: 'Create a new task',
        keywords: ['add', 'create', 'new', 'task', 'todo'],
        shortcut: '⌘T',
        category: 'action',
        icon: 'plus',
        handler: () => {
            // Will be handled by emitting event
            window.dispatchEvent(new CustomEvent('command-palette:create-task'));
        },
    },
    {
        id: 'action-new-project',
        name: 'Create New Project',
        description: 'Create a new project',
        keywords: ['add', 'create', 'new', 'project'],
        shortcut: '⌘P',
        category: 'action',
        icon: 'folder-plus',
        handler: () => {
            window.dispatchEvent(new CustomEvent('command-palette:create-project'));
        },
    },
    {
        id: 'action-new-client',
        name: 'Create New Client',
        description: 'Add a new client',
        keywords: ['add', 'create', 'new', 'client', 'customer'],
        category: 'action',
        icon: 'user-plus',
        handler: () => {
            window.dispatchEvent(new CustomEvent('command-palette:create-client'));
        },
    },
    {
        id: 'action-pay-contractors',
        name: 'Pay Contractors',
        description: 'Review and pay pending contractor invoices',
        keywords: ['pay', 'contractors', 'wise', 'transfer', 'payment', 'cfo'],
        category: 'action',
        icon: 'wallet',
        handler: () => router.visit('/contractor-invoices/pending'),
    },
    {
        id: 'action-tax-status',
        name: 'Tax Status',
        description: 'View quarterly tax estimates and 1099 status',
        keywords: ['tax', 'taxes', 'quarterly', '1099', 'estimate', 'cfo', 'irs'],
        category: 'action',
        icon: 'calculator',
        handler: () => {
            // Trigger CFO agent with tax_status intent
            window.dispatchEvent(new CustomEvent('command-palette:trigger-agent', {
                detail: { agent: 'cfo', intent: 'tax_status' }
            }));
        },
    },
    {
        id: 'action-tax-strategies',
        name: 'Tax Strategies',
        description: 'Review tax saving strategies and recommendations',
        keywords: ['tax', 'strategy', 'savings', 'deduction', 'cfo'],
        category: 'action',
        icon: 'lightbulb',
        handler: () => {
            window.dispatchEvent(new CustomEvent('command-palette:trigger-agent', {
                detail: { agent: 'cfo', intent: 'tax_strategies' }
            }));
        },
    },
    {
        id: 'action-1099-vendors',
        name: '1099 Vendors',
        description: 'View vendors requiring 1099 forms',
        keywords: ['1099', 'vendors', 'tax', 'filing', 'w9'],
        category: 'action',
        icon: 'document',
        handler: () => {
            window.dispatchEvent(new CustomEvent('command-palette:trigger-agent', {
                detail: { agent: 'cfo', intent: '1099_vendors' }
            }));
        },
    },
    {
        id: 'action-run-bookkeeping',
        name: 'Run Bookkeeping',
        description: 'Categorize expenses and reconcile transactions',
        keywords: ['bookkeeping', 'categorize', 'expenses', 'reconcile', 'qbo', 'cfo'],
        category: 'action',
        icon: 'calculator',
        handler: () => {
            window.dispatchEvent(new CustomEvent('command-palette:trigger-agent', {
                detail: { agent: 'cfo', intent: 'bookkeeping' }
            }));
        },
    },
    {
        id: 'action-new-contractor',
        name: 'Add Contractor',
        description: 'Add a new contractor',
        keywords: ['add', 'create', 'new', 'contractor', 'freelancer'],
        category: 'action',
        icon: 'user-plus',
        handler: () => {
            window.dispatchEvent(new CustomEvent('command-palette:create-contractor'));
        },
    },
    {
        id: 'action-create-invoice',
        name: 'Create My Invoice',
        description: 'Submit a new invoice for payment',
        keywords: ['create', 'new', 'invoice', 'submit', 'bill'],
        category: 'action',
        icon: 'document',
        handler: () => router.visit('/my/invoices/create'),
    },
    {
        id: 'action-create-client-invoice',
        name: 'Create Client Invoice',
        description: 'Create an invoice for a client from unbilled time',
        keywords: ['create', 'new', 'invoice', 'client', 'bill', 'billing', 'accounts receivable'],
        category: 'action',
        icon: 'document',
        handler: () => router.visit('/invoices/create'),
    },
    {
        id: 'action-sync-github',
        name: 'Sync GitHub Repos',
        description: 'Sync repositories from GitHub',
        keywords: ['github', 'sync', 'repos', 'repositories', 'fetch'],
        category: 'action',
        icon: 'github',
        handler: async () => {
            await fetch('/api/integrations/github/sync', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
            });
            window.dispatchEvent(new CustomEvent('command-palette:message', {
                detail: { type: 'success', message: 'GitHub sync started' }
            }));
        },
    },
    {
        id: 'action-github-prs',
        name: 'View Open Pull Requests',
        description: 'See all open PRs across repos',
        keywords: ['github', 'prs', 'pull requests', 'review', 'code'],
        category: 'action',
        icon: 'github',
        handler: () => router.visit('/github/pull-requests'),
    },
    {
        id: 'action-github-issues',
        name: 'View GitHub Issues',
        description: 'See open issues across repos',
        keywords: ['github', 'issues', 'bugs', 'tasks', 'tickets'],
        category: 'action',
        icon: 'github',
        handler: () => router.visit('/github/issues'),
    },
    {
        id: 'action-import-sow',
        name: 'Import Statement of Work',
        description: 'Create a project from a SOW document',
        keywords: ['sow', 'import', 'statement', 'work', 'pdf', 'document', 'project', 'setup'],
        category: 'action',
        icon: 'file-text',
        handler: () => {
            window.dispatchEvent(new CustomEvent('command-palette:import-sow'));
        },
    },
];

/**
 * Static commands (navigation + actions).
 */
const staticCommands = [...navigationCommands, ...actionCommands];

/**
 * Dynamic agent commands (loaded from API).
 */
const agentCommands = ref<Command[]>([]);
const agentsLoaded = ref(false);

/**
 * All available commands (static + dynamic).
 */
const allCommands = computed(() => [...staticCommands, ...agentCommands.value]);

/**
 * Create Fuse.js instance for fuzzy search.
 */
const createFuse = (commands: Command[]) => new Fuse(commands, {
    keys: [
        { name: 'name', weight: 0.4 },
        { name: 'description', weight: 0.2 },
        { name: 'keywords', weight: 0.4 },
    ],
    threshold: 0.3,
    includeScore: true,
});

/**
 * Trigger an agent via API.
 */
const triggerAgent = async (slug: string, requiresApproval: boolean) => {
    try {
        const response = await fetch(`/agents/${slug}/trigger`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Invocation-Source': 'command_palette',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
            body: JSON.stringify({}),
        });

        const data = await response.json();

        if (data.requires_approval) {
            window.dispatchEvent(new CustomEvent('command-palette:message', {
                detail: { type: 'info', message: `Agent requires approval. Check the approvals page.` }
            }));
            router.visit('/approvals');
        } else if (data.status === 'completed') {
            window.dispatchEvent(new CustomEvent('command-palette:message', {
                detail: { type: 'success', message: `Agent executed successfully.` }
            }));
        }
    } catch (error) {
        console.error('Failed to trigger agent:', error);
        window.dispatchEvent(new CustomEvent('command-palette:message', {
            detail: { type: 'error', message: `Failed to trigger agent.` }
        }));
    }
};

/**
 * Fetch agents from API and create commands.
 */
const loadAgents = async () => {
    if (agentsLoaded.value) return;

    try {
        const response = await fetch('/api/agents');
        const agents: AgentInfo[] = await response.json();

        agentCommands.value = agents.map(agent => ({
            id: `agent-${agent.slug}`,
            name: `Run ${agent.name}`,
            description: agent.description || `Trigger the ${agent.name} agent`,
            keywords: ['agent', 'run', 'trigger', 'ai', agent.slug, ...agent.name.toLowerCase().split(' ')],
            category: 'agent' as const,
            icon: 'cpu',
            handler: () => triggerAgent(agent.slug, agent.requires_approval),
        }));

        agentsLoaded.value = true;
    } catch (error) {
        console.error('Failed to load agents:', error);
    }
};

/**
 * Load recent commands from API.
 */
const loadRecentCommands = async () => {
    if (recentLoaded.value) return;

    try {
        const response = await fetch('/api/command-palette/recent');
        const data = await response.json();
        recentCommands.value = data.recent || [];
        frequentCommands.value = data.frequent || [];
        recentLoaded.value = true;
    } catch (error) {
        console.error('Failed to load recent commands:', error);
    }
};

/**
 * Log a command execution.
 */
const logCommandExecution = async (command: Command, query?: string) => {
    try {
        await fetch('/api/command-palette/log', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
            body: JSON.stringify({
                command_id: command.id,
                command_type: command.category,
                query: query || null,
            }),
        });

        // Update local cache
        recentCommands.value = [
            { command_id: command.id, command_type: command.category, query, executed_at: new Date().toISOString() },
            ...recentCommands.value.filter(r => r.command_id !== command.id).slice(0, 9),
        ];
    } catch (error) {
        // Silent fail - don't block command execution
    }
};

export function useCommandPalette() {
    const query = ref('');
    const selectedIndex = ref(0);
    const isAIMode = ref(false);
    const objectResults = ref<Command[]>([]);
    const isLoadingObjects = ref(false);
    const shouldShowAISuggestion = ref(false);

    // Load agents and recent commands on mount
    onMounted(() => {
        loadAgents();
        loadRecentCommands();
    });

    /**
     * Search for objects (Projects, Clients, Tasks, etc.) via API.
     */
    const searchObjects = async (searchQuery: string): Promise<Command[]> => {
        if (!searchQuery.trim() || searchQuery.length < 2) {
            return [];
        }

        try {
            isLoadingObjects.value = true;
            const response = await fetch(`/api/command-palette/object-search?query=${encodeURIComponent(searchQuery)}`, {
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                },
            });

            if (!response.ok) {
                console.error('Object search failed:', response.status, response.statusText);
                return [];
            }

            const data = await response.json();

            if (!data.results || typeof data.results !== 'object') {
                console.error('Object search: unexpected response format', data);
                return [];
            }

            const objects: Command[] = [];

            // Transform API results to Command format
            for (const [category, items] of Object.entries(data.results)) {
                if (!Array.isArray(items)) continue;
                for (const item of items as any[]) {
                    objects.push({
                        id: item.id,
                        name: item.name,
                        description: item.description,
                        keywords: [item.name.toLowerCase()],
                        category: 'object',
                        icon: item.icon,
                        url: item.url,
                        type: item.type,
                        metadata: item.metadata,
                        handler: () => {
                            if (item.url) {
                                router.visit(item.url);
                            }
                        },
                    });
                }
            }

            return objects;
        } catch (error) {
            console.error('Object search failed:', error);
            return [];
        } finally {
            isLoadingObjects.value = false;
        }
    };

    /**
     * Debounced object search.
     */
    let objectSearchTimeout: number | null = null;
    watch(query, async (newQuery) => {
        // Clear previous timeout
        if (objectSearchTimeout) {
            clearTimeout(objectSearchTimeout);
        }

        // Check for AI mode prefix
        if (newQuery.startsWith('>') || newQuery.startsWith('?')) {
            isAIMode.value = true;
            objectResults.value = [];
            shouldShowAISuggestion.value = false;
            return;
        }

        isAIMode.value = false;

        // Debounce object search
        if (newQuery.trim() && newQuery.length >= 2) {
            objectSearchTimeout = window.setTimeout(async () => {
                objectResults.value = await searchObjects(newQuery);
            }, 150);
        } else {
            objectResults.value = [];
            shouldShowAISuggestion.value = false;
        }
    });

    /**
     * Detect if query looks like a question or AI request.
     */
    const looksLikeQuestion = (q: string): boolean => {
        const questionWords = ['what', 'how', 'why', 'when', 'where', 'who', 'which', 'can', 'could', 'should', 'would', 'is', 'are', 'does', 'do'];
        const lowerQuery = q.toLowerCase().trim();

        // Check if starts with question word
        if (questionWords.some(word => lowerQuery.startsWith(word + ' '))) {
            return true;
        }

        // Check if ends with question mark
        if (lowerQuery.endsWith('?')) {
            return true;
        }

        // Check if contains common AI request patterns
        const aiPatterns = ['show me', 'tell me', 'find me', 'help me', 'explain', 'summarize'];
        if (aiPatterns.some(pattern => lowerQuery.includes(pattern))) {
            return true;
        }

        return false;
    };

    /**
     * Filtered results based on search query with priority:
     * 1. Registered actions (navigation, actions, agents)
     * 2. Objects (projects, clients, tasks, etc.)
     * 3. AI fallback suggestion if no matches
     */
    const results = computed<Command[]>(() => {
        const commands = allCommands.value;

        // Check for AI mode prefix
        if (query.value.startsWith('>') || query.value.startsWith('?')) {
            isAIMode.value = true;
            shouldShowAISuggestion.value = false;
            return [];
        }

        isAIMode.value = false;

        if (!query.value.trim()) {
            shouldShowAISuggestion.value = false;
            // Sort by recent usage when no query
            const recentIds = recentCommands.value.map(r => r.command_id);
            const sortedCommands = [...commands].sort((a, b) => {
                const aRecent = recentIds.indexOf(a.id);
                const bRecent = recentIds.indexOf(b.id);
                if (aRecent !== -1 && bRecent === -1) return -1;
                if (bRecent !== -1 && aRecent === -1) return 1;
                if (aRecent !== -1 && bRecent !== -1) return aRecent - bRecent;
                return 0;
            });
            return sortedCommands;
        }

        // 1. Search registered actions first
        const fuse = createFuse(commands);
        const actionResults = fuse.search(query.value);
        const actionCommands = actionResults.map(r => r.item);

        // 2. Combine with object results
        const combinedResults = [...actionCommands, ...objectResults.value];

        // 3. Determine if we should show AI suggestion
        if (combinedResults.length === 0) {
            // No matches - check if query looks like a question
            if (looksLikeQuestion(query.value) || query.value.length > 10) {
                shouldShowAISuggestion.value = true;
            } else {
                shouldShowAISuggestion.value = false;
            }
        } else {
            shouldShowAISuggestion.value = false;
        }

        return combinedResults;
    });

    /**
     * Grouped results by category.
     */
    const groupedResults = computed(() => {
        const groups: Record<string, Command[]> = {
            navigation: [],
            action: [],
            agent: [],
            object: [],
            ai: [],
        };

        for (const cmd of results.value) {
            groups[cmd.category].push(cmd);
        }

        return groups;
    });

    /**
     * Switch to AI mode programmatically.
     */
    const switchToAIMode = () => {
        query.value = '> ' + query.value.trim();
        isAIMode.value = true;
    };

    /**
     * Navigate selection up.
     */
    const selectPrevious = () => {
        if (selectedIndex.value > 0) {
            selectedIndex.value--;
        } else {
            selectedIndex.value = results.value.length - 1;
        }
    };

    /**
     * Navigate selection down.
     */
    const selectNext = () => {
        if (selectedIndex.value < results.value.length - 1) {
            selectedIndex.value++;
        } else {
            selectedIndex.value = 0;
        }
    };

    /**
     * Execute the selected command.
     */
    const executeSelected = async () => {
        const command = results.value[selectedIndex.value];
        if (command) {
            // Log execution (async, don't await)
            logCommandExecution(command, query.value);
            await command.handler();
            return true;
        }
        return false;
    };

    /**
     * Execute a specific command by ID.
     */
    const executeCommand = async (id: string) => {
        const command = allCommands.value.find(c => c.id === id);
        if (command) {
            await command.handler();
            return true;
        }
        return false;
    };

    /**
     * Clear the search.
     */
    const clearQuery = () => {
        query.value = '';
        selectedIndex.value = 0;
        isAIMode.value = false;
    };

    return {
        query,
        selectedIndex,
        results,
        groupedResults,
        isAIMode,
        shouldShowAISuggestion,
        isLoadingObjects,
        recentCommands,
        selectPrevious,
        selectNext,
        executeSelected,
        executeCommand,
        clearQuery,
        switchToAIMode,
        allCommands,
    };
}
