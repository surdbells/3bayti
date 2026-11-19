<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Cashback;

use Bayti\Api\Domain\Setting\SettingsService;

/**
 * Thin, mockable wrapper over SettingsService for the cashback programme
 * settings (SettingsService itself is final). Reads never throw: any storage
 * error degrades to the safe default (programme OFF).
 */
class CashbackSettingsStore
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function load(): CashbackProgramSettings
    {
        try {
            return CashbackProgramSettings::fromArray($this->settings->get(CashbackProgramSettings::KEY));
        } catch (\Throwable) {
            return CashbackProgramSettings::default();
        }
    }

    public function save(CashbackProgramSettings $settings): void
    {
        $this->settings->set(CashbackProgramSettings::KEY, $settings->toArray());
    }
}
