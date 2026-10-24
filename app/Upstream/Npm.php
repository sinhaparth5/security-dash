<?php

namespace App\Upstream;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class Npm
{
    /**
     * Packuments already read in this process; the planner asks for the same few many times.
     *
     * @var array<string, array<string, array{dependencies: array<string, string>, deprecated: bool}>>
     */
    private array $read = [];

    /**
     * Every published version of a package and what each one depends on (dependencies plus
     * optionalDependencies, as installed), from the abbreviated packument the npm CLI itself reads.
     * Empty when the public registry doesn't have the package.
     *
     * @return array<string, array{dependencies: array<string, string>, deprecated: bool}>
     */
    public function versions(string $name): array
    {
        return $this->read[$name] ??= Cache::remember('npm:versions:'.$name, now()->addHours(6), function () use ($name) {
            $response = Http::withHeaders(['Accept' => 'application/vnd.npm.install-v1+json'])->timeout(20)
                ->get('https://registry.npmjs.org/'.str_replace('/', '%2F', $name));

            if ($response->notFound()) {
                return [];
            }

            $versions = [];

            foreach ((array) $response->throw()->json('versions') as $version => $manifest) {
                if (! is_array($manifest)) {
                    continue;
                }

                $versions[(string) $version] = [
                    'dependencies' => array_filter(
                        [...(array) ($manifest['dependencies'] ?? []), ...(array) ($manifest['optionalDependencies'] ?? [])],
                        fn (mixed $range, int|string $dependency) => is_string($range) && is_string($dependency),
                        ARRAY_FILTER_USE_BOTH,
                    ),
                    'deprecated' => isset($manifest['deprecated']),
                ];
            }

            return $versions;
        });
    }
}
