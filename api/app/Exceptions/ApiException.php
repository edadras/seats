<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Every deliberate API failure is one of these, so responses share one envelope and one set of
 * stable machine-readable codes. Clients — notably the WordPress plugin — branch on `code`, so a
 * code is part of the contract and must not be renamed casually.
 */
class ApiException extends \RuntimeException implements HttpExceptionInterface
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $status = 400,
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $message = 'Resource not found.'): self
    {
        return new self('not_found', $message, 404);
    }

    public static function forbidden(string $message = 'You may not perform this action.'): self
    {
        return new self('forbidden', $message, 403);
    }

    public static function unauthorized(string $code, string $message): self
    {
        return new self($code, $message, 401);
    }

    public static function conflict(string $code, string $message, array $details = []): self
    {
        return new self($code, $message, 409, $details);
    }

    public static function unprocessable(string $code, string $message, array $details = []): self
    {
        return new self($code, $message, 422, $details);
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
                'message' => $this->getMessage(),
                'details' => $this->details ?: null,
                'request_id' => $request->attributes->get('request_id'),
            ], fn ($v) => $v !== null),
        ], $this->status);
    }
}
