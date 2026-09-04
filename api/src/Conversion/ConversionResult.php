<?php

declare(strict_types=1);

namespace App\Conversion;

use App\Entity\Conversion;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\Filesystem\Exception\IOException;

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

    /** The id is the fallback because it is ASCII and separator-free by construction. */
    public function downloadName(Conversion $conversion): string
    {
        $stem = pathinfo($conversion->file()->originalFilename(), \PATHINFO_FILENAME);

        // Not a separator on Linux, so pathinfo() leaves it in; makeDisposition refuses it.
        $stem = str_replace('\\', '-', $stem);

        return \sprintf(
            '%s.%s',
            '' === $stem ? $conversion->id() : $stem,
            $conversion->targetFormat()->value,
        );
    }

    public function disposition(Conversion $conversion): string
    {
        return HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $this->downloadName($conversion),
            \sprintf('%s.%s', $conversion->id(), $conversion->targetFormat()->value),
        );
    }

    private function pathFor(Conversion $conversion): string
    {
        return $this->directory.'/'.$conversion->id();
    }
}
