import type { Dependency } from '@/types';

const relativeTime = new Intl.RelativeTimeFormat(undefined, {
    numeric: 'auto',
});

export function lastSeen(iso: string | null, now: number = Date.now()): string {
    if (iso === null) {
        return 'Never reported';
    }

    const minutes = Math.round((new Date(iso).getTime() - now) / 60_000);

    if (Math.abs(minutes) < 60) {
        return relativeTime.format(minutes, 'minute');
    }

    if (Math.abs(minutes) < 60 * 24) {
        return relativeTime.format(Math.round(minutes / 60), 'hour');
    }

    return relativeTime.format(Math.round(minutes / (60 * 24)), 'day');
}

export function plural(
    count: number,
    singular: string,
    pluralForm: string,
): string {
    return `${count} ${count === 1 ? singular : pluralForm}`;
}

const registries: Partial<Record<Dependency['ecosystem'], string>> = {
    npm: 'https://www.npmjs.com/package/',
    go: 'https://pkg.go.dev/',
};

/** The package's registry page, or null for runtimes and images. */
export function packageUrl(dependency: Dependency): string | null {
    const base = registries[dependency.ecosystem];

    return base ? base + dependency.name : null;
}
