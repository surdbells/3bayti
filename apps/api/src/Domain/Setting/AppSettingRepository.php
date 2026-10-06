<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Setting;

use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<AppSetting> */
class AppSettingRepository extends EntityRepository
{
    public function findByKey(string $key): ?AppSetting
    {
        return $this->findOneBy(['key' => $key]);
    }

    public function save(AppSetting $setting): void
    {
        $em = $this->getEntityManager();
        $em->persist($setting);
        $em->flush();
    }
}
