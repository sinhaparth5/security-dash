<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Service;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The unmodified output of `trivy image --format json`.
 */
class ImageScanRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ArtifactName' => ['required', 'string', 'max:255'],
            'Results' => ['sometimes', 'list', 'max:500'],
            'Results.*.Vulnerabilities' => ['nullable', 'list', 'max:5000'],
            'Results.*.Vulnerabilities.*.VulnerabilityID' => ['required', 'string', 'max:255'],
            'Results.*.Vulnerabilities.*.PkgName' => ['required', 'string', 'max:255'],
            'Results.*.Vulnerabilities.*.InstalledVersion' => ['nullable', 'string', 'max:100'],
            'Results.*.Vulnerabilities.*.FixedVersion' => ['nullable', 'string', 'max:255'],
            'Results.*.Vulnerabilities.*.Severity' => ['nullable', 'string', 'max:20'],
            'Results.*.Vulnerabilities.*.Title' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Every validated finding across all scan results.
     *
     * @return list<array{VulnerabilityID: string, PkgName: string, InstalledVersion?: string|null, FixedVersion?: string|null, Severity?: string|null, Title?: string|null}>
     */
    public function vulnerabilities(): array
    {
        return $this->validated('Results.*.Vulnerabilities.*', []);
    }
}
