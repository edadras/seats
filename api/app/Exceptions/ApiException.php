<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Lang;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Every deliberate API failure is one of these, so responses share one envelope and one set of
 * stable machine-readable codes. Clients — notably the WordPress plugin — branch on `code`, so a
 * code is part of the contract and must not be renamed casually.
 *
 * The **code is also the translation key** (ADR-0005): a failure with code `seat_unavailable`
 * renders `errors.seat_unavailable` in the reader's language. Where one code carries more than one
 * message — `not_found` says several different things — the call site names a key instead.
 *
 * Resolution happens at render time, not at construction, because the locale is settled by
 * middleware and some of these are thrown from inside it. The English message passed to the
 * constructor stays as what `getMessage()` returns, which is what logs and stack traces want.
 */
class ApiException extends \RuntimeException implements HttpExceptionInterface
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $status = 400,
        private readonly array $details = [],
        private readonly ?string $messageKey = null,
        private readonly array $replace = [],
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $message = 'Resource not found.', ?string $key = null): self
    {
        return new self('not_found', $message, 404, [], $key);
    }

    public static function forbidden(string $message = 'You may not perform this action.', ?string $key = null): self
    {
        return new self('forbidden', $message, 403, [], $key);
    }

    public static function unauthorized(string $code, string $message, ?string $key = null): self
    {
        return new self($code, $message, 401, [], $key);
    }

    public static function conflict(string $code, string $message, array $details = [], array $replace = []): self
    {
        return new self($code, $message, 409, $details, null, $replace);
    }

    public static function unprocessable(string $code, string $message, array $details = [], array $replace = []): self
    {
        return new self($code, $message, 422, $details, null, $replace);
    }

    public static function seatsUnavailable(array $seatIds): self
    {
        return new self(
            'seat_unavailable',
            'One or more of the requested seats are no longer available.',
            409,
            ['unavailable_seat_ids' => array_values($seatIds)],
        );
    }

    /**
     * The message in the reader's language, falling back to the English one the call site wrote.
     *
     * The fallback is not a formality: a code added tomorrow without a catalogue entry should say
     * something true in English rather than print its own key at a buyer.
     */
    public function localisedMessage(): string
    {
        $key = 'errors.'.($this->messageKey ?? $this->errorCode);

        return Lang::has($key) ? (string) __($key, $this->replace) : $this->getMessage();
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function details(): array
    {
        return $this->details;
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getHeaders(): array
    {
        return [];
    }

    public function render($request): JsonResponse
    {
        return response()->json([
            'error' => array_filter([
                'code' => $this->errorCode,
                'message' => $this->localisedMessage(),
                'details' => $this->details ?: null,
                'request_id' => $request->attributes->get('request_id'),
            ], fn ($v) => $v !== null),
        ], $this->status);
    }
}
