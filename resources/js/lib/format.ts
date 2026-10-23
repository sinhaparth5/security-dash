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
