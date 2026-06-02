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
            'label'          => 'Wedding Anniversary',
            'arabic_label'   => 'ذكرى الزفاف',
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

    /**
     * All theme metadata — used by the mobile GET /v3/gift-cards/themes endpoint.
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
