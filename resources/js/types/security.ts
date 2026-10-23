export type Status = 'green' | 'yellow' | 'red' | 'unknown';

export type Advisory = {
    id: number;
    osv_id: string;
    aliases: string[];
    severity: string | null;
    summary: string | null;
    fixed_version: string | null;
    malicious: boolean;
    known_exploited: boolean;
    epss: number | null;
    accepted: boolean;
    triage: AdvisoryTriage | null;
};

/** Jev's suggestion: urgency 0 (routine) to 3 (emergency), and the next step. */
export type AdvisoryTriage = {
    urgency: number;
    urgency_confidence: number;
    action: 'upgrade' | 'investigate' | 'accept' | 'replace';
    action_confidence: number;
};

export type RiskAcceptance = {
    id: number;
    osv_id: string;
    reason: string;
    expires_at: string | null;
    active: boolean;
    accepted_by: string | null;
    created_at: string | null;
};

export type StatusChange = {
    id: number;
    status: Status;
    created_at: string;
};

export type Pipeline = {
    last_evaluated_at: string | null;
    stalled: boolean;
    failed_jobs: number;
};

export type Dependency = {
    id: number;
    ecosystem: 'go' | 'npm' | 'runtime' | 'image';
    name: string;
    version: string;
    latest_version: string | null;
    status: Status;
    eol_at: string | null;
    pull_request_url: string | null;
    malicious: boolean;
    advisories: Advisory[];
};

export type MonitoredService = {
    id: number;
    name: string;
    environment: string;
    repository: string | null;
    status: Status;
    status_changed_at: string | null;
    evaluated_at: string | null;
    last_ping_at: string | null;
    is_stale: boolean;
    malicious: boolean;
    dependencies: Dependency[];
    risk_acceptances: RiskAcceptance[];
};

export type IssuedServiceToken = {
    name: string;
    environment: string;
    token: string;
    existing: boolean;
};

export type ServiceSummary = {
    id: number;
    name: string;
    environment: string;
    status: Status;
    tokens_count: number;
};

export type ServiceConnectionDetails = {
    id: number;
    name: string;
    environment: string;
    status: Status;
    last_ping_at: string | null;
    allowed_ips: string[];
    tokens: ServiceToken[];
};

export type ServiceToken = {
    id: number;
    created_at: string | null;
    last_used_at: string | null;
    expires_at: string | null;
};

export type AuditEvent = {
    id: number;
    action: string;
    details: Record<string, unknown> | null;
    ip: string | null;
    created_at: string;
    user: string | null;
    service: { id: number; name: string; environment: string } | null;
};
