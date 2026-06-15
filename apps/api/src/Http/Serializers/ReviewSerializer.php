<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Catalog\ProductReview;

/**
 * Shapes product reviews for API responses.
 *
 * Two audiences:
 *  - publicShape: what other shoppers see on a product/store page
 *    (reviewer display name, stars, text, vendor reply, helpfulness).
 *    Never exposes status or the reviewer's email.
 *  - mineShape: the author's own view (includes status + product id so
 *    the app can link back and show "pending moderation").
 */
final class ReviewSerializer
{
    /** @return array<string,mixed> */
    public function publicShape(ProductReview $r): array
    {
        return [
            'id'            => $r->getId(),
            'product_id'    => $r->getProduct()?->getId(),
            'product_name'  => $r->getProductNameSnapshot() ?? $r->getProduct()?->getName(),
            'reviewer'      => $r->getReviewerName(),
            'star'          => $r->getStar(),
            'title'         => $r->getTitle(),
            'comment'       => $r->getComment(),
            'vendor_reply'  => $r->getVendorReply(),
            'is_verified'   => $r->isVerified(),
            'helpful_count' => $r->getHelpfulCount(),
            'created_at'    => $r->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string,mixed> */
    public function mineShape(ProductReview $r): array
    {
        return [
            'id'            => $r->getId(),
            'product_id'    => $r->getProduct()?->getId(),
            'product_name'  => $r->getProductNameSnapshot() ?? $r->getProduct()?->getName(),
            'vendor_id'     => $r->getVendor()?->getId(),
            'star'          => $r->getStar(),
            'title'         => $r->getTitle(),
            'comment'       => $r->getComment(),
            'status'        => $r->getStatus(),
            'vendor_reply'  => $r->getVendorReply(),
            'helpful_count' => $r->getHelpfulCount(),
            'created_at'    => $r->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
