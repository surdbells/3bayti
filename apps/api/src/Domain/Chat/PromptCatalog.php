<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Chat;

/**
 * The curated, bilingual starter taxonomy of chat quick-start prompts (P1) —
 * the single source of truth for the seed, mirroring PermissionCatalog.
 *
 * Two audiences:
 *   - 'customer': canned questions a buyer can tap to start a message about
 *     their order (chat is order-scoped, so these are post-purchase topics).
 *   - 'vendor':   canned reply templates a seller can tap to answer quickly.
 *
 * These are seeded into chat_prompt_categories + chat_prompts by a migration
 * (INSERT ... ON CONFLICT (slug) DO NOTHING), so adding a prompt = edit this
 * catalog + reseed. The rows are admin-manageable at runtime afterwards; the
 * seed only guarantees the starter set exists on every environment.
 *
 * The prompt layer is ADDITIVE — it never replaces the free-text composer.
 */
final class PromptCatalog
{
    public const AUDIENCE_CUSTOMER = 'customer';
    public const AUDIENCE_VENDOR = 'vendor';

    /**
     * @return list<array{
     *   slug: string, audience: string, label: string, label_ar: string,
     *   icon: string, sort: int,
     *   prompts: list<array{slug: string, text: string, text_ar: string, sort: int}>
     * }>
     */
    public static function categories(): array
    {
        return [
            [
                'slug' => 'order_status', 'audience' => self::AUDIENCE_CUSTOMER,
                'label' => 'Order status', 'label_ar' => 'حالة الطلب', 'icon' => 'package', 'sort' => 10,
                'prompts' => [
                    ['slug' => 'where_is_my_order', 'text' => 'Where is my order?', 'text_ar' => 'أين طلبي؟', 'sort' => 10],
                    ['slug' => 'when_delivered', 'text' => 'When will it be delivered?', 'text_ar' => 'متى سيتم التوصيل؟', 'sort' => 20],
                    ['slug' => 'tracking_update', 'text' => 'Can you share a tracking update?', 'text_ar' => 'هل يمكنك مشاركة تحديث التتبع؟', 'sort' => 30],
                ],
            ],
            [
                'slug' => 'delivery', 'audience' => self::AUDIENCE_CUSTOMER,
                'label' => 'Delivery', 'label_ar' => 'التوصيل', 'icon' => 'truck', 'sort' => 20,
                'prompts' => [
                    ['slug' => 'change_address', 'text' => 'Can I change my delivery address?', 'text_ar' => 'هل يمكنني تغيير عنوان التوصيل؟', 'sort' => 10],
                    ['slug' => 'deliver_specific_day', 'text' => 'Can you deliver on a specific day?', 'text_ar' => 'هل يمكن التوصيل في يوم محدد؟', 'sort' => 20],
                ],
            ],
            [
                'slug' => 'sizing_exchange', 'audience' => self::AUDIENCE_CUSTOMER,
                'label' => 'Sizing & exchange', 'label_ar' => 'المقاس والاستبدال', 'icon' => 'ruler', 'sort' => 30,
                'prompts' => [
                    ['slug' => 'doesnt_fit_exchange', 'text' => "The size doesn't fit — can I exchange it?", 'text_ar' => 'المقاس غير مناسب — هل يمكنني استبداله؟', 'sort' => 10],
                    ['slug' => 'change_size', 'text' => 'Can I change the size?', 'text_ar' => 'هل يمكنني تغيير المقاس؟', 'sort' => 20],
                ],
            ],
            [
                'slug' => 'customization', 'audience' => self::AUDIENCE_CUSTOMER,
                'label' => 'Customization', 'label_ar' => 'التخصيص', 'icon' => 'sparkles', 'sort' => 40,
                'prompts' => [
                    ['slug' => 'can_you_customize', 'text' => 'Can you customize or alter this for me?', 'text_ar' => 'هل يمكنك تخصيص أو تعديل هذا لي؟', 'sort' => 10],
                ],
            ],
            [
                'slug' => 'care', 'audience' => self::AUDIENCE_CUSTOMER,
                'label' => 'Product care', 'label_ar' => 'العناية بالمنتج', 'icon' => 'heart', 'sort' => 50,
                'prompts' => [
                    ['slug' => 'how_to_care', 'text' => 'How do I care for this item?', 'text_ar' => 'كيف أعتني بهذا المنتج؟', 'sort' => 10],
                    ['slug' => 'what_material', 'text' => 'What material is this made of?', 'text_ar' => 'من أي مادة صُنع هذا؟', 'sort' => 20],
                ],
            ],
            [
                'slug' => 'vendor_quick_replies', 'audience' => self::AUDIENCE_VENDOR,
                'label' => 'Quick replies', 'label_ar' => 'ردود سريعة', 'icon' => 'chat', 'sort' => 10,
                'prompts' => [
                    ['slug' => 'thanks_will_check', 'text' => "Thank you for your message! I'll check and get back to you shortly.", 'text_ar' => 'شكرًا لرسالتك! سأتحقق وأعود إليك قريبًا.', 'sort' => 10],
                    ['slug' => 'order_shipped', 'text' => 'Your order has shipped and should arrive within 2–4 days.', 'text_ar' => 'تم شحن طلبك ومن المتوقع وصوله خلال 2–4 أيام.', 'sort' => 20],
                    ['slug' => 'can_exchange', 'text' => 'Yes, we can arrange an exchange — please share the details.', 'text_ar' => 'نعم، يمكننا ترتيب الاستبدال — يرجى مشاركة التفاصيل.', 'sort' => 30],
                    ['slug' => 'can_customize', 'text' => "Yes, we can customize this — I'll prepare a quote for you.", 'text_ar' => 'نعم، يمكننا تخصيص هذا — سأعدّ لك عرض سعر.', 'sort' => 40],
                    ['slug' => 'follow_care_label', 'text' => 'Please follow the care label; happy to help further.', 'text_ar' => 'يرجى اتباع تعليمات العناية على الملصق؛ يسعدني مساعدتك أكثر.', 'sort' => 50],
                ],
            ],
        ];
    }
}
