<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\GiftCard;

use Bayti\Api\Domain\User\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The customer's gift-card WALLET — the aggregate spendable balance across
 * all the cards they own (as buyer) or have redeemed (as recipient).
 *
 * A single gift card is still a discrete stored-value instrument with its
 * own code and ledger; the "wallet" is simply the sum of the customer's
 * spendable cards, plus a plan for how to spend across them toward an order.
 *
 * At checkout the wallet is applied greedily in the repository's order
 * (soonest-expiry first, see GiftCardRepository::findSpendableByUser) so
 * cards nearest expiry are consumed first. The engine downstream is
 * unchanged: if the wallet covers the whole order the gateway is skipped;
 * otherwise Noon is charged the remainder.
 *
 * This is a thin, stateless helper over the EntityManager — instantiated
 * directly by controllers (no DI surface of its own beyond the EM).
 */
final class GiftCardWalletService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * The user's spendable cards, soonest-expiry first (the order the wallet
     * spends them in).
     *
     * @return list<GiftCard>
     */
    public function spendableCards(User $user): array
    {
        /** @var GiftCardRepository $repo */
        $repo = $this->em->getRepository(GiftCard::class);
        return $repo->findSpendableByUser($user);
    }

    /** Total spendable wallet balance (sum of the spendable card balances). */
    public function balance(User $user): string
    {
        return self::sumBalances($this->spendableCards($user));
    }

    /**
     * Plan how much to draw from each card toward an order total. Greedy in
     * the given card order: fill from each card up to its balance until the
     * order is covered or cards run out.
     *
     * @param list<GiftCard> $cards
     * @return list<array{card: GiftCard, amount: string}>  amounts are DECIMAL(10,2) strings > 0
     */
    public function planApply(array $cards, string $orderTotal): array
    {
        $remaining = bcadd($orderTotal, '0.00', 2);
        $plan = [];
        foreach ($cards as $card) {
            if (bccomp($remaining, '0.00', 2) <= 0) {
                break;
            }
            $bal = bcadd($card->getBalance(), '0.00', 2);
            if (bccomp($bal, '0.00', 2) <= 0) {
                continue;
            }
            $take = bccomp($bal, $remaining, 2) <= 0 ? $bal : $remaining;
            $plan[] = ['card' => $card, 'amount' => $take];
            $remaining = bcsub($remaining, $take, 2);
        }
        return $plan;
    }

    /**
     * Total amount a plan draws from the wallet (sum of the per-card amounts).
     *
     * @param list<array{card: GiftCard, amount: string}> $plan
     */
    public static function planTotal(array $plan): string
    {
        $sum = '0.00';
        foreach ($plan as $row) {
            $sum = bcadd($sum, $row['amount'], 2);
        }
        return $sum;
    }

    /**
     * @param list<GiftCard> $cards
     */
    public static function sumBalances(array $cards): string
    {
        $sum = '0.00';
        foreach ($cards as $card) {
            $sum = bcadd($sum, $card->getBalance(), 2);
        }
        return $sum;
    }
}
