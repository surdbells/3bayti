<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Currency;

use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<FxRate>
 */
class FxRateRepository extends EntityRepository
{
    /**
     * Load every rate. v1 stores 5 rows (one per supported
     * currency including the AED identity); the typical load is
     * <1ms. CurrencyConversionService calls this once per request
     * and reads from the in-memory result for every conversion
     * thereafter.
     *
     * @return list<FxRate>
     */
    public function findAllRates(): array
    {
        /** @var list<FxRate> $rows */
        $rows = $this->createQueryBuilder('r')
            ->orderBy('r.targetCode', 'ASC')
            ->getQuery()
            ->getResult();
        return $rows;
    }

    public function findByPair(string $base, string $target): ?FxRate
    {
        return $this->findOneBy([
            'baseCode' => strtoupper($base),
            'targetCode' => strtoupper($target),
        ]);
    }

    public function save(FxRate $rate): void
    {
        $em = $this->getEntityManager();
        $em->persist($rate);
        $em->flush();
    }
}
