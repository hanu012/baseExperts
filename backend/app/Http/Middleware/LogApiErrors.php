<?php

namespace App\Http\Middleware;

use App\Models\ApiErrorLog;
use App\Models\Setting;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records failed api/* calls for System → API error log, while admin
 * Settings → Error log is on.
 *
 * Global middleware, not api-group: that way it also sees a 404 for an
 * api/ URL that matches no route, and anything a group or route middleware
 * refuses (401, 403, 429). The exception behind a response, when there was
 * one, comes from `$response->exception`, which Laravel sets when it turns
 * an exception into a response.
 *
 * WHAT COUNTS AS FAILED: an HTTP status of 400 or more, OR a JSON reply
 * whose envelope says `"success": false` whatever its status. The apps
 * treat `success: false` as an error (CommonResponse.isSuccess), so a
 * failure sent with a 200 is still a failure to the user — and the one
 * kind most likely to go unnoticed, which is why it is recorded at both
 * levels, not only "all".
 *
 * Cheap on the happy path: a successful response costs an integer
 * comparison and a substring check on the body — the setting is only read,
 * and the JSON only decoded, once a call looks failed.
 *
 * Never breaks a request: anything that goes wrong while recording is
 * caught, logged to the normal Laravel log, and the response goes out
 * unchanged.
 */
class LogApiErrors
{
    /** Request fields never stored, matched case-insensitively anywhere in the name. */
    private const REDACT = ['password', 'token', 'secret', 'otp', 'authorization', 'api_key', 'apikey', 'card', 'cvv'];

    private const MAX_TRACE_FRAMES = 25;

    private const MAX_STRING = 2000;

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);

        $response = $next($request);

        if ($request->is('api/*') && ($response->getStatusCode() >= 400 || self::reportsFailure($response))) {
            $this->record($request, $response, $startedAt);
        }

        return $response;
    }

    /**
     * A JSON reply whose envelope says `"success": false`. The substring
     * check keeps ordinary successful replies from being decoded at all.
     */
    public static function reportsFailure(Response $response): bool
    {
        if (! $response instanceof JsonResponse || ! str_contains((string) $response->getContent(), '"success":false')) {
            return false;
        }

        $body = $response->getData(true);

        return is_array($body) && ($body['success'] ?? null) === false;
    }

    private function record(Request $request, Response $response, float $startedAt): void
    {
        try {
            if (! Setting::get('api_error_log_enabled', false)) {
                return;
            }

            $status = $response->getStatusCode();

            // "Server errors only" skips 4xx. A success:false sent with a
            // 2xx/3xx status is always kept — see the class docblock.
            if ($status >= 400 && $status < 500 && Setting::get('api_error_log_level', 'server') !== 'all') {
                return;
            }

            $exception = property_exists($response, 'exception') ? $response->exception : null;
            $body = $response instanceof JsonResponse ? $response->getData(true) : null;

            ApiErrorLog::create([
                'status' => $status,
                'method' => $request->method(),
                'path' => Str::limit('/'.ltrim($request->path(), '/'), 495, ''),
                'error_code' => is_array($body) ? Str::limit((string) data_get($body, 'error.code', ''), 95, '') ?: null : null,
                'message' => $this->message($body, $exception),
                'exception_class' => $exception ? get_class($exception) : null,
                'exception_location' => $exception
                    ? Str::limit(str_replace(base_path().DIRECTORY_SEPARATOR, '', $exception->getFile()).':'.$exception->getLine(), 250, '')
                    : null,
                'trace' => $exception ? $this->trace($exception) : null,
                'user_id' => $this->userId($request),
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 495, '') ?: null,
                'request_payload' => $this->redact($request->all() + $this->files($request)) ?: null,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Could not record an API error', ['error' => $e->getMessage()]);
        }
    }

    /** The API's own message, else the exception's — what an admin reads first. */
    private function message(?array $body, ?\Throwable $exception): ?string
    {
        $message = is_array($body) ? data_get($body, 'error.message') : null;

        // A 500 hides the real cause from the app ("Server error"); the log
        // is exactly where an admin needs it.
        if ($exception && (! is_string($message) || $message === '' || $message === 'Server Error')) {
            $message = $exception->getMessage();
        }

        // Validation: list the field errors too, not just "invalid".
        $fields = is_array($body) ? data_get($body, 'error.fields') : null;

        if (is_array($fields) && $fields !== []) {
            $message = trim(($message ?? '').' '.json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return is_string($message) && $message !== '' ? Str::limit($message, self::MAX_STRING) : null;
    }

    private function trace(\Throwable $exception): string
    {
        $lines = [];

        foreach (array_slice($exception->getTrace(), 0, self::MAX_TRACE_FRAMES) as $index => $frame) {
            $where = isset($frame['file'])
                ? str_replace(base_path().DIRECTORY_SEPARATOR, '', $frame['file']).':'.($frame['line'] ?? '?')
                : '[internal]';
            $call = ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '');

            $lines[] = "#{$index} {$where} {$call}()";
        }

        return implode("\n", $lines);
    }

    private function userId(Request $request): ?int
    {
        try {
            // API calls authenticate with a Sanctum bearer token, resolved
            // here directly: the default guard is the panel's session one.
            return auth('sanctum')->id();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Uploaded files, described rather than stored. */
    private function files(Request $request): array
    {
        return collect($request->allFiles())
            ->map(fn ($file) => $file instanceof UploadedFile
                ? sprintf('[file %s, %s, %d KB]', $file->getClientOriginalName(), $file->getClientMimeType(), (int) ceil($file->getSize() / 1024))
                : '[files]')
            ->all();
    }

    /**
     * Blanks any field whose name looks secret, at any depth, and trims long
     * values.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && Str::contains(strtolower($key), self::REDACT)) {
                $data[$key] = '[hidden]';

                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::redact($value);
            } elseif (is_string($value)) {
                $data[$key] = Str::limit($value, 500);
            }
        }

        return $data;
    }
}
