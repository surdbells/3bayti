<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Setting;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

/**
 * A durable, admin-editable application setting: one JSON value keyed by a
 * dotted string (e.g. 'otp.providers'). The table is the source of truth;
 * SettingsService puts a short-lived cache in front for hot reads.
 */
#[ORM\Entity(repositoryClass: AppSettingRepository::class)]
#[ORM\Table(name: 'app_settings')]
class AppSetting
{
    #[ORM\Id]
    #[ORM\Column(name: 'setting_key', type: 'string', length: 190)]
    private string $key;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'value', type: 'json')]
    private array $value;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    /** @param array<string, mixed> $value */
    public function __construct(string $key, array $value)
    {
        $this->key = $key;
        $this->value = $value;
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function getKey(): string { return $this->key; }

    /** @return array<string, mixed> */
    public function getValue(): array { return $this->value; }

    public function getUpdatedAt(): DateTimeImmutable { return $this->updatedAt; }

    /** @param array<string, mixed> $value */
    public function setValue(array $value): void
    {
        $this->value = $value;
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
