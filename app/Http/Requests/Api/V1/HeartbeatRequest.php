<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Ecosystem;
use App\Models\Service;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HeartbeatRequest extends FormRequest
{
    /**
     * Only service tokens may report telemetry, never user tokens.
     */
    public function authorize(): bool
    {
        return $this->user('sanctum') instanceof Service;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Images are reported by CI scans, not by the running service. The graph (`requires` on the
     * service and on each package) and the usage scan are optional, so older reporters keep working.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'dependencies' => ['present', 'list', 'max:5000'],
            'dependencies.*.ecosystem' => ['required', Rule::enum(Ecosystem::class)->except(Ecosystem::Image)],
            'dependencies.*.name' => ['required', 'string', 'max:255'],
            'dependencies.*.version' => ['required', 'string', 'max:100'],
            'dependencies.*.requires' => ['sometimes', 'nullable', 'array', 'max:1000'],
            'requires' => ['sometimes', 'nullable', 'array', 'max:5000'],
            'usage' => ['sometimes', 'nullable', 'list', 'max:5000'],
            'usage.*.name' => ['required', 'string', 'max:255'],
            'usage.*.bin' => ['sometimes', 'boolean'],
            'usage.*.sites' => ['present', 'list', 'max:20'],
            'usage.*.sites.*.file' => ['required', 'string', 'max:500'],
            'usage.*.sites.*.line' => ['required', 'integer', 'min:1'],
            'usage.*.sites.*.kind' => ['required', 'in:source,tooling'],
        ];
    }

    /**
     * The reported packages keyed by "ecosystem|name". npm packages carry their edges and their usage,
     * both null when not reported so stale ones are cleared.
     *
     * @return array<string, array{ecosystem: string, name: string, version: string, requires?: array<string, string|null>|null, usage?: array{sites: list<array{file: string, line: int, kind: 'source'|'tooling'}>, bin: bool}|null}>
     */
    public function manifest(): array
    {
        $usage = [];

        foreach ($this->usageList() as $package) {
            $usage[$package['name']] = [
                'sites' => array_map(fn (array $site) => ['file' => $site['file'], 'line' => $site['line'], 'kind' => $site['kind']], $package['sites']),
                'bin' => $package['bin'] ?? false,
            ];
        }

        $manifest = [];

        foreach ($this->dependencyList() as $dependency) {
            $entry = ['ecosystem' => $dependency['ecosystem'], 'name' => $dependency['name'], 'version' => $dependency['version']];

            if ($dependency['ecosystem'] === Ecosystem::Npm->value) {
                $entry['requires'] = self::ranges($dependency['requires'] ?? null);
                $entry['usage'] = $usage[$dependency['name']] ?? null;
            }

            $manifest[$dependency['ecosystem'].'|'.$dependency['name']] = $entry;
        }

        return $manifest;
    }

    /**
     * The service's own dependencies (its package.json) and their ranges: the roots of its graph.
     *
     * @return array<string, string|null>|null
     */
    public function rootRequires(): ?array
    {
        return self::ranges($this->validated('requires'));
    }

    /**
     * Whether the reporter scanned the code for imports. An empty list still counts: nothing is imported.
     */
    public function usageScanned(): bool
    {
        return is_array($this->validated('usage'));
    }

    /**
     * A name => range map with anything malformed dropped. Validation can't address map keys,
     * since package names like lodash.merge contain dots, so it's checked here instead.
     *
     * @return array<string, string|null>|null
     */
    public static function ranges(mixed $requires): ?array
    {
        if (! is_array($requires)) {
            return null;
        }

        return array_filter(
            $requires,
            fn (mixed $range, int|string $name) => is_string($name) && strlen($name) <= 255 && ($range === null || (is_string($range) && strlen($range) <= 200)),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @return list<array{ecosystem: string, name: string, version: string, requires?: mixed}>
     */
    private function dependencyList(): array
    {
        return $this->validated('dependencies');
    }

    /**
     * @return list<array{name: string, bin?: bool, sites: list<array{file: string, line: int, kind: 'source'|'tooling'}>}>
     */
    private function usageList(): array
    {
        return $this->validated('usage') ?? [];
    }
}
