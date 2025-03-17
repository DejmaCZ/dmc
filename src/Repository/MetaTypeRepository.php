<?php

namespace App\Repository;

use App\Entity\MetaType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MetaType>
 */
class MetaTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MetaType::class);
    }

    /**
     * @return MetaType[] Returns an array of MetaType objects
     */
    public function findByDataType(string $dataType): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.dataType = :val')
            ->setParameter('val', $dataType)
            ->orderBy('m.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return MetaType[] Returns an array of MetaType objects
     */
    public function findAllSorted(): array
    {
        return $this->createQueryBuilder('m')
            ->orderBy('m.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByName(string $name): ?MetaType
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.name = :val')
            ->setParameter('val', $name)
            ->getQuery()
            ->getOneOrNullResult();
    }
}