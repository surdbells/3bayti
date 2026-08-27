<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Chat;

use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderAddress;
use Bayti\Api\Domain\Order\OrderItem;

/**
 * Builds the first, system-generated message of an order conversation: a
 * complete snapshot of the purchased item, product, variant, custom
 * measurements, pricing, order totals and the shipping details, in English
 * and Arabic. This is informational and authored by the platform, so it is
 * never run through PII moderation (the address legitimately belongs to the
 * order).
 */
class OrderDetailsMessageBuilder
{
    /**
     * @param ?string $introEn Override the opening line (English). Defaults to
     *        the vendor-facing "New order received…". The order-confirmation
     *        emails pass a customer/vendor-appropriate opener while reusing the
     *        identical detail body + policy footer.
     * @param ?string $introAr Override the opening line (Arabic).
     * @return array{0: string, 1: string} [english, arabic]
     */
    public function build(
        Order $order,
        OrderItem $item,
        ?string $introEn = null,
        ?string $introAr = null,
    ): array {
        $en = [];
        $ar = [];

        $en[] = $introEn ?? 'New order received. Here are the full details:';
        $ar[] = $introAr ?? 'تم استلام طلب جديد. إليك التفاصيل الكاملة:';
        $en[] = '';
        $ar[] = '';

        // ── Order ───────────────────────────────────────────────
        $en[] = 'ORDER';
        $ar[] = 'الطلب';
        $this->line($en, $ar, 'Reference', 'رقم الطلب', $order->getOrderReference());
        $this->line($en, $ar, 'Date', 'التاريخ', $order->getCreatedAt()->format('d M Y, H:i'));
        $this->line($en, $ar, 'Status', 'الحالة', $item->getItemStatus());

        // ── Item ────────────────────────────────────────────────
        $en[] = '';
        $ar[] = '';
        $en[] = 'ITEM';
        $ar[] = 'المنتج';
        $this->line($en, $ar, 'Product', 'المنتج', $item->getProductNameSnapshot());
        $this->line($en, $ar, 'Quantity', 'الكمية', (string) $item->getQuantity());
        if ($item->getSize() !== null && $item->getSize() !== '') {
            $this->line($en, $ar, 'Size', 'المقاس', $item->getSize());
        }
        if ($item->getColor() !== null && $item->getColor() !== '') {
            $this->line($en, $ar, 'Color', 'اللون', $item->getColor());
        }
        $this->line($en, $ar, 'Custom order', 'طلب مخصص', $item->isCustom() ? 'Yes' : 'No');

        // Measurements (may be JSON key/value or free text).
        $measurement = $this->formatMeasurement($item->getMeasurement());
        if ($measurement !== []) {
            $en[] = '';
            $ar[] = '';
            $en[] = 'MEASUREMENTS';
            $ar[] = 'القياسات';
            foreach ($measurement as $label => $value) {
                $this->line($en, $ar, (string) $label, (string) $label, (string) $value);
            }
        }
        $extra = $this->formatMeasurement($item->getExtraMeasurement());
        if ($extra !== []) {
            foreach ($extra as $label => $value) {
                $this->line($en, $ar, (string) $label, (string) $label, (string) $value);
            }
        }
        if ($item->getNote() !== null && trim($item->getNote()) !== '') {
            $this->line($en, $ar, 'Note', 'ملاحظة', trim($item->getNote()));
        }

        // ── Pricing ─────────────────────────────────────────────
        $cur = $order->getCurrency();
        $en[] = '';
        $ar[] = '';
        $en[] = 'PRICING';
        $ar[] = 'التسعير';
        $this->line($en, $ar, 'Unit price', 'سعر الوحدة', "{$cur} {$item->getUnitPrice()}");
        $this->line($en, $ar, 'Item subtotal', 'مجموع المنتج', "{$cur} {$item->getSubtotal()}");
        $this->line($en, $ar, 'Order subtotal', 'المجموع الفرعي', "{$cur} {$order->getSubtotal()}");
        $this->line($en, $ar, 'Delivery', 'التوصيل', "{$cur} {$order->getDeliveryFee()}");
        if ((float) $order->getDiscount() > 0) {
            $this->line($en, $ar, 'Discount', 'الخصم', "-{$cur} {$order->getDiscount()}");
        }
        if ((float) $order->getGiftCardAmount() > 0) {
            $this->line($en, $ar, 'Gift card', 'بطاقة هدية', "-{$cur} {$order->getGiftCardAmount()}");
        }
        $this->line($en, $ar, 'Order total', 'الإجمالي', "{$cur} {$order->getTotal()}");

        // ── Shipping ────────────────────────────────────────────
        $shipping = $order->getShippingAddress();
        if ($shipping instanceof OrderAddress) {
            $en[] = '';
            $ar[] = '';
            $en[] = 'SHIPPING';
            $ar[] = 'الشحن';
            $recipient = trim($shipping->getFirstName() . ' ' . ($shipping->getLastName() ?? ''));
            $this->line($en, $ar, 'Recipient', 'المستلم', $recipient);
            $this->line($en, $ar, 'Phone', 'الهاتف', $shipping->getPhone());
            $this->line($en, $ar, 'Address', 'العنوان', $shipping->getStreet());
            $cityLine = $shipping->getCity();
            if ($shipping->getStateProvince() !== null && $shipping->getStateProvince() !== '') {
                $cityLine .= ', ' . $shipping->getStateProvince();
            }
            $this->line($en, $ar, 'City', 'المدينة', $cityLine);
            $this->line($en, $ar, 'Country', 'الدولة', $shipping->getCountryCode());
            if ($shipping->getPostalCode() !== null && $shipping->getPostalCode() !== '') {
                $this->line($en, $ar, 'Postal code', 'الرمز البريدي', $shipping->getPostalCode());
            }
        }

        // ── Policy reminder ─────────────────────────────────────
        $en[] = '';
        $ar[] = '';
        $en[] = 'Please keep all communication here. Sharing personal contact details (phone, email, social, address) is not allowed.';
        $ar[] = 'يرجى إبقاء جميع المراسلات هنا. لا يُسمح بمشاركة بيانات الاتصال الشخصية (هاتف، بريد إلكتروني، تواصل اجتماعي، عنوان).';

        return [implode("\n", $en), implode("\n", $ar)];
    }

    private function line(array &$en, array &$ar, string $enLabel, string $arLabel, string $value): void
    {
        $en[] = "{$enLabel}: {$value}";
        $ar[] = "{$arLabel}: {$value}";
    }

    /**
     * Normalise a measurement field into label => value pairs. Accepts a
     * JSON object, or falls back to a single 'Details' entry for free text.
     *
     * @return array<string, string>
     */
    private function formatMeasurement(?string $raw): array
    {
        if ($raw === null) {
            return [];
        }
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $out = [];
            foreach ($decoded as $k => $v) {
                if ($v === null || $v === '' || is_array($v)) {
                    continue;
                }
                $label = ucwords(str_replace(['_', '-'], ' ', (string) $k));
                $out[$label] = (string) $v;
            }
            return $out;
        }
        return ['Details' => $raw];
    }
}
