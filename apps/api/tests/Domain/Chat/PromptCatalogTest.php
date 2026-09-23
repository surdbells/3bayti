<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Chat;

use Bayti\Api\Domain\Chat\ChatPrompt;
use Bayti\Api\Domain\Chat\ChatPromptCategory;
use Bayti\Api\Domain\Chat\Conversation;
use Bayti\Api\Domain\Chat\Message;
use Bayti\Api\Domain\Chat\PromptCatalog;
use Bayti\Api\Domain\User\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PromptCatalog::class)]
#[CoversClass(ChatPrompt::class)]
#[CoversClass(ChatPromptCategory::class)]
final class PromptCatalogTest extends TestCase
{
    #[Test]
    public function catalogIsWellFormedWithUniqueSlugs(): void
    {
        $cats = PromptCatalog::categories();
        self::assertNotEmpty($cats);

        $catSlugs = [];
        $promptSlugs = [];
        $sawCustomer = false;
        $sawVendor = false;

        foreach ($cats as $cat) {
            self::assertContains($cat['audience'], [PromptCatalog::AUDIENCE_CUSTOMER, PromptCatalog::AUDIENCE_VENDOR]);
            $sawCustomer = $sawCustomer || $cat['audience'] === PromptCatalog::AUDIENCE_CUSTOMER;
            $sawVendor = $sawVendor || $cat['audience'] === PromptCatalog::AUDIENCE_VENDOR;
            self::assertNotSame('', trim($cat['label']));
            self::assertNotSame('', trim($cat['label_ar']));
            $catSlugs[] = $cat['slug'];
            self::assertNotEmpty($cat['prompts'], "category {$cat['slug']} has no prompts");
            foreach ($cat['prompts'] as $p) {
                self::assertNotSame('', trim($p['text']), "prompt {$p['slug']} missing en text");
                self::assertNotSame('', trim($p['text_ar']), "prompt {$p['slug']} missing ar text");
                $promptSlugs[] = $p['slug'];
            }
        }

        self::assertTrue($sawCustomer, 'catalog must define customer prompts');
        self::assertTrue($sawVendor, 'catalog must define vendor templates');
        self::assertSame(count($catSlugs), count(array_unique($catSlugs)), 'category slugs must be unique');
        self::assertSame(count($promptSlugs), count(array_unique($promptSlugs)), 'prompt slugs must be globally unique');
    }

    #[Test]
    public function fromCustomerPromptSnapshotsBilingualTextAndTags(): void
    {
        $category = new ChatPromptCategory('order_status', PromptCatalog::AUDIENCE_CUSTOMER, 'Order status', 'حالة الطلب', 'package', 10);
        $prompt = new ChatPrompt($category, 'where_is_my_order', 'Where is my order?', 'أين طلبي؟', 10);
        $this->setId($prompt, 42);

        $conversation = (new \ReflectionClass(Conversation::class))->newInstanceWithoutConstructor();
        $customer = new User(
            email: 'c@example.com',
            phone: '+971500000000',
            passwordHash: password_hash('p', PASSWORD_BCRYPT),
            countryCode: 'AE',
        );

        $message = Message::fromCustomerPrompt($conversation, $customer, $prompt);

        self::assertSame(Message::TYPE_PROMPT, $message->getType());
        self::assertSame('Where is my order?', $message->getContent());
        self::assertSame('أين طلبي؟', $message->getContentAr());
        self::assertSame(42, $message->getPromptId());
        self::assertSame(Conversation::PARTY_CUSTOMER, $message->getSenderType());
        self::assertTrue($message->isDelivered());
    }

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }
}
