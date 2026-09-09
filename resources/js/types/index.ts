export interface KpiData {
    revenue_mtd: number;
    revenue_change_pct: number;
    active_projects: number;
    client_health_avg: number;
    hours_tracked_mtd: number;
    pending_approvals: number;
    running_agents: number;
    total_agents?: number;
}

export interface AgentRun {
    id: number;
    agent_name: string;
    agent_slug: string;
    status: 'pending' | 'running' | 'completed' | 'failed' | 'awaiting_approval';
    trigger: string;
    cost_usd: string;
    started_at: string | null;
    completed_at: string | null;
    created_at: string;
}

export interface ApprovalRequest {
    id: number;
    action_type: string;
    summary: string;
    risk_level: 'low' | 'medium' | 'high' | 'critical';
    agent_name: string;
    expires_at: string | null;
    created_at: string;
}

export interface Client {
    id: number;
    name: string;
    slug: string;
    health_score: number;
    status: string;
}

export interface Project {
    id: number;
    name: string;
    slug: string;
    status: string;
    type: string;
    budget: number;
    client_id: number;
}

export interface User {
    id: number;
    name: string;
    email: string;
    role: 'owner' | 'staff' | 'client';
}
