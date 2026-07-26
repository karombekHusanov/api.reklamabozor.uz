<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A banner link may be either an internal mini-app deep-link (a path starting
 * with a single "/", e.g. "/agents/1") or an absolute external URL over
 * http/https (e.g. "https://example.com"). Protocol-relative ("//host") and
 * other schemes are rejected.
 */
class BannerLinkUrl implements ValidationRule
{
    /**
     * Convenience factory so FormRequest rule arrays read cleanly.
     */
    public static function rule(): self
    {
        return new self;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $isInternal = str_starts_with($value, '/') && ! str_starts_with($value, '//');
        $isExternal = filter_var($value, FILTER_VALIDATE_URL) !== false
            && preg_match('#^https?://#i', $value) === 1;

        if (! $isInternal && ! $isExternal) {
            $fail('The :attribute must be an internal path (starting with "/") or an http(s) URL.')->translate([
                'attribute' => str_replace('_', ' ', $attribute),
            ]);
        }
    }
}
