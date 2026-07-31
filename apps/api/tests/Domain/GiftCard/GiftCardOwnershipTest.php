<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\GiftCard;

use Bayti\Api\Domain\GiftCard\GiftCard;
use Bayti\Api\Domain\User\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Who may SPEND a gift card.
 *
 * A card bought as a gift for someone else must not be spendable by the buyer
 * (it would otherwise sit in their wallet and be drained at checkout). The
 * buyer's escape hatch is redeeming it back to their own account, which
 * assigns recipient_user.
 */
#[CoversClass(GiftCard::class)]
final class GiftCardOwnershipTest extends TestCase
{
    private function user(int $id, string $email): User
    {
        $u = new User($email, '+9715000000' . $id, password_hash('p', PASSWORD_BCRYPT), 'AE');
        $rp = new \ReflectionProperty(User::class, 'id');
        $rp->setAccessible(true);
        $rp->setValue($u, $id);
        return $u;
    }

    private function card(User $buyer, ?string $name = null, ?string $email = null, ?string $phone = null): GiftCard
    {
        return new GiftCard(
            buyerUser: $buyer,
            denomination: '500.00',
            theme: GiftCard::THEME_BIRTHDAY,
            recipientName: $name,
            recipientMessage: null,
            recipientPhotoUrl: null,
            scheduledDeliveryAt: null,
            recipientEmail: $email,
            recipientPhone: $phone,
        );
    }

    #[Test]
    public function selfPurchaseIsSpendableByTheBuyer(): void
    {
        $buyer = $this->user(1, 'buyer@example.com');
        $card  = $this->card($buyer); // no recipient details at all

        self::assertFalse($card->isGiftForSomeoneElse());
        self::assertTrue($card->isSpendableBy($buyer));
    }

    #[Test]
    public function cardWithAnyRecipientDetailIsNotSpendableByTheBuyer(): void
    {
        $buyer = $this->user(1, 'buyer@example.com');

        // A name alone is enough to mark it as a gift (the buyer shares the
        // code manually in that flow).
        self::assertTrue($this->card($buyer, name: 'Sara')->isGiftForSomeoneElse());
        self::assertFalse($this->card($buyer, name: 'Sara')->isSpendableBy($buyer));

        self::assertFalse($this->card($buyer, email: 'sara@example.com')->isSpendableBy($buyer));
        self::assertFalse($this->card($buyer, phone: '+971501234567')->isSpendableBy($buyer));
    }

    #[Test]
    public function assignedRecipientCanSpendItAndOthersCannot(): void
    {
        $buyer     = $this->user(1, 'buyer@example.com');
        $recipient = $this->user(2, 'sara@example.com');
        $stranger  = $this->user(3, 'nobody@example.com');

        $card = $this->card($buyer, email: 'sara@example.com');
        $card->assignRecipient($recipient);

        self::assertTrue($card->isSpendableBy($recipient));
        self::assertFalse($card->isSpendableBy($buyer));
        self::assertFalse($card->isSpendableBy($stranger));
    }

    #[Test]
    public function buyerCanReclaimAGiftCardByRedeemingItToThemselves(): void
    {
        // The documented escape hatch: the recipient never claimed it, so the
        // buyer redeems it themselves and it becomes spendable again.
        $buyer = $this->user(1, 'buyer@example.com');
        $card  = $this->card($buyer, name: 'Sara', email: 'sara@example.com');

        self::assertFalse($card->isSpendableBy($buyer));

        $card->assignRecipient($buyer);

        self::assertTrue($card->isSpendableBy($buyer));
    }
}
