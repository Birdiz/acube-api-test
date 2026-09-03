<?php

declare(strict_types=1);

namespace App\Conversion\Exception;

use App\Conversion\ConversionStatus;

/**
 * Not 404: the caller has this id from the 202, and being told it does not exist
 * invites restarting the flow. It carries the state instead, which is the part
 * the caller branches on.
 */
final class ResultNotReady extends ConversionProblem
{
    private function __construct(
        string $message,
        private readonly ConversionStatus $conversionStatus,
        private readonly string $statusUrl,
    ) {
        parent::__construct($message);
    }

    public static function forStatus(ConversionStatus $status, string $statusUrl): self
    {
        // Same conflict, but waiting will not fix a failed job.
        $message = match ($status) {
            ConversionStatus::Failed => \sprintf(
                'The conversion failed, so there will be no result. %s says what happened.',
                $statusUrl,
            ),
            default => \sprintf(
                'The conversion is still %s. Poll %s until it reports "%s".',
                $status->value,
                $statusUrl,
                ConversionStatus::Done->value,
            ),
        };

        return new self($message, $status, $statusUrl);
    }

    public function status(): int
    {
        return 409;
    }

    public function type(): string
    {
        return '/errors/result-not-ready';
    }

    public function title(): string
    {
        return 'Result not ready';
    }

    /** Named `conversion_status` because `status` is already the HTTP one. */
    public function extensions(): array
    {
        return [
            'conversion_status' => $this->conversionStatus->value,
            'status_url' => $this->statusUrl,
        ];
    }
}
