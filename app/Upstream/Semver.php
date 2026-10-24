<?php

namespace App\Upstream;

/**
 * Just enough of node-semver to resolve npm dependency ranges the way `npm install` does:
 * version ordering with prereleases, ^ ~ and x-ranges, hyphen ranges, || unions, and the rule that
 * a prerelease only matches a range that names a prerelease of the same version.
 * Anything else (tags, git or file specs, aliases, workspace:) can't be resolved and yields null.
 */
class Semver
{
    private const VERSION = '/^v?(\d+)\.(\d+)\.(\d+)(?:-([0-9A-Za-z.-]+))?(?:\+[0-9A-Za-z.-]+)?$/';

    private const PARTIAL = '/^(<=|>=|<|>|=|~>|~|\^)?v?([0-9]+|[xX*])(?:\.([0-9]+|[xX*]))?(?:\.([0-9]+|[xX*]))?(?:-([0-9A-Za-z.-]+))?(?:\+[0-9A-Za-z.-]+)?$/';

    /**
     * @return array{int, int, int, list<string>}|null major, minor, patch and prerelease identifiers
     */
    public static function parse(string $version): ?array
    {
        if (! preg_match(self::VERSION, trim($version), $match)) {
            return null;
        }

        return [(int) $match[1], (int) $match[2], (int) $match[3], ($match[4] ?? '') === '' ? [] : explode('.', $match[4])];
    }

    /**
     * Order two versions; falls back to version_compare() when either isn't semver.
     */
    public static function compare(string $a, string $b): int
    {
        $left = self::parse($a);
        $right = self::parse($b);

        return $left === null || $right === null ? version_compare($a, $b) : self::compareParsed($left, $right);
    }

    /**
     * Whether the version is in the range, or null when either can't be read.
     */
    public static function satisfies(string $version, string $range): ?bool
    {
        $parsed = self::parse($version);
        $sets = self::sets($range);

        if ($parsed === null || $sets === null) {
            return null;
        }

        foreach ($sets as $set) {
            if (self::inSet($parsed, $set)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The highest version in the range, which is what npm installs for it.
     *
     * @param  iterable<string>  $versions
     */
    public static function maxSatisfying(iterable $versions, string $range): ?string
    {
        $best = null;

        foreach ($versions as $version) {
            if (self::satisfies($version, $range) && ($best === null || self::compare($version, $best) > 0)) {
                $best = $version;
            }
        }

        return $best;
    }

    /**
     * @param  array{int, int, int, list<string>}  $a
     * @param  array{int, int, int, list<string>}  $b
     */
    private static function compareParsed(array $a, array $b): int
    {
        foreach ([0, 1, 2] as $part) {
            if ($a[$part] !== $b[$part]) {
                return $a[$part] <=> $b[$part];
            }
        }

        // 1.0.0-rc.1 < 1.0.0
        if ($a[3] === [] || $b[3] === []) {
            return ($a[3] === []) <=> ($b[3] === []);
        }

        foreach (range(0, max(count($a[3]), count($b[3])) - 1) as $index) {
            $left = $a[3][$index] ?? null;
            $right = $b[3][$index] ?? null;

            if ($left === null || $right === null) {
                return $left === null ? -1 : 1;
            }

            if ($left === $right) {
                continue;
            }

            // Numeric identifiers sort numerically and below alphanumeric ones.
            return match (true) {
                ctype_digit($left) && ctype_digit($right) => (int) $left <=> (int) $right,
                ctype_digit($left) => -1,
                ctype_digit($right) => 1,
                default => strcmp($left, $right) <=> 0,
            };
        }

        return 0;
    }

    /**
     * @param  array{int, int, int, list<string>}  $version
     * @param  list<array{string, array{int, int, int, list<string>}}>  $set
     */
    private static function inSet(array $version, array $set): bool
    {
        foreach ($set as [$operator, $bound]) {
            $order = self::compareParsed($version, $bound);

            $holds = match ($operator) {
                '<' => $order < 0,
                '<=' => $order <= 0,
                '>' => $order > 0,
                '>=' => $order >= 0,
                default => $order === 0,
            };

            if (! $holds) {
                return false;
            }
        }

        if ($version[3] === []) {
            return true;
        }

        // A prerelease only counts when the range itself names a prerelease of the same version.
        foreach ($set as [, $bound]) {
            if ($bound[3] !== [] && $bound[3] !== ['0'] && array_slice($bound, 0, 3) === array_slice($version, 0, 3)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The range as a union of comparator sets, each a list of [operator, version] that must all hold.
     *
     * @return list<list<array{string, array{int, int, int, list<string>}}>>|null
     */
    private static function sets(string $range): ?array
    {
        $sets = [];

        foreach (explode('||', $range) as $part) {
            $part = trim($part);

            if (preg_match('/^(\S+)\s+-\s+(\S+)$/', $part, $hyphen)) {
                $lower = self::comparators('>='.$hyphen[1]);
                $upper = self::comparators('<='.$hyphen[2]);

                if ($lower === null || $upper === null) {
                    return null;
                }

                $sets[] = [...$lower, ...$upper];

                continue;
            }

            $set = [];
            $tokens = preg_split('/\s+/', (string) preg_replace('/(<=|>=|<|>|=|~>|~|\^)\s+/', '$1', $part), flags: PREG_SPLIT_NO_EMPTY);

            foreach ($tokens === false || $tokens === [] ? ['*'] : $tokens as $token) {
                $comparators = self::comparators($token);

                if ($comparators === null) {
                    return null;
                }

                array_push($set, ...$comparators);
            }

            $sets[] = $set;
        }

        return $sets;
    }

    /**
     * One range token such as ^1.2.3, ~1.2, >=2, 1.x or 1.2.3 as primitive comparators.
     *
     * @return list<array{string, array{int, int, int, list<string>}}>|null
     */
    private static function comparators(string $token): ?array
    {
        if (! preg_match(self::PARTIAL, $token, $match)) {
            return null;
        }

        $operator = $match[1];
        $wild = fn (?string $part) => $part === null || $part === '' || in_array($part, ['x', 'X', '*'], true);
        $major = $wild($match[2]) ? null : (int) $match[2];
        $minor = $major === null || $wild($match[3] ?? null) ? null : (int) ($match[3] ?? 0);
        $patch = $minor === null || $wild($match[4] ?? null) ? null : (int) ($match[4] ?? 0);
        $pre = $patch === null || ($match[5] ?? '') === '' ? [] : explode('.', $match[5]);

        $any = [['>=', [0, 0, 0, []]]];
        $none = [['<', [0, 0, 0, ['0']]]];
        if ($major === null) {
            return in_array($operator, ['<', '>'], true) ? $none : $any;
        }

        return match ($operator) {
            '~', '~>' => $minor === null
                ? [self::from($major), self::below($major + 1)]
                : [self::from($major, $minor, $patch ?? 0, $pre), self::below($major, $minor + 1)],
            '^' => match (true) {
                $minor === null => [self::from($major), self::below($major + 1)],
                $major > 0 => [self::from($major, $minor, $patch ?? 0, $pre), self::below($major + 1)],
                $patch === null || $minor > 0 => [self::from(0, $minor, $patch ?? 0, $pre), self::below(0, $minor + 1)],
                default => [self::from(0, 0, $patch, $pre), self::below(0, 0, $patch + 1)],
            },
            '>' => match (true) {
                $minor === null => [self::from($major + 1)],
                $patch === null => [self::from($major, $minor + 1)],
                default => [['>', [$major, $minor, $patch, $pre]]],
            },
            '>=' => [self::from($major, $minor ?? 0, $patch ?? 0, $pre)],
            '<' => $patch === null ? [self::below($major, $minor ?? 0)] : [['<', [$major, $minor, $patch, $pre]]],
            '<=' => match (true) {
                $minor === null => [self::below($major + 1)],
                $patch === null => [self::below($major, $minor + 1)],
                default => [['<=', [$major, $minor, $patch, $pre]]],
            },
            default => match (true) {
                $minor === null => [self::from($major), self::below($major + 1)],
                $patch === null => [self::from($major, $minor), self::below($major, $minor + 1)],
                default => [['=', [$major, $minor, $patch, $pre]]],
            },
        };
    }

    /**
     * @param  list<string>  $pre
     * @return array{string, array{int, int, int, list<string>}}
     */
    private static function from(int $major, int $minor = 0, int $patch = 0, array $pre = []): array
    {
        return ['>=', [$major, $minor, $patch, $pre]];
    }

    /**
     * Below the given version and all its prereleases: "-0" is the lowest prerelease.
     *
     * @return array{string, array{int, int, int, list<string>}}
     */
    private static function below(int $major, int $minor = 0, int $patch = 0): array
    {
        return ['<', [$major, $minor, $patch, ['0']]];
    }
}
