<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Chat;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Chat\Conversation;
use Bayti\Api\Domain\Chat\ConversationRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\HttpException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Resolves a conversation by uuid and authorizes the viewer. A 404 (never
 * 403) is returned when the conversation is missing or belongs to someone
 * else, so the endpoint never reveals that a given conversation exists.
 */
trait ResolvesChatConversation
{
    protected function customerConversation(EntityManagerInterface $em, string $uuid, User $user): Conversation
    {
        /** @var ConversationRepository $repo */
        $repo = $em->getRepository(Conversation::class);
        $conversation = $uuid !== '' ? $repo->findByUuid($uuid) : null;

        if ($conversation === null || $conversation->getCustomer()->getId() !== $user->getId()) {
            throw HttpException::notFound('Conversation not found.');
        }
        return $conversation;
    }

    protected function vendorConversation(EntityManagerInterface $em, string $uuid, User $user): Conversation
    {
        /** @var ConversationRepository $repo */
        $repo = $em->getRepository(Conversation::class);
        $conversation = $uuid !== '' ? $repo->findByUuid($uuid) : null;

        /** @var VendorRepository $vendors */
        $vendors = $em->getRepository(Vendor::class);
        $ownedVendorIds = $vendors->findIdsByOwnerUser($user);

        if (
            $conversation === null
            || !in_array($conversation->getVendor()->getId(), $ownedVendorIds, true)
        ) {
            throw HttpException::notFound('Conversation not found.');
        }
        return $conversation;
    }
}
