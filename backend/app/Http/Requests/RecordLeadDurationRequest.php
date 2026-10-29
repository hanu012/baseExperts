<?php

namespace App\Http\Requests;

use App\Models\Lead;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The customer app reporting how long a Call lead's call lasted
 * (approximate — see the add_call_duration_to_leads migration).
 */
class RecordLeadDurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Anything longer is capped, not refused: a customer who left
            // the app in the background for hours still made the call.
            'duration_seconds' => ['required', 'integer', 'min:0', 'max:'.(Lead::MAX_CALL_DURATION_SECONDS * 24)],
        ];
    }
}
