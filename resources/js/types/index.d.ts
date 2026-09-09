export interface User {
    id: number;
    name: string;
    email: string;
    role: 'owner' | 'staff' | 'client';
    avatar_url?: string;
    created_at: string;
    updated_at: string;
}

export interface Client {
    id: number;
    name: string;
    slug: string;
    health_score: number;
    status: 'active' | 'inactive' | 'prospect';
    contacts: ClientContact[];
    projects: Project[];
    created_at: string;
    updated_at: string;
}

export interface ClientContact {
    id: number;
    client_id: number;
    name: string;
    email: string;
    role: string;
    is_primary: boolean;
}

export interface Project {
    id: number;
    client_id: number;
    name: string;
    slug: string;
    status: 'active' | 'on_hold' | 'completed' | 'archived';
    type: 'retainer' | 'project' | 'support';
    milestones: Milestone[];
    created_at: string;
    updated_at: string;
}

export interface Milestone {
    id: number;
    project_id: number;
    name: string;
    status: 'pending' | 'in_progress' | 'completed';
    due_date?: string;
    completed_at?: string;
}

export interface Task {
    id: number;
    title: string;
    description?: string;
    status: 'pending' | 'in_progress' | 'review' | 'completed';
    priority: 'low' | 'medium' | 'high' | 'urgent';
    assigned_to?: number;
    project_id?: number;
    source: 'manual' | 'meeting-parser' | 'agent';
    source_session_id?: string;
    due_date?: string;
    created_at: string;
    updated_at: string;
}

export interface Agent {
    id: number;
    name: string;
    slug: string;
    description: string;
    status: 'active' | 'disabled' | 'circuit_broken';
    model: 'opus' | 'sonnet' | 'haiku';
    requires_approval: boolean;
    max_budget_usd: number;
    created_at: string;
    updated_at: string;
}

export interface AgentRun {
    id: number;
    agent_id: number;
    agent: Agent;
    session_id: string;
    status: 'running' | 'completed' | 'failed' | 'pending_approval';
    task: string;
    output?: Record<string, unknown>;
    cost_usd: number;
    duration_ms: number;
    started_at: string;
    completed_at?: string;
}

export interface ApprovalRequest {
    id: number;
    agent_run_id: number;
    agent_run: AgentRun;
    action_type: 'deploy.production' | 'deploy.staging' | 'financial.invoice' | 'communication.client_email' | 'database.migration';
    description: string;
    payload: Record<string, unknown>;
    status: 'pending' | 'approved' | 'rejected' | 'expired';
    decided_by?: number;
    decided_at?: string;
    expires_at: string;
    created_at: string;
}

export interface KpiData {
    revenue_mtd: number;
    revenue_change_pct: number;
    active_projects: number;
    client_health_avg: number;
    hours_tracked_mtd: number;
    pending_approvals: number;
    running_agents: number;
}

export type PageProps<T extends Record<string, unknown> = Record<string, unknown>> = T & {
    auth: {
        user: User;
    };
    flash: {
        success?: string;
        error?: string;
    };
};
