<?php

namespace App\Http\Controllers;

use App\Enums\Ecosystem;
use App\Models\Advisory;
use App\Models\AuditEvent;
use App\Models\Dependency;
use App\Models\DependencyGraph;
use App\Models\RiskAcceptance;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class BomController extends Controller
{
    /**
     * CycloneDX severities, by OSV/GHSA severity.
     */
    private const SEVERITY = ['CRITICAL' => 'critical', 'HIGH' => 'high', 'MODERATE' => 'medium', 'MEDIUM' => 'medium', 'LOW' => 'low'];

    /**
     * The service as a CycloneDX 1.6 SBOM with its advisories as VEX statements: what Patchwatch
     * knows about each one, from reachability (the usage scan, the Go symbol scan) and risk acceptances.
     * Container images are left out; they are artifacts of their own.
     */
    public function __invoke(Service $service): JsonResponse
    {
        $service->load(['dependencies.advisories', 'riskAcceptances.user:id,name']);
        $dependencies = $service->dependencies->reject(fn (Dependency $dependency) => $dependency->ecosystem === Ecosystem::Image)
            ->sortBy(fn (Dependency $dependency) => [$dependency->ecosystem->value, $dependency->name])->values();
        $graph = DependencyGraph::of($service, $dependencies);
        $accepted = $service->riskAcceptances->filter(fn (RiskAcceptance $acceptance) => $acceptance->isActive())->keyBy('osv_id');
        $purls = $dependencies->mapWithKeys(fn (Dependency $dependency) => [$dependency->ecosystem->value.'|'.$dependency->name => self::purl($dependency)]);
        $npmRef = fn (string $name) => $purls[Ecosystem::Npm->value.'|'.$name] ?? null;
        $roots = $graph->known()
            ? $dependencies->filter(fn (Dependency $dependency) => $dependency->ecosystem !== Ecosystem::Npm || $graph->isDirect($dependency->name))
            : $dependencies;

        AuditEvent::record('service.sbom_exported', $service);

        return response()->json([
            'bomFormat' => 'CycloneDX',
            'specVersion' => '1.6',
            'serialNumber' => 'urn:uuid:'.Str::uuid(),
            'version' => 1,
            'metadata' => [
                'timestamp' => now()->toIso8601ZuluString(),
                'tools' => ['components' => [['type' => 'application', 'name' => 'Patchwatch']]],
                'component' => ['type' => 'application', 'bom-ref' => 'service', 'name' => $service->name, 'version' => $service->environment],
            ],
            'components' => $dependencies->map(fn (Dependency $dependency) => [
                'type' => $dependency->ecosystem === Ecosystem::Runtime ? 'platform' : 'library',
                'bom-ref' => $purls[$dependency->ecosystem->value.'|'.$dependency->name],
                'name' => $dependency->name,
                'version' => $dependency->version,
                'purl' => $purls[$dependency->ecosystem->value.'|'.$dependency->name],
            ])->all(),
            'dependencies' => [
                ['ref' => 'service', 'dependsOn' => $roots->map(fn (Dependency $dependency) => $purls[$dependency->ecosystem->value.'|'.$dependency->name])->values()->all()],
                ...$dependencies->filter(fn (Dependency $dependency) => $dependency->ecosystem === Ecosystem::Npm && $dependency->requires !== null)
                    ->map(fn (Dependency $dependency) => [
                        'ref' => $purls[Ecosystem::Npm->value.'|'.$dependency->name],
                        'dependsOn' => array_values(array_filter(array_map($npmRef, array_keys($dependency->requires ?? [])))),
                    ])->values()->all(),
            ],
            'vulnerabilities' => $dependencies->flatMap(fn (Dependency $dependency) => $dependency->advisories->map(fn (Advisory $advisory) => array_filter([
                'bom-ref' => $advisory->osv_id.'@'.$purls[$dependency->ecosystem->value.'|'.$dependency->name],
                'id' => $advisory->osv_id,
                'source' => str_starts_with($advisory->osv_id, 'NODE-') || str_starts_with($advisory->osv_id, 'EOL-')
                    ? ['name' => 'Patchwatch']
                    : ['name' => 'OSV', 'url' => 'https://osv.dev/vulnerability/'.rawurlencode($advisory->osv_id)],
                'references' => array_map(fn (string $alias) => ['id' => $alias, 'source' => ['name' => str_starts_with($alias, 'CVE-') ? 'NVD' : 'OSV']], $advisory->aliases),
                'ratings' => $advisory->severity === null ? null : [['severity' => self::SEVERITY[strtoupper($advisory->severity)] ?? 'unknown', 'method' => 'other']],
                'description' => $advisory->summary,
                'affects' => [['ref' => $purls[$dependency->ecosystem->value.'|'.$dependency->name]]],
                'analysis' => self::analysis($advisory, $accepted->get($advisory->osv_id)),
            ])))->values()->all(),
        ], options: JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)->header('Content-Disposition', 'attachment; filename="'.Str::slug($service->name.'-'.$service->environment).'.cdx.json"');
    }

    /**
     * The VEX statement for one advisory. Only reachability is grounds for "not affected"; an accepted
     * risk is a decision not to fix, and everything else is still being worked on.
     *
     * @return array<string, mixed>
     */
    private static function analysis(Advisory $advisory, ?RiskAcceptance $acceptance): array
    {
        return match (true) {
            $advisory->isMalicious() => ['state' => 'exploitable', 'response' => ['update'], 'detail' => 'Reported as malware: remove the package and treat machines that installed it as compromised.'],
            $advisory->reachable === false && $advisory->affected_symbols !== null && $advisory->affected_symbols !== [] => [
                'state' => 'not_affected',
                'justification' => 'code_not_present',
                'detail' => 'None of the vulnerable functions ('.implode(', ', array_slice($advisory->affected_symbols, 0, 10)).') are linked into the binary.',
            ],
            $advisory->reachable === false => [
                'state' => 'not_affected',
                'justification' => 'code_not_reachable',
                'detail' => 'The code never imports or runs the package, or anything that pulls it in.',
            ],
            $acceptance !== null => [
                'state' => 'exploitable',
                'response' => ['will_not_fix'],
                'detail' => 'Risk accepted'.($acceptance->user ? " by {$acceptance->user->name}" : '').": {$acceptance->reason}".($acceptance->expires_at ? " Re-check on {$acceptance->expires_at->toDateString()}." : ''),
            ],
            default => [
                'state' => 'in_triage',
                'response' => [$advisory->fixed_version === null ? 'can_not_fix' : 'update'],
                'detail' => $advisory->fixed_version === null ? 'No fixed version released yet.' : "Fixed in {$advisory->fixed_version}.",
            ],
        };
    }

    /**
     * The package URL for a dependency, e.g. pkg:npm/%40scope/name@1.0.0 or pkg:golang/golang.org/x/net@v0.17.0.
     */
    private static function purl(Dependency $dependency): string
    {
        $version = rawurlencode($dependency->version);

        return match ($dependency->ecosystem) {
            Ecosystem::Npm => 'pkg:npm/'.str_replace('@', '%40', $dependency->name).'@'.$version,
            Ecosystem::Go => 'pkg:golang/'.$dependency->name.'@'.$version,
            default => 'pkg:generic/'.rawurlencode($dependency->name).'@'.$version,
        };
    }
}
