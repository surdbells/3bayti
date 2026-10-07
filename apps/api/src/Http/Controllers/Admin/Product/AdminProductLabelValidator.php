<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Product;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorLabel;
use Bayti\Api\Domain\Catalog\VendorLabelRepository;
use Bayti\Api\Http\Errors\HttpException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Validates the store label (`label_id`) an admin picks in the product form,
 * for POST /v3/admin/products and PUT /v3/admin/products/{id}.
 *
 * Semantics (same null/absent behaviour as the vendor product endpoints):
 *   - absent / null  -> no change (create: no label; update: keeps the stored one)
 *   - 0 / negative   -> 422 from VendorProductInput's Positive constraint, so
 *                       there is no "clear the label" value (vendor flow has none either)
 *   - a positive id  -> must be an ACTIVE label of the product's own store
 *                       (create: the payload's vendor_id; update: the product's
 *                       vendor), otherwise 422 on `label_id`
 *
 * One exception on update: re-sending the product's CURRENT label_id is a
 * no-op that is always accepted, even if that label has since been
 * soft-deleted. The edit form round-trips the stored label on every save, so
 * rejecting it would block unrelated edits to the product.
 */
final class AdminProductLabelValidator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param int|null $labelId        label_id from the request (null = absent)
     * @param Vendor   $vendor         the store the product belongs to
     * @param int|null $currentLabelId the product's stored label_id (update only)
     *
     * @return int|null the label id to assign, or null to leave the label untouched
     *
     * @throws HttpException 422 when the label is unknown, another store's, or inactive
     */
    public function resolve(?int $labelId, Vendor $vendor, ?int $currentLabelId = null): ?int
    {
        if ($labelId === null) {
            return null;
        }
        if ($currentLabelId !== null && $labelId === $currentLabelId) {
            return null;
        }

        /** @var VendorLabelRepository $repo */
        $repo = $this->em->getRepository(VendorLabel::class);
        $label = $repo->find($labelId);

        if ($label === null || $label->getVendor()->getId() !== $vendor->getId()) {
            throw HttpException::validation([
                'label_id' => ['The selected label does not belong to this store.'],
            ]);
        }
        if (!$label->isActive()) {
            throw HttpException::validation([
                'label_id' => ['The selected label has been deleted. Choose another label.'],
            ]);
        }

        return $labelId;
    }
}
