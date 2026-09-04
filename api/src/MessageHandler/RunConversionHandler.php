<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Conversion\ConversionRunner;
use App\Message\RunConversion;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RunConversionHandler
{
    public function __construct(private ConversionRunner $runner)
    {
    }

    public function __invoke(RunConversion $message): void
    {
        $this->runner->run($message->conversionId);
    }
}
