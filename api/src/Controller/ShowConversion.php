<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Conversion;
use App\Repository\ConversionRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
final readonly class ShowConversion
{
    public function __construct(private ConversionRepository $conversions)
    {
    }

    public function __invoke(string $id): Response
    {
        $response = new JsonResponse($this->document($this->conversions->withId($id)));

        // A polled status that may be cached is a status that lies.
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * The same keys in every state, so a caller learns from the values, not
     * from which ones turned up.
     *
     * @return array<string, string|null>
     */
    private function document(Conversion $conversion): array
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
