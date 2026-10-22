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
        return $this->user() instanceof Service;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Images are reported by CI scans, not by the running service.
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
        ];
    }
}
