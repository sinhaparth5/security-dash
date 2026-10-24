// Patchwatch reporter for Node services. No dependencies, Node 18+ (global fetch).
//
// Reports the Node runtime plus every production package from your lockfile
// (npm, pnpm, Yarn or Bun) to PATCHWATCH_URL, authenticated with PATCHWATCH_TOKEN,
// with what each package requires so Patchwatch can trace how a vulnerable one
// gets in. Set PATCHWATCH_SOURCE (e.g. "." in CI, where the source is checked
// out) to also scan your code for imports: findings in packages nothing you run
// reaches are then graded as warnings. Copy this file into your project.
//
//   import { startPatchwatch } from './patchwatch.mjs';
//   startPatchwatch(); // on boot, then every 6 hours
//
// Or run it once, e.g. from cron or a deploy step: node patchwatch.mjs

import { existsSync } from 'node:fs';
import { readdir, readFile, stat } from 'node:fs/promises';
import { basename, dirname, extname, join, relative, sep } from 'node:path';
import { pathToFileURL } from 'node:url';

const SIX_HOURS = 6 * 60 * 60 * 1000;

const LOCKFILES = {
    'package-lock.json': readNpm,
    'npm-shrinkwrap.json': readNpm,
    'pnpm-lock.yaml': readPnpm,
    'yarn.lock': readYarn,
    'bun.lock': readBun,
};

/**
 * Build the manifest: the Node runtime plus one entry per production package.
 * Patchwatch keeps one version per package name, so the copy closest to your
 * own dependencies wins over nested duplicates.
 */
export async function collectDependencies(
    lockfile = process.env.PATCHWATCH_LOCKFILE ?? findLockfile(),
) {
    const read = LOCKFILES[basename(lockfile)];

    if (!read) {
        throw new Error(
            basename(lockfile) === 'bun.lockb'
                ? 'bun.lockb is binary. Run `bun install --save-text-lockfile` (or upgrade to Bun 1.2+) to get bun.lock.'
                : `Unsupported lockfile ${lockfile}. Use one of: ${Object.keys(LOCKFILES).join(', ')}.`,
        );
    }

    const versions = new Map();

    for (const [name, version, requires] of await read(lockfile)) {
        if (!versions.has(name)) {
            versions.set(name, { version, requires });
        }
    }

    return [
        { ecosystem: 'runtime', name: 'node', version: process.version },
        ...[...versions].map(([name, { version, requires }]) => ({
            ecosystem: 'npm',
            name,
            version,
            requires,
        })),
    ];
}

/** Your package.json's production dependencies and their ranges: the roots of the graph. */
async function readRoots(lockfile) {
    const manifest = JSON.parse(
        await readFile(join(dirname(lockfile), 'package.json'), 'utf8'),
    );

    return { manifest, requires: requiresOf(manifest) };
}

function findLockfile() {
    const found = Object.keys(LOCKFILES).find((file) => existsSync(file));

    if (!found) {
        throw new Error(
            `No lockfile in ${process.cwd()}. Set PATCHWATCH_LOCKFILE or copy one of ${Object.keys(LOCKFILES).join(', ')} into the image.`,
        );
    }

    return found;
}

/** package-lock.json v2/v3: every install path is listed and dev packages are flagged. */
async function readNpm(lockfile) {
    const lock = JSON.parse(await readFile(lockfile, 'utf8'));

    if (!lock.packages) {
        throw new Error(
            `${lockfile} has no "packages" map. Regenerate it with npm 7 or newer.`,
        );
    }

    return (
        Object.entries(lock.packages)
            // "" is your own project, links point at workspace folders, dev deps aren't deployed.
            .filter(
                ([path, entry]) =>
                    path !== '' && !entry.link && !entry.dev && entry.version,
            )
            .map(([path, entry]) => ({
                depth: path.split('node_modules/').length,
                name:
                    entry.name ??
                    path.slice(path.lastIndexOf('node_modules/') + 13),
                version: entry.version,
                requires: requiresOf(entry),
            }))
            .sort((a, b) => a.depth - b.depth)
            .map(({ name, version, requires }) => [name, version, requires])
    );
}

/**
 * pnpm-lock.yaml. v5/v6 flag dev packages; v9 doesn't, so walk the graph
 * from every importer's production dependencies through `snapshots`.
 */
async function readPnpm(lockfile) {
    const lock = parseYamlish(await readFile(lockfile, 'utf8'));
    const major = parseInt(lock.lockfileVersion, 10);

    if (major < 9) {
        // v6 keys look like /name@1.0.0(peer@2.0.0), v5 like /name/1.0.0_peer@2.0.0.
        return Object.entries(lock.packages ?? {})
            .filter(([, entry]) => entry.dev !== 'true')
            .map(([key, entry]) => {
                const id = key.replace(/^\//, '').replace(/\(.*$/, '');
                const at =
                    major >= 6 ? id.lastIndexOf('@') : id.lastIndexOf('/');

                return [
                    id.slice(0, at),
                    id.slice(at + 1).replace(/_.*$/, ''),
                    pnpmRequires(entry),
                ];
            });
    }

    const roots = Object.values(lock.importers ?? {}).flatMap((importer) =>
        prodEntries(importer).map(([name, dep]) => pnpmId(name, dep.version)),
    );

    return walk(roots, (id) => {
        const snapshot = lock.snapshots?.[id];

        if (!snapshot) {
            return undefined; // link:, file: and workspace packages
        }

        const at = id.indexOf('@', 1);

        return {
            id,
            name: id.slice(0, at),
            version: id.slice(at + 1).replace(/\(.*$/, ''),
            requires: pnpmRequires(snapshot),
            deps: prodEntries(snapshot).map(([name, version]) =>
                pnpmId(name, version),
            ),
        };
    });
}

/** pnpm records the version each dependency resolved to, not its range; peer suffixes dropped. */
function pnpmRequires(entry) {
    return Object.fromEntries(
        prodEntries(entry).map(([name, version]) => [
            name,
            version.replace(/\(.*$/, '').replace(/_.*$/, ''),
        ]),
    );
}

/** An npm alias (`"my-lodash": "lodash@4.17.21"`) already names the real package. */
function pnpmId(name, version) {
    return /^@?[^@(]+@/.test(version) ? version : `${name}@${version}`;
}

/**
 * yarn.lock, classic (v1) or Berry (v2+). Neither flags dev packages, so walk
 * from package.json's dependencies through each entry's descriptors.
 */
async function readYarn(lockfile) {
    const lock = parseYamlish(await readFile(lockfile, 'utf8'));
    // ponytail: roots are the package.json next to the lockfile only; workspace packages' own deps aren't walked
    const manifest = JSON.parse(
        await readFile(join(dirname(lockfile), 'package.json'), 'utf8'),
    );
    const entries = new Map();

    for (const [header, entry] of Object.entries(lock)) {
        // A header lists every range that resolved to this entry: "a@^1.0.0", "a@^1.2.0"
        for (const descriptor of header.split(/["']?,\s*["']?/)) {
            entries.set(descriptor.replace(/^["']|["']$/g, ''), entry);
        }
    }

    const roots = prodEntries(manifest).map(
        ([name, range]) => `${name}@${range}`,
    );

    return walk(roots, (descriptor) => {
        const at = descriptor.indexOf('@', 1);
        const name = descriptor.slice(0, at);
        // Berry prefixes registry ranges with npm:, package.json doesn't.
        const entry =
            entries.get(descriptor) ??
            entries.get(`${name}@npm:${descriptor.slice(at + 1)}`);

        if (!entry?.version || entry.version.endsWith('-use.local')) {
            return undefined; // workspace or unresolved
        }

        return {
            id: `${name}@${entry.version}`,
            name,
            version: entry.version,
            requires: requiresOf(entry),
            deps: prodEntries(entry).map(([dep, range]) => `${dep}@${range}`),
        };
    });
}

/**
 * bun.lock (Bun 1.2+), JSON with trailing commas. Packages are keyed by
 * install path ("parent/child" for a nested copy); dev packages aren't flagged.
 */
async function readBun(lockfile) {
    const lock = JSON.parse(
        (await readFile(lockfile, 'utf8')).replace(/,(\s*[}\]])/g, '$1'),
    );
    const roots = Object.values(lock.workspaces ?? {}).flatMap((workspace) =>
        prodEntries(workspace).map(([name]) => ({ name, parent: '' })),
    );

    return walk(roots, ({ name, parent }) => {
        // The closest nested copy (parent/name, grandparent/name, ...) wins over
        // the hoisted one, as in node_modules.
        let scope = parent;

        while (scope && !lock.packages?.[`${scope}/${name}`]) {
            scope = scope.slice(0, Math.max(0, scope.lastIndexOf('/')));
        }

        const key = scope ? `${scope}/${name}` : name;

        const [spec, , meta] = lock.packages?.[key] ?? [];
        const version = spec?.slice(spec.indexOf('@', 1) + 1);

        if (!version || version.startsWith('workspace:')) {
            return undefined;
        }

        return {
            id: key,
            name,
            version,
            requires: requiresOf(meta ?? {}),
            deps: prodEntries(meta ?? {}).map(([dep]) => ({
                name: dep,
                parent: key,
            })),
        };
    });
}

/** dependencies + optionalDependencies of a manifest or lock entry; devDependencies are left out. */
function prodEntries(manifest) {
    return [
        ...Object.entries(manifest.dependencies ?? {}),
        ...Object.entries(manifest.optionalDependencies ?? {}),
    ];
}

/** name => range for a manifest or lock entry. Berry's "npm:" prefix is dropped so the range reads as semver. */
function requiresOf(manifest) {
    return Object.fromEntries(
        prodEntries(manifest).map(([name, range]) => [
            name,
            String(range).replace(/^npm:(?=[\d^~<>=*x])/, ''),
        ]),
    );
}

/**
 * Breadth-first walk from the root refs, so the copy closest to your own
 * dependencies comes first. resolve(ref) returns a node or undefined to skip it.
 */
function walk(roots, resolve) {
    const seen = new Set();
    const found = [];
    const queue = [...roots];

    for (let i = 0; i < queue.length; i++) {
        const node = resolve(queue[i]);

        if (node && !seen.has(node.id)) {
            seen.add(node.id);
            found.push([node.name, node.version, node.requires]);
            queue.push(...node.deps);
        }
    }

    return found;
}

/**
 * Just enough YAML for pnpm-lock.yaml and yarn.lock (classic and Berry):
 * indentation-nested maps of scalar values. Lists and multi-line strings are
 * ignored, since none of the fields read above use them.
 */
function parseYamlish(text) {
    const root = {};
    const stack = [{ indent: -1, map: root }];
    const unquote = (value) =>
        /^(["']).*\1$/.test(value) ? value.slice(1, -1) : value;

    for (const raw of text.split(/\r?\n/)) {
        const line = raw.trim();

        if (line === '' || line.startsWith('#') || line.startsWith('- ')) {
            continue;
        }

        const indent = raw.length - raw.trimStart().length;

        while (indent <= stack.at(-1).indent) {
            stack.pop();
        }

        const parent = stack.at(-1).map;

        if (line.endsWith(':')) {
            const map = {};
            parent[unquote(line.slice(0, -1))] = map;
            stack.push({ indent, map });
            continue;
        }

        let key;
        let value;

        if (line[0] === '"' || line[0] === "'") {
            const close = line.indexOf(line[0], 1);
            key = line.slice(1, close);
            value = line.slice(close + 1).replace(/^:/, '');
        } else {
            // YAML is `key: value`, classic yarn.lock is `key value`.
            const match =
                line.match(/^(\S+?):\s+(.*)$/) ?? line.match(/^(\S+)\s+(.*)$/);

            if (!match) {
                continue;
            }

            [, key, value] = match;
        }

        parent[key] = unquote(value.trim());
    }

    return root;
}

const SOURCE_EXTENSIONS = new Set([
    '.js',
    '.mjs',
    '.cjs',
    '.jsx',
    '.ts',
    '.mts',
    '.cts',
    '.tsx',
    '.vue',
    '.svelte',
    '.astro',
    '.mdx',
]);
const SKIPPED_DIRECTORIES = new Set([
    'node_modules',
    '.git',
    'dist',
    'build',
    'out',
    'coverage',
    'vendor',
    'public',
    '.next',
    '.nuxt',
    '.output',
    '.astro',
    '.svelte-kit',
    '.vercel',
    '.turbo',
    '.cache',
]);
const MAX_FILES = 5000;
const MAX_SITES = 20;

// Each pattern's last group is the module specifier. `import type` is left out: it never runs.
const IMPORT_PATTERNS = [
    /\bimport\s+(?!type\s)(?:[\w$*{}\s,]+?\s+from\s+)?(['"])([^'"\n]+)\1/g,
    /\bexport\s+(?!type\s)[\w$*{}\s,]+?\s+from\s+(['"])([^'"\n]+)\1/g,
    /\bimport\s*\(\s*(['"])([^'"\n]+)\1\s*\)/g,
    /\brequire\s*\(\s*(['"])([^'"\n]+)\1\s*\)/g,
];

// Config, tests and top-level scripts/ load packages to build or check the app, not as part of it.
const TOOLING_PATH =
    /^scripts\/|(^|\/)(tests?|__tests__|__mocks__|e2e|cypress|playwright|\.storybook)\/|\.(test|spec|stories)\.[^/]+$|(^|\/)[^/]+\.config\.[^/]+$|(^|\/)\.[^/]+rc\.[^/]+$/;

/**
 * Where your code imports each package: walk the source (skipping build output
 * and node_modules), pull every import/export/require/import() specifier out of
 * each file, and map it to a package from the lockfile. Also notes which direct
 * dependencies run as a CLI from package.json scripts. Every direct dependency
 * gets an entry, so "imported nowhere" is reported rather than left out.
 *
 * ponytail: a regex scan, not a parser; strings that look like imports count, which only errs towards "used".
 */
export async function collectUsage(
    source,
    { manifest, requires },
    packages,
    lockfile,
) {
    const usage = new Map(
        Object.keys(requires).map((name) => [
            name,
            { name, bin: false, sites: [] },
        ]),
    );
    const files = await sourceFiles(source);

    for (const file of files) {
        const path = relative(source, file).split(sep).join('/');
        const text = stripComments(await readFile(file, 'utf8'));

        for (const pattern of IMPORT_PATTERNS) {
            for (const match of text.matchAll(pattern)) {
                const name = packageOf(match[2]);

                if (!name || !packages.has(name)) {
                    continue;
                }

                const entry = usage.get(name) ?? {
                    name,
                    bin: false,
                    sites: [],
                };
                usage.set(name, entry);

                if (entry.sites.length < MAX_SITES) {
                    const at = match.index + match[0].lastIndexOf(match[2]);

                    entry.sites.push({
                        file: path,
                        line: text.slice(0, at).split('\n').length,
                        kind: TOOLING_PATH.test(path) ? 'tooling' : 'source',
                    });
                }
            }
        }
    }

    const scripts = Object.values(manifest.scripts ?? {}).join('\n');

    for (const entry of usage.values()) {
        if (Object.hasOwn(requires, entry.name)) {
            entry.bin = (await binNames(entry.name, lockfile)).some((bin) =>
                new RegExp(
                    `(^|[\\s;&|(])${escapeRegExp(bin)}($|[\\s;&|)])`,
                    'm',
                ).test(scripts),
            );
        }
    }

    return [...usage.values()];
}

async function sourceFiles(directory, found = []) {
    for (const entry of await readdir(directory, { withFileTypes: true })) {
        if (found.length >= MAX_FILES) {
            break;
        }

        const path = join(directory, entry.name);

        if (entry.isDirectory() && !SKIPPED_DIRECTORIES.has(entry.name)) {
            await sourceFiles(path, found);
        } else if (
            entry.isFile() &&
            SOURCE_EXTENSIONS.has(extname(entry.name)) &&
            !entry.name.endsWith('.d.ts') &&
            (await stat(path)).size < 1_000_000
        ) {
            found.push(path);
        }
    }

    return found;
}

/** Blank out comments, keeping newlines so line numbers still match the file. */
function stripComments(text) {
    return text
        .replace(/\/\*[\s\S]*?\*\//g, (comment) =>
            comment.replace(/[^\n]/g, ' '),
        )
        .replace(/(^|[^:\\'"`])\/\/[^\n]*/g, '$1');
}

/** The package a specifier loads from: "@scope/pkg/sub" → "@scope/pkg", "pkg/sub" → "pkg". */
function packageOf(specifier) {
    // Relative paths, URLs, node:, virtual:, astro:, and path aliases like @/ or ~/ aren't packages.
    if (/^[./#~]|^@\/|:/.test(specifier)) {
        return null;
    }

    const parts = specifier.split('/');

    return specifier.startsWith('@') ? parts.slice(0, 2).join('/') : parts[0];
}

/** The commands a package installs, falling back to its unscoped name when node_modules isn't there. */
async function binNames(name, lockfile) {
    try {
        const manifest = JSON.parse(
            await readFile(
                join(dirname(lockfile), 'node_modules', name, 'package.json'),
                'utf8',
            ),
        );

        if (typeof manifest.bin === 'string') {
            return [name.split('/').pop()];
        }

        if (manifest.bin) {
            return Object.keys(manifest.bin);
        }
    } catch {
        // not installed here
    }

    return [name.split('/').pop()];
}

function escapeRegExp(text) {
    return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** Send one heartbeat. Throws on a missing config, network error or non-2xx response. */
export async function reportOnce({
    url = process.env.PATCHWATCH_URL,
    token = process.env.PATCHWATCH_TOKEN,
    lockfile = process.env.PATCHWATCH_LOCKFILE ?? findLockfile(),
    source = process.env.PATCHWATCH_SOURCE,
} = {}) {
    if (!url || !token) {
        throw new Error('Set PATCHWATCH_URL and PATCHWATCH_TOKEN.');
    }

    const dependencies = await collectDependencies(lockfile);
    const roots = await readRoots(lockfile).catch(() => null);
    const usage =
        source && roots
            ? await collectUsage(
                  source,
                  roots,
                  new Set(dependencies.map((dependency) => dependency.name)),
                  lockfile,
              )
            : undefined;

    const response = await fetch(url, {
        method: 'POST',
        headers: {
            Authorization: `Bearer ${token}`,
            'Content-Type': 'application/json',
            Accept: 'application/json',
        },
        body: JSON.stringify({
            dependencies,
            requires: roots?.requires,
            usage,
        }),
        signal: AbortSignal.timeout(30_000),
    });

    if (!response.ok) {
        throw new Error(
            `Patchwatch heartbeat failed: ${response.status} ${await response.text()}`,
        );
    }
}

/**
 * Report now and then on an interval. Failures are logged, never thrown, so a
 * Patchwatch outage can't take your service down. Returns a function that stops it.
 */
export function startPatchwatch({ intervalMs = SIX_HOURS, ...options } = {}) {
    const run = () =>
        reportOnce(options).catch((error) =>
            console.warn(`[patchwatch] ${error.message}`),
        );

    void run();
    const timer = setInterval(run, intervalMs);
    timer.unref(); // don't keep the process alive just for heartbeats

    return () => clearInterval(timer);
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
    await reportOnce();
    console.log('[patchwatch] heartbeat sent');
}
