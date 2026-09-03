<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\File;
use App\File\Exception\UnknownFile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<File> */
final class FileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, File::class);
    }

    /** Doctrine answers null; the API answers 404. @throws UnknownFile */
    public function withId(string $fileId): File
    {
        return $this->find($fileId) ?? throw UnknownFile::withId($fileId);
    }
}
