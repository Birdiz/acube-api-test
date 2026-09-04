<?php

declare(strict_types=1);

namespace App\Repository;

use App\Conversion\Exception\ConversionProblem;
use App\Entity\Conversion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Conversion> */
final class ConversionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversion::class);
    }

    /** Doctrine answers null; the API answers 404. @throws ConversionProblem */
    public function withId(string $conversionId): Conversion
    {
        return $this->find($conversionId) ?? throw ConversionProblem::unknownConversion($conversionId);
    }
}
