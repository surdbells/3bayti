<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * M3.2.X.15-A, Multi-currency display: fx_rates table + seed rates.
 *
 * Single additive table holding the AED-base FX rates used by
 * the CurrencyConversionService (X.15-C) to convert catalog prices
 * for display only. Settlement (Carts, Orders, payments, refunds)
 * remains 100% AED, Q-Scope = A locked.
 *
 * Schema
 * ======
 *   id              BIGSERIAL  primary key
 *   base_code       VARCHAR(3) ISO 4217 source currency (always 'AED'
 *                              in v1, kept for future flexibility)
 *   target_code     VARCHAR(3) ISO 4217 target currency
 *   rate            NUMERIC(18, 8)  target per 1 base
 *   updated_at      TIMESTAMP with time zone
 *   updated_by_user_id INTEGER NULL  who pushed the latest value
 *                                    (ON DELETE SET NULL preserves
 *                                    rate when admin user deleted)
 *
 * UNIQUE (base_code, target_code), exactly one rate per pair.
 * INDEX on target_code for the common 'fetch rate for currency X'
 * lookup pattern.
 *
 * Q-RateShape = A locked: AED base. Every product price IS in AED;
 * one multiplication per amount. Inverse-base shapes require
 * either inversion or a chain, slower + error-prone.
 *
 * Q-Currencies = A locked: AED + USD + EUR + SAR + GBP (5
 * currencies in v1).
 *
 * Seed rates are reasonable as of late May 2026. Operator updates
 * via the X.15-F admin endpoint after deploy. The seed rates exist
 * so the conversion service has SOMETHING to work with on first
 * boot, otherwise every non-AED query would fall back to AED on
 * a fresh install.
 *
 * NUMERIC(18, 8) holds rates like 0.27225000 (AED→USD). 18 total
 * digits and 8 fractional digits is comfortable headroom, even
 * volatile pairs like AED→KWD (which a future currency might add)
 * won't exceed 6 fractional places of precision.
 */
final class Version20260518000005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M3.2.X.15-A — fx_rates table + 5 seed rates for display-only multi-currency.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE fx_rates (
                id BIGSERIAL PRIMARY KEY,
                base_code VARCHAR(3) NOT NULL,
                target_code VARCHAR(3) NOT NULL,
                rate NUMERIC(18, 8) NOT NULL,
                updated_at TIMESTAMP WITH TIME ZONE NOT NULL,
                updated_by_user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
                UNIQUE (base_code, target_code)
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_fx_rates_target ON fx_rates (target_code)
        SQL);

        // Seed 5 rows. AED→AED is the identity rate (special-cased
        // in service but stored for completeness + admin UI clarity).
        $this->addSql(<<<'SQL'
            INSERT INTO fx_rates (base_code, target_code, rate, updated_at) VALUES
                ('AED', 'AED', 1.00000000, NOW()),
                ('AED', 'USD', 0.27225000, NOW()),
                ('AED', 'EUR', 0.25180000, NOW()),
                ('AED', 'SAR', 1.02100000, NOW()),
                ('AED', 'GBP', 0.21450000, NOW())
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS fx_rates');
    }
}
