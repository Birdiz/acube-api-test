<?php

declare(strict_types=1);

namespace App\Conversion;

use App\Entity\Conversion;
use App\Repository\ConversionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * The conversion is a stub — the exercise is the lifecycle — so it never opens
 * the source file. It does not catch either: a failure belongs to Messenger's
 * retries, and is recorded once they are spent.
 */
final class ConversionRunner
{
    public function __construct(
        private readonly ConversionRepository $conversions,
        private readonly ConversionResult $result,
        private readonly SerializerInterface $serializer,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function run(string $conversionId): void
    {
        $conversion = $this->conversions->withId($conversionId);

        // A result already handed out must not change, so a redelivery of
        // finished work is acknowledged rather than redone.
        match ($conversion->status()) {
            ConversionStatus::Pending, ConversionStatus::Processing => $this->convert($conversion),
            ConversionStatus::Done, ConversionStatus::Failed => null,
        };
    }

    private function convert(Conversion $conversion): void
    {
        $conversion->markProcessing();
        $this->entityManager->flush();

        // Bytes first: a conversion is only done once there is something to serve.
        $this->result->store($conversion, $this->placeholder($conversion));

        $conversion->markDone();
        $this->entityManager->flush();
    }

    /** TargetFormat's values are the Serializer's format names too, so "xml" is spelled once. */
    private function placeholder(Conversion $conversion): string
    {
        return $this->serializer->serialize(
            [
                'id' => $conversion->id(),
                'file_id' => $conversion->file()->id(),
                'source_format' => $conversion->file()->sourceFormat()->value,
                'target_format' => $conversion->targetFormat()->value,
                'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'note' => 'Placeholder output: this build records the conversion lifecycle and does not read the source file.',
            ],
            $conversion->targetFormat()->value,
            ['xml_root_node_name' => 'conversion'],
        );
    }
}
