<?php

namespace App\Repository;

use App\Entity\FileExtension;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FileExtension>
 */
class FileExtensionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FileExtension::class);
    }

    /**
     * Find extensions by names
     * 
     * @param array $names Array of extension names
     * @return array
     */
    public function findByNames(array $names): array
    {
        $lowerNames = array_map('strtolower', $names);
        
        return $this->createQueryBuilder('e')
            ->where('e.name IN (:names)')
            ->setParameter('names', $lowerNames)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find extension by name
     */
    public function findOneByName(string $name): ?FileExtension
    {
        return $this->createQueryBuilder('e')
            ->where('e.name = :name')
            ->setParameter('name', strtolower($name))
            ->getQuery()
            ->getOneOrNullResult();
    }
}