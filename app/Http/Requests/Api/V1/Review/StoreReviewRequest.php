<?php

namespace App\Http\Requests\Api\V1\Review;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreReviewRequest extends FormRequest
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
            'criteria' => ['nullable', 'array'],
            'criteria.*.code' => ['required', 'string', 'max:64'],
            'criteria.*.score' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** A review needs at least one score or a comment. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $hasCriteria = ! empty($this->input('criteria'));
            $hasComment = trim((string) $this->input('comment', '')) !== '';

            if (! $hasCriteria && ! $hasComment) {
                $validator->errors()->add('criteria', 'Provide at least one rating or a comment.');
            }
        });
    }
}
