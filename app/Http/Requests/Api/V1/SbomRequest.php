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
     * The npm and Go packages in the SBOM, keyed like the heartbeat manifest.
     *
     * @return array<string, array{ecosystem: string, name: string, version: string}>
     */
    public function dependencies(): array
    {
        $dependencies = [];

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
            }
        }

        return $dependencies;
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
