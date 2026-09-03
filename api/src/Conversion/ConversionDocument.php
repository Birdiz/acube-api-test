<?php

declare(strict_types=1);

namespace App\Conversion;

use App\Entity\Conversion;

/** The same keys in every state, so a caller learns from the values, not from which ones turned up. */
final class ConversionDocument
{
    /** @return array<string, string|null> */
    public function of(Conversion $conversion): array
    {
        return [
            'id' => $conversion->id(),
            'status' => $conversion->status()->value,
            'format' => $conversion->targetFormat()->value,
            'file_id' => $conversion->file()->id(),
            'created_at' => $conversion->createdAt()->format(\DateTimeInterface::ATOM),
            'completed_at' => $conversion->completedAt()?->format(\DateTimeInterface::ATOM),
            'error' => $conversion->errorMessage(),
        ];
    }
}
