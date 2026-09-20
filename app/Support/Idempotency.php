<?php

namespace App\Support;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Cache;

class Idempotency
{
    private const TTL_SECONDS = 86400;

    /**
     * Runs $callback once per key. A retry with the same body returns the first result;
     * the same key with a different body is refused.
     *
     * @param  array<string, mixed>  $body
     * @param  callable(): array{data: mixed, message: ?string, status: int}  $callback
     * @return array{data: mixed, message: ?string, status: int}
     */
    public static function run(string $scope, ?string $key, array $body, callable $callback): array
    {
        if ($key === null) {
            return $callback();
        }

        $cacheKey = 'idem:'.$scope.':'.$key;
        $hash = md5(json_encode($body));

        return Cache::lock($cacheKey.':lock', 30)->block(10, function () use ($cacheKey, $hash, $callback) {
            $stored = Cache::get($cacheKey);

            if ($stored) {
                if ($stored['hash'] !== $hash) {
                    throw new ApiException('idempotency.key_reused', 'That key was already used for a different request', 409);
                }

                return $stored['result'];
            }

            $result = $callback();
            Cache::put($cacheKey, ['hash' => $hash, 'result' => $result], self::TTL_SECONDS);

            return $result;
        });
    }
}
