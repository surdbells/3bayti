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
            'label'         => 'Birthday',
            'primary_color' => '#F5A623',
            'accent_color'  => '#FFF3D6',
            'supports_photo'=> false,
            'arabic_label'  => 'عيد ميلاد',
        ],
        GiftCard::THEME_WEDDING => [
            'label'         => 'Wedding Anniversary',
            'primary_color' => '#C9A0A0',
            'accent_color'  => '#FDF6F0',
            'supports_photo'=> false,
            'arabic_label'  => 'ذكرى الزفاف',
        ],
        GiftCard::THEME_EID => [
            'label'         => 'Eid Mubarak',
            'primary_color' => '#1A5C3A',
            'accent_color'  => '#E8F5EE',
            'supports_photo'=> false,
            'arabic_label'  => 'عيد مبارك',
        ],
        GiftCard::THEME_MOTHER => [
            'label'         => "Mother's Day",
            'primary_color' => '#E8A0B4',
            'accent_color'  => '#FDF0F5',
            'supports_photo'=> false,
            'arabic_label'  => 'عيد الأم',
        ],
        GiftCard::THEME_GRADUATION => [
            'label'         => 'Graduation',
            'primary_color' => '#1B3C6E',
            'accent_color'  => '#EDF2FB',
            'supports_photo'=> false,
            'arabic_label'  => 'التخرج',
        ],
        GiftCard::THEME_LUXURY => [
            'label'         => 'Luxury Gift',
            'primary_color' => '#1A1A1A',
            'accent_color'  => '#C8B88A',
            'supports_photo'=> true,
            'arabic_label'  => 'هدية فاخرة',
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
