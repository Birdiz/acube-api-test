<?php

declare(strict_types=1);

namespace App\Conversion;

use App\Entity\Conversion;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\HeaderUtils;

/** As with an upload, the location is derived from the id: a stored path would be one container's. */
final readonly class ConversionResult
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/results')]
        private string $directory,
        private Filesystem $filesystem,
    ) {
    }

    public function store(Conversion $conversion, string $payload): void
    {
        $this->filesystem->dumpFile($this->pathFor($conversion), $payload);
    }

    public function contents(Conversion $conversion): string
    {
        return $this->filesystem->readFile($this->pathFor($conversion));
    }

    /**
     * Named after the conversion, not after the file it came from: the id is
     * ASCII and separator-free by construction, so there is nothing to sanitise
     * and no fallback to pick when sanitising leaves nothing behind. The caller
     * still has the original name — they sent it.
     */
    public function disposition(Conversion $conversion): string
    {
        return HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            \sprintf('%s.%s', $conversion->id(), $conversion->targetFormat()->value),
        );
    }

    private function pathFor(Conversion $conversion): string
    {
        return $this->directory.'/'.$conversion->id();
    }
}
