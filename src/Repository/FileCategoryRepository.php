<?php

namespace App\Repository;

use App\Entity\FileCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FileCategory>
 */
class FileCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FileCategory::class);
    }

    /**
     * Find all categories with their extensions
     */
    public function findAllWithExtensions(): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.extensions', 'e')
            ->addSelect('e')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find category by name
     */
    public function findOneByName(string $name): ?FileCategory
    {
        return $this->createQueryBuilder('c')
            ->where('c.name = :name')
            ->setParameter('name', $name)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find category with all extensions for a specific file extension
     */
    public function findCategoryByExtension(string $extension): ?FileCategory
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.extensions', 'e')
            ->where('e.name = :extension')
            ->setParameter('extension', strtolower($extension))
            ->getQuery()
            ->getOneOrNullResult();
    }
}