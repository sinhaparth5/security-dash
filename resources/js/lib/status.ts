import {
    CircleArrowUp,
    CircleCheck,
    CircleDashed,
    ShieldAlert,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import type { Status } from '@/types';

/**
 * Worst first: the order the dashboard ranks and groups by.
 */
export const statusOrder: Status[] = ['red', 'yellow', 'unknown', 'green'];

/**
 * Every status carries an icon and a label as well as a colour,
 * so it never depends on colour alone.
 */
export const statusMeta: Record<
    Status,
    {
        label: string;
        description: string;
        icon: LucideIcon;
        badge: string;
        soft: string;
        text: string;
        /** Chart marks: bars and the donut. */
        fill: string;
        stroke: string;
    }
> = {
    red: {
        label: 'Patch required',
        description: 'A known advisory affects the running version',
        icon: ShieldAlert,
        badge: 'bg-status-critical text-status-critical-foreground',
        soft: 'bg-status-critical-soft',
        text: 'text-status-critical',
        fill: 'bg-status-critical',
        stroke: 'stroke-status-critical',
    },
    yellow: {
        label: 'Update available',
        description: 'A newer release exists, no known advisory',
        icon: CircleArrowUp,
        badge: 'bg-status-warning text-status-warning-foreground',
        soft: 'bg-status-warning-soft',
        text: 'text-status-warning-foreground dark:text-status-warning',
        fill: 'bg-status-warning',
        stroke: 'stroke-status-warning',
    },
    unknown: {
        label: 'Not evaluated',
        description: 'Untracked ecosystem or no upstream data yet',
        icon: CircleDashed,
        badge: 'border-border bg-status-unknown-soft text-muted-foreground',
        soft: 'bg-status-unknown-soft',
        text: 'text-muted-foreground',
        fill: 'bg-status-unknown',
        stroke: 'stroke-status-unknown',
    },
    green: {
        label: 'Up to date',
        description: 'Latest release with no known advisory',
        icon: CircleCheck,
        badge: 'bg-status-ok text-status-ok-foreground',
        soft: 'bg-status-ok-soft',
        text: 'text-status-ok',
        fill: 'bg-status-ok',
        stroke: 'stroke-status-ok',
    },
};
