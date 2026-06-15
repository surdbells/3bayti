<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Support\Ticket;
use Bayti\Api\Domain\Support\TicketMessage;

/**
 * Shapes support tickets + their messages for API responses. Shared by
 * the customer (/v3/me/tickets) endpoints; mirrors the field names the
 * admin ticket controllers emit so both surfaces stay consistent.
 */
final class TicketSerializer
{
    /** @return array<string,mixed> */
    public function messageShape(TicketMessage $m): array
    {
        return [
            'id'             => $m->getId(),
            'body'           => $m->getBody(),
            'author_name'    => $m->getAuthorName(),
            'is_admin_reply' => $m->isAdminReply(),
            'created_at'     => $m->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** Full ticket incl. its message thread (detail view). @return array<string,mixed> */
    public function detailShape(Ticket $t): array
    {
        $messages = [];
        foreach ($t->getMessages() as $m) {
            $messages[] = $this->messageShape($m);
        }

        return [
            'id'         => $t->getId(),
            'subject'    => $t->getSubject(),
            'body'       => $t->getBody(),
            'status'     => $t->getStatus(),
            'priority'   => $t->getPriority(),
            'vendor_id'  => $t->getVendorId(),
            'messages'   => $messages,
            'created_at' => $t->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $t->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** Lightweight row for list views (no body/messages). @return array<string,mixed> */
    public function listItemShape(Ticket $t): array
    {
        return [
            'id'            => $t->getId(),
            'subject'       => $t->getSubject(),
            'status'        => $t->getStatus(),
            'priority'      => $t->getPriority(),
            'vendor_id'     => $t->getVendorId(),
            'message_count' => $t->getMessages()->count(),
            'created_at'    => $t->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at'    => $t->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
