export type Status = 'green' | 'yellow' | 'red' | 'unknown';

export type Advisory = {
    id: number;
    osv_id: string;
    aliases: string[];
    severity: string | null;
    summary: string | null;
    fixed_version: string | null;
    affected_packages: string[];
    malicious: boolean;
    known_exploited: boolean;
    epss: number | null;
    accepted: boolean;
    triage: AdvisoryTriage | null;
    /** Fix-by date from severity and when the advisory was first seen. */
    due_at: string;
    overdue: boolean;
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

/** Services per status at the end of each of the last 30 days, plus this month's changes. */
export type StatusHistory = {
    trend: ({ date: string } & Record<Status, number>)[];
    events: {
        service_id: number;
        service: string;
        status: Status;
        at: string;
    }[];
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
    pull_request_state: 'open' | 'merged' | 'closed' | null;
    /** The popular npm package this name is one typo away from. */
    lookalike: string | null;
    malicious: boolean;
    /** In the service's own package.json; null when the reporter sent no graph. */
    direct: boolean | null;
    /** How the usage scan saw it; null without a scan. */
    usage: 'source' | 'tooling' | 'unused' | null;
    import_sites: ImportSite[];
    /** Does the service's code lead to it? Only set for packages with advisories; null when unknown. */
    reachable: boolean | null;
    /** Up to three shortest chains from a direct dependency down to this one. */
    paths: string[][];
    path_count: number;
    advisories: Advisory[];
};

export type ImportSite = {
    file: string;
    line: number;
    kind: 'source' | 'tooling';
};

/** One step of the upgrade plan, cheapest first. */
export type FixStep = {
    action: 'refresh' | 'bump' | 'override';
    package: string;
    from: string | null;
    to: string;
    /** Outside the caret range of the current version. */
    breaking: boolean;
    fixes: string[];
    via: string[];
};

export type MonitoredService = {
    id: number;
    name: string;
    environment: string;
    repository: string | null;
    usage_scanned_at: string | null;
    fix_plan: FixStep[];
    status: Status;
    status_changed_at: string | null;
    evaluated_at: string | null;
    last_ping_at: string | null;
    is_stale: boolean;
    malicious: boolean;
    overdue: number;
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

export type FleetPackage = {
    ecosystem: Dependency['ecosystem'];
    name: string;
    status: Status;
    services_count: number;
    versions: {
        version: string;
        status: Status;
        services: { id: number; name: string; environment: string }[];
    }[];
};

export type NewsArticle = {
    id: number;
    url: string;
    source: string;
    title: string;
    summary: string | null;
    image_url: string | null;
    published_at: string;
    /** Jev: 0 background, 1 worth knowing, 2 important, 3 act now. */
    importance: number | null;
    importance_confidence: number | null;
};
