export type Status = 'green' | 'yellow' | 'red' | 'unknown';

export type Advisory = {
    id: number;
    osv_id: string;
    aliases: string[];
    severity: string | null;
    summary: string | null;
    fixed_version: string | null;
};

export type Dependency = {
    id: number;
    ecosystem: 'go' | 'npm' | 'runtime' | 'image';
    name: string;
    version: string;
    latest_version: string | null;
    status: Status;
    advisories: Advisory[];
};

export type MonitoredService = {
    id: number;
    name: string;
    environment: string;
    status: Status;
    last_ping_at: string | null;
    is_stale: boolean;
    dependencies: Dependency[];
};
