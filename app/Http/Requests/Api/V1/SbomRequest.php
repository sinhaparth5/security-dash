<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Ecosystem;
use App\Models\Service;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SbomRequest extends FormRequest
{
    /**
     * Only service tokens may report telemetry, never user tokens.
     */
    public function authorize(): bool
    {
        return $this->user('sanctum') instanceof Service;
    }

    /**
     * A CycloneDX JSON document. Components are read leniently: anything without
     * an npm or Go package URL is skipped rather than rejected.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'bomFormat' => ['required', 'in:CycloneDX'],
            'components' => ['present', 'array'],
        ];
    }

    /**
     * The npm and Go packages in the SBOM, keyed like the heartbeat manifest. npm packages get their
     * edges from the SBOM's dependency graph (no ranges: an SBOM records what resolved, not what was asked for).
     *
     * @return array<string, array{ecosystem: string, name: string, version: string, requires?: array<string, string|null>|null}>
     */
    public function dependencies(): array
    {
        $dependencies = [];
        $edges = $this->edges();

        foreach ($this->flatten((array) $this->input('components')) as $component) {
            // pkg:type/namespace/name@version?qualifiers#subpath
            if (! is_string($component['purl'] ?? null) || ! preg_match('~^pkg:(npm|golang)/([^@?#]+)@([^?#]+)~', $component['purl'], $match)) {
                continue;
            }

            $ecosystem = $match[1] === 'npm' ? Ecosystem::Npm : Ecosystem::Go;
            $name = rawurldecode($match[2]);
            $version = rawurldecode($match[3]);

            if (strlen($name) <= 255 && strlen($version) <= 100) {
                $dependencies[$ecosystem->value.'|'.$name] = ['ecosystem' => $ecosystem->value, 'name' => $name, 'version' => $version];

                if ($ecosystem === Ecosystem::Npm) {
                    $dependencies[$ecosystem->value.'|'.$name]['requires'] = $edges === null ? null : $edges[(string) ($component['bom-ref'] ?? '')] ?? [];
                }
            }
        }

        return $dependencies;
    }

    /**
     * What the SBOM's own component (the service) depends on directly, or null without a graph.
     *
     * @return array<string, string|null>|null
     */
    public function rootRequires(): ?array
    {
        $root = $this->input('metadata.component.bom-ref');
        $edges = $this->edges();

        return $edges === null ? null : $edges[is_string($root) ? $root : ''] ?? [];
    }

    /**
     * The `dependencies` section as bom-ref => [npm package name => null], or null when the SBOM has none.
     *
     * @return array<string, array<string, null>>|null
     */
    private function edges(): ?array
    {
        $graph = $this->input('dependencies');

        if (! is_array($graph)) {
            return null;
        }

        $names = [];

        foreach ($this->flatten((array) $this->input('components')) as $component) {
            if (is_string($component['bom-ref'] ?? null) && is_string($component['purl'] ?? null) && preg_match('~^pkg:npm/([^@?#]+)@~', $component['purl'], $match)) {
                $names[$component['bom-ref']] = rawurldecode($match[1]);
            }
        }

        $edges = [];

        foreach ($graph as $node) {
            if (is_array($node) && is_string($node['ref'] ?? null)) {
                $children = array_filter((array) ($node['dependsOn'] ?? []), fn (mixed $ref) => is_string($ref) && isset($names[$ref]));
                $edges[$node['ref']] = array_fill_keys(array_map(fn (string $ref) => $names[$ref], $children), null);
            }
        }

        return $edges;
    }

    /**
     * CycloneDX nests components inside components; walk them all.
     *
     * @param  array<mixed>  $components
     * @return iterable<array<string, mixed>>
     */
    private function flatten(array $components): iterable
    {
        foreach ($components as $component) {
            if (is_array($component)) {
                yield $component;
                yield from $this->flatten((array) ($component['components'] ?? []));
            }
        }
    }
}
