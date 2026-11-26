<?php

namespace App\Support;

use Illuminate\Support\Arr;

/**
 * Extracts validated settings values from a request payload.
 *
 * Settings schemas use dotted keys such as `colors.primary`. Laravel's
 * `validated()` and `Request::only()` drop those when the payload sends them as
 * flat keys, so read each key exactly and fall back to nested lookup.
 */
class SettingsInput
{
    /**
     * @param  array<string, mixed>  $input
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    public static function extract(array $input, array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $input)) {
                $values[$key] = $input[$key];

                continue;
            }

            if (Arr::has($input, $key)) {
                $values[$key] = Arr::get($input, $key);
            }
        }

        return $values;
    }
}
