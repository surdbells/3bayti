<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\GiftCard;

use Bayti\Api\Domain\GiftCard\GiftCard;
use Bayti\Api\Domain\GiftCard\GiftCardTransaction;

/**
 * Shared response shaper for gift card endpoints.
 * Kept in the Http layer (not Domain) because it knows about
 * the public response shape contract, not business logic.
 *
 * Theme metadata: each theme carries design hints the mobile app uses
 * to select the correct SVG/Lottie card template and colour palette.
 */
final class GiftCardSerializer
{
    private const THEME_META = [
        GiftCard::THEME_BIRTHDAY => [
            'label'          => 'Birthday',
            'arabic_label'   => 'عيد ميلاد',
            // Deep amber-black card with gold sunburst rays from top-right corner,
            // concentric gold circles, and a diamond-chain border strip.
            'primary_color'  => '#3A1A00',   // deep amber-black card base
            'accent_color'   => '#E8C040',   // gold sunburst / title
            'text_color'     => '#F5E060',   // amount + display text
            'border_color'   => '#E8C040',   // outer card border
            'pattern'        => 'sunburst',  // mobile picks the sunburst SVG template
            'supports_photo' => false,
        ],
        GiftCard::THEME_WEDDING => [
            'label'          => 'Anniversary',
            'arabic_label'   => 'ذكرى سنوية',
            // Deep burgundy-black card with layered sinusoidal vine waves,
            // interlocking gold rings (wedding band motif), and a dot-column divider.
            'primary_color'  => '#260014',   // deep burgundy-black card base
            'accent_color'   => '#D4AF37',   // gold rings / vines / title
            'text_color'     => '#F5E060',   // amount + display text
            'border_color'   => '#D4AF37',
            'pattern'        => 'rings',     // mobile picks the interlocking-rings template
            'supports_photo' => false,
        ],
        GiftCard::THEME_EID => [
            'label'          => 'Eid Mubarak',
            'arabic_label'   => 'عيد مبارك',
            // Deep forest-black card with a single clean 12-pointed Islamic
            // geometric star, crescent + star top-right, and arabesque wave borders.
            'primary_color'  => '#002A12',   // deep forest-black card base
            'accent_color'   => '#D4AF37',   // gold star / arabesque / title
            'text_color'     => '#F5E060',   // amount + display text
            'border_color'   => '#2A8A50',   // outer border green accent
            'pattern'        => 'star',      // mobile picks the 12-point-star template
            'supports_photo' => false,
        ],
        GiftCard::THEME_MOTHER => [
            'label'          => "Mother's Day",
            'arabic_label'   => 'عيد الأم',
            // Deep plum-black card with a large 12-petal symmetrical bloom
            // centre-right, a smaller bloom echo top-right, and a fleur-de-lis
            // diamond dot border strip.
            'primary_color'  => '#22001A',   // deep plum-black card base
            'accent_color'   => '#D4AF37',   // gold bloom petals / title
            'text_color'     => '#F5E060',   // amount + display text
            'border_color'   => '#D4AF37',
            'pattern'        => 'bloom',     // mobile picks the petal-bloom template
            'supports_photo' => false,
        ],
        GiftCard::THEME_GRADUATION => [
            'label'          => 'Graduation',
            'arabic_label'   => 'التخرج',
            // Deep navy-black card with a layered heraldic seal (tick-mark outer ring,
            // 16-point star, inner rings, mortar board glyph), navy stripe top/bottom,
            // and subtle vertical column lines on the left margin.
            'primary_color'  => '#000C26',   // deep navy-black card base
            'accent_color'   => '#D4AF37',   // gold seal / title
            'text_color'     => '#F5E060',   // amount + display text
            'border_color'   => '#1A3070',   // outer border navy accent
            'pattern'        => 'seal',      // mobile picks the heraldic-seal template
            'supports_photo' => false,
        ],
        GiftCard::THEME_LUXURY => [
            'label'          => 'Luxury Gift',
            'arabic_label'   => 'هدية فاخرة',
            // Pure black-gold card with fine diagonal cross-hatch, bold gold side
            // pillars, a grand outer medallion ring with tick marks, a 16-point
            // decorative star behind an ornate framed recipient photo circle, and
            // a triple outer gold border.
            'primary_color'  => '#1A1200',   // pure black-gold card base
            'accent_color'   => '#E8C040',   // bright gold pillars / medallion / title
            'text_color'     => '#F0D060',   // amount + display text
            'border_color'   => '#E8C040',   // bold outer gold border
            'pattern'        => 'medallion', // mobile picks the grand-medallion template
            'supports_photo' => true,        // ONLY theme that accepts recipient_photo_url
        ],
    ];

    /** @return array<string,mixed> */
    public static function shape(GiftCard $card, bool $includeTransactions = false): array
    {
        $themeMeta = (array) self::THEME_META[$card->getTheme()];

        $data = [
            'id'                    => $card->getId(),
            'code'                  => $card->formattedCode(),
            'theme'                 => $card->getTheme(),
            'theme_meta'            => $themeMeta,
            'denomination'          => $card->getDenomination(),
            'balance'               => $card->getBalance(),
            'currency'              => $card->getCurrency(),
            'status'                => $card->getStatus(),
            'is_spendable'          => $card->isSpendable(),
            'recipient_name'        => $card->getRecipientName(),
            'recipient_message'     => $card->getRecipientMessage(),
            'recipient_photo_url'   => $card->getRecipientPhotoUrl(),
            'recipient_email'       => self::maskEmail($card->getRecipientEmail()),
            'recipient_phone'       => self::maskPhone($card->getRecipientPhone()),
            'email_delivered_at'    => $card->getEmailDeliveredAt()?->format(\DateTimeInterface::ATOM),
            'sms_delivered_at'      => $card->getSmsDeliveredAt()?->format(\DateTimeInterface::ATOM),
            'scheduled_delivery_at' => $card->getScheduledDeliveryAt()?->format(\DateTimeInterface::ATOM),
            'activated_at'          => $card->getActivatedAt()?->format(\DateTimeInterface::ATOM),
            'expires_at'            => $card->getExpiresAt()?->format(\DateTimeInterface::ATOM),
            'created_at'            => $card->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'is_buyer'              => true, // caller adjusts if needed
        ];

        if ($includeTransactions) {
            $data['transactions'] = array_map(
                static fn(GiftCardTransaction $tx) => [
                    'id'              => $tx->getId(),
                    'type'            => $tx->getType(),
                    'amount'          => $tx->getAmount(),
                    'balance_after'   => $tx->getBalanceAfter(),
                    'order_reference' => $tx->getOrderReference(),
                    'created_at'      => $tx->getCreatedAt()->format(\DateTimeInterface::ATOM),
                ],
                $card->getTransactions()->toArray()
            );
        }

        return $data;
    }

    // ── Admin shapes (M5 admin gift-card surface) ─────────────────────
    //
    // The admin surface is staff-only behind the permission stack, so
    // unlike the customer shape it exposes UNMASKED purchaser + recipient
    // contact details and the raw code (admins need to look cards up and
    // support customers). The list shape is intentionally lean (no
    // ledger, no theme_meta) for cheap pages; the detail shape adds the
    // full ordered ledger + delivery block.

    /**
     * Lean admin LIST row. No ledger, no theme metadata, keeps the
     * paginated payload small. The buyer/recipient are flattened to the
     * fields an admin table needs to render + search against.
     *
     * @return array<string,mixed>
     */
    public static function adminListShape(GiftCard $card): array
    {
        $buyer     = $card->getBuyerUser();
        $recipient = $card->getRecipientUser();

        return [
            'id'                 => $card->getId(),
            'code'               => $card->formattedCode(),
            'theme'              => $card->getTheme(),
            'denomination'       => $card->getDenomination(),
            'balance'            => $card->getBalance(),
            'currency'           => $card->getCurrency(),
            'status'             => $card->getStatus(),
            'is_spendable'       => $card->isSpendable(),
            'purchaser' => [
                'id'    => $buyer->getId(),
                'email' => $buyer->getEmail(),
                'name'  => self::fullName($buyer),
            ],
            'recipient_name'     => $card->getRecipientName(),
            'recipient_email'    => $card->getRecipientEmail(),
            'recipient_user' => $recipient !== null ? [
                'id'    => $recipient->getId(),
                'email' => $recipient->getEmail(),
                'name'  => self::fullName($recipient),
            ] : null,
            'is_delivered'       => $card->getEmailDeliveredAt() !== null || $card->getSmsDeliveredAt() !== null,
            'scheduled_delivery_at' => $card->getScheduledDeliveryAt()?->format(\DateTimeInterface::ATOM),
            'activated_at'       => $card->getActivatedAt()?->format(\DateTimeInterface::ATOM),
            'expires_at'         => $card->getExpiresAt()?->format(\DateTimeInterface::ATOM),
            'created_at'         => $card->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param iterable<GiftCard> $cards
     * @return list<array<string,mixed>>
     */
    public static function adminListShapeMany(iterable $cards): array
    {
        $out = [];
        foreach ($cards as $card) {
            $out[] = self::adminListShape($card);
        }
        return $out;
    }

    /**
     * Full admin DETAIL shape: list fields + theme_meta + the complete
     * ordered transaction ledger + a delivery block.
     *
     * @return array<string,mixed>
     */
    public static function adminDetailShape(GiftCard $card): array
    {
        $data = self::adminListShape($card);

        $data['theme_meta']        = (array) self::THEME_META[$card->getTheme()];
        $data['recipient_message'] = $card->getRecipientMessage();
        $data['recipient_photo_url'] = $card->getRecipientPhotoUrl();
        $data['recipient_phone']   = $card->getRecipientPhone();
        $data['purchase_order_reference'] = $card->getPurchaseOrderReference();
        $data['updated_at']        = $card->getUpdatedAt()->format(\DateTimeInterface::ATOM);

        // Delivery status block, recipient + channel + timestamps. The
        // email/phone shown here are the EFFECTIVE contacts, the buyer-provided
        // delivery target, or the claimed recipient account's email/phone as a
        // fallback, so the admin sees where the card can actually be sent (and
        // *_from_account flags whether that came from the claimed account
        // rather than the original purchase).
        $data['delivery'] = [
            'recipient_email'    => $card->effectiveRecipientEmail(),
            'recipient_phone'    => $card->effectiveRecipientPhone(),
            'email_from_account' => $card->recipientEmailIsFromAccount(),
            'phone_from_account' => $card->recipientPhoneIsFromAccount(),
            'is_scheduled'       => $card->isScheduled(),
            'scheduled_at'       => $card->getScheduledDeliveryAt()?->format(\DateTimeInterface::ATOM),
            'email_delivered_at' => $card->getEmailDeliveredAt()?->format(\DateTimeInterface::ATOM),
            'sms_delivered_at'   => $card->getSmsDeliveredAt()?->format(\DateTimeInterface::ATOM),
            'channel'            => self::deliveryChannel($card),
            'delivered'          => $card->getEmailDeliveredAt() !== null || $card->getSmsDeliveredAt() !== null,
            // Whether the admin "Send to recipient" action is available: the
            // card is spendable AND has at least one recipient contact to send
            // to. Drives the Send button's enabled state on the detail page.
            'can_send'           => $card->isSpendable()
                && ($card->effectiveRecipientEmail() !== null || $card->effectiveRecipientPhone() !== null),
        ];

        // Full ordered ledger (ASC by id, append-only, so chronological).
        $data['ledger'] = array_map(
            static fn(GiftCardTransaction $tx) => self::ledgerRow($tx),
            $card->getTransactions()->toArray(),
        );

        return $data;
    }

    /**
     * Shape a single ledger row, shared by the detail ledger + the
     * adjust/void action responses (which return the new row).
     *
     * @return array<string,mixed>
     */
    public static function ledgerRow(GiftCardTransaction $tx): array
    {
        return [
            'id'              => $tx->getId(),
            'type'            => $tx->getType(),
            'amount'          => $tx->getAmount(),
            'balance_after'   => $tx->getBalanceAfter(),
            'order_reference' => $tx->getOrderReference(),
            'order_id'        => $tx->getOrderId(),
            'reason'          => $tx->getReason(),
            'actor_user_id'   => $tx->getActorUserId(),
            'created_at'      => $tx->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** Which channel(s) the card has been delivered through. */
    private static function deliveryChannel(GiftCard $card): ?string
    {
        $channels = [];
        if ($card->getEmailDeliveredAt() !== null) {
            $channels[] = 'email';
        }
        if ($card->getSmsDeliveredAt() !== null) {
            $channels[] = 'sms';
        }
        return $channels === [] ? null : implode('+', $channels);
    }

    /** Best-effort display name from a user's first/last name. */
    private static function fullName(\Bayti\Api\Domain\User\User $user): ?string
    {
        $name = trim(((string) $user->getFirstName()) . ' ' . ((string) $user->getLastName()));
        return $name === '' ? null : $name;
    }

    /**
     * Lightly mask a recipient email for the response shape, e.g.
     * 'sara@domain.com' -> 's***@domain.com'. Null stays null.
     */
    private static function maskEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }
        $at = strpos($email, '@');
        if ($at === false || $at < 1) {
            return '***';
        }
        $local  = substr($email, 0, $at);
        $domain = substr($email, $at);
        return $local[0] . '***' . $domain;
    }

    /**
     * Lightly mask a recipient phone, keeping only the last 2 digits,
     * e.g. '+971501234567' -> '+*********67'. Null stays null.
     */
    private static function maskPhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $len = strlen($phone);
        if ($len <= 2) {
            return str_repeat('*', $len);
        }
        $plus = str_starts_with($phone, '+') ? '+' : '';
        $last2 = substr($phone, -2);
        $maskedCount = $len - strlen($plus) - 2;
        return $plus . str_repeat('*', max(0, $maskedCount)) . $last2;
    }

    /**
     * All theme metadata, used by the mobile GET /v3/gift-cards/themes endpoint.
     * @return array<string,array<string,mixed>>
     */
    public static function allThemes(): array
    {
        $out = [];
        foreach (GiftCard::THEMES as $theme) {
            /** @phpstan-ignore-next-line */
            $meta = isset(self::THEME_META[$theme]) ? (array) self::THEME_META[$theme] : [];
            $meta['presets']         = GiftCard::PRESET_DENOMINATIONS;
            $meta['min_denomination']= GiftCard::MIN_DENOMINATION;
            $meta['max_denomination']= GiftCard::MAX_DENOMINATION;
            $out[$theme] = $meta;
        }
        return $out;
    }
}
