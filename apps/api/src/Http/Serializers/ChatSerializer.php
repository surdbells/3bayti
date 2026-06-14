<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Chat\Conversation;
use Bayti\Api\Domain\Chat\Message;
use Bayti\Api\Domain\User\User;

/**
 * Shapes chat conversations and messages for the API. Conversation output
 * is viewer-aware: the unread count and the "counterparty" block depend on
 * whether the customer or the vendor is looking.
 */
final class ChatSerializer
{
    /**
     * @param Conversation::PARTY_* $viewerParty
     * @return array<string, mixed>
     */
    public function conversationShape(Conversation $conversation, string $viewerParty): array
    {
        $item = $conversation->getOrderItem();

        $counterparty = $viewerParty === Conversation::PARTY_CUSTOMER
            ? $this->vendorShape($conversation->getVendor())
            : $this->customerShape($conversation->getCustomer());

        return [
            'uuid'                 => $conversation->getUuid(),
            'status'               => $conversation->getStatus(),
            'order_reference'      => $conversation->getOrder()->getOrderReference(),
            'unread_count'         => $conversation->unreadFor($viewerParty),
            'last_message_at'      => $conversation->getLastMessageAt()?->format(\DATE_ATOM),
            'last_message_preview' => $conversation->getLastMessagePreview(),
            'counterparty'         => $counterparty,
            'item'                 => [
                'name'  => $item->getProductNameSnapshot(),
                'image' => $item->getProductImageSnapshot(),
                'size'  => $item->getSize(),
                'color' => $item->getColor(),
            ],
            'created_at'           => $conversation->getCreatedAt()->format(\DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function messageShape(Message $message): array
    {
        return [
            'id'          => $message->getId(),
            'uuid'        => $message->getUuid(),
            'sender_type' => $message->getSenderType(),
            'type'        => $message->getType(),
            'content'     => $message->getContent(),
            'content_ar'  => $message->getContentAr(),
            'is_flagged'  => $message->isFlagged(),
            'status'      => $message->getStatus(),
            'created_at'  => $message->getCreatedAt()->format(\DATE_ATOM),
        ];
    }

    /**
     * Admin oversight view of a flagged message: the attempted content plus
     * full conversation context (order, customer incl. email, vendor). Used
     * only behind the admin guard.
     *
     * @return array<string, mixed>
     */
    public function flaggedMessageShape(Message $message): array
    {
        $conversation = $message->getConversation();
        $customer = $conversation->getCustomer();
        $vendor = $conversation->getVendor();
        $item = $conversation->getOrderItem();
        $customerName = trim(($customer->getFirstName() ?? '') . ' ' . ($customer->getLastName() ?? ''));

        return [
            'uuid'         => $message->getUuid(),
            'sender_type'  => $message->getSenderType(),
            'status'       => $message->getStatus(),
            'flag_type'    => $message->getFlagType(),
            'content'      => $message->getContent(),
            'created_at'   => $message->getCreatedAt()->format(\DATE_ATOM),
            'conversation' => [
                'uuid'            => $conversation->getUuid(),
                'order_reference' => $conversation->getOrder()->getOrderReference(),
                'item_name'       => $item->getProductNameSnapshot(),
                'customer'        => [
                    'name'  => $customerName !== '' ? $customerName : 'Customer',
                    'email' => $customer->getEmail(),
                ],
                'vendor'          => [
                    'name' => $vendor->getName(),
                    'slug' => $vendor->getSlug(),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function vendorShape(Vendor $vendor): array
    {
        return [
            'type'     => 'vendor',
            'name'     => $vendor->getName(),
            'slug'     => $vendor->getSlug(),
            'logo_url' => $vendor->getLogoUrl(),
        ];
    }

    /**
     * Admin investigation view of a whole conversation — both parties named,
     * no viewer scoping. Pair with messageShape over findAllForConversation.
     *
     * @return array<string, mixed>
     */
    public function adminConversationShape(Conversation $conversation): array
    {
        $customer = $conversation->getCustomer();
        $vendor = $conversation->getVendor();
        $item = $conversation->getOrderItem();
        $customerName = trim(($customer->getFirstName() ?? '') . ' ' . ($customer->getLastName() ?? ''));

        return [
            'uuid'            => $conversation->getUuid(),
            'status'          => $conversation->getStatus(),
            'order_reference' => $conversation->getOrder()->getOrderReference(),
            'item_name'       => $item->getProductNameSnapshot(),
            'customer'        => [
                'name'  => $customerName !== '' ? $customerName : 'Customer',
                'email' => $customer->getEmail(),
            ],
            'vendor'          => [
                'name' => $vendor->getName(),
                'slug' => $vendor->getSlug(),
            ],
            'created_at'      => $conversation->getCreatedAt()->format(\DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function customerShape(User $user): array
    {
        $name = trim(($user->getFirstName() ?? '') . ' ' . ($user->getLastName() ?? ''));
        return [
            'type' => 'customer',
            'name' => $name !== '' ? $name : 'Customer',
        ];
    }
}
