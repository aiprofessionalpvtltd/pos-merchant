<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class ApiResponse
{
    /**
     * @param  array<string, mixed>  $meta  Extra meta, such as pagination, merged after the standard fields
     */
    public static function success(mixed $data = null, ?string $message = null, int $status = 200, array $meta = []): JsonResponse
    {
        $body = ['success' => true];

        if ($message !== null) {
            $body['message'] = $message;
        }

        $body['data'] = $data;
        $body['meta'] = self::meta() + $meta;

        return response()->json($body, $status);
    }

    public static function error(string $code, string $message, int $status, array $details = [], ?string $field = null): JsonResponse
    {
        $error = ['code' => $code];

        if ($field !== null) {
            $error['field'] = $field;
        }

        if ($details !== []) {
            $error['details'] = $details;
        }

        $response = response()->json([
            'success' => false,
            'message' => $message,
            'error' => $error,
            'meta' => self::meta(),
        ], $status);

        if (isset($details['retry_after'])) {
            $response->header('Retry-After', (string) $details['retry_after']);
        }

        return $response;
    }

    public static function iso($date): ?string
    {
        if ($date === null) {
            return null;
        }

        return \Illuminate\Support\Carbon::parse($date)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private static function meta(): array
    {
        $request = request();

        if (! $request->attributes->has('request_id')) {
            $request->attributes->set('request_id', 'req_'.strtoupper(Str::ulid()->toBase32()));
        }

        return [
            'request_id' => $request->attributes->get('request_id'),
            'server_time' => self::iso(now()),
        ];
    }
}
