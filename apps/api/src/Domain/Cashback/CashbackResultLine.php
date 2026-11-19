<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Cashback;

final class CashbackResultLine
{
    public function __construct(
        public readonly ?int $key,
        public readonly CashbackRule $rule,
        public readonly string $base,
        public readonly string $amount,
    ) {
    }
}
