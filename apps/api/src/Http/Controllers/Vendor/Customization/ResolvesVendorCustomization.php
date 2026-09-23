<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor\Customization;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Customization\CustomizationRequest;
use Bayti\Api\Domain\Customization\CustomizationRequestRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared auth + load + ownership resolution for the vendor-facing
 * customization controllers (get/quote/decline/complete). Enforces the
 * 404-not-403 cross-actor rule: a request whose product isn't owned by
 * one of the caller's vendors is indistinguishable from a missing one.
 */
trait ResolvesVendorCustomization
{
    /**
     * @param array<string, string> $args
     */
    private function resolveForVendor(
        ServerRequestInterface $request,
        array $args,
        EntityManagerInterface $em,
    ): CustomizationRequest {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            throw HttpException::notFound('Customization request not found.');
        }

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $em->getRepository(Vendor::class);
        $userVendorIds = $vendorRepo->findIdsByOwnerUser($user);

        /** @var CustomizationRequestRepository $repo */
        $repo = $em->getRepository(CustomizationRequest::class);
        $customization = $repo->findById($id);
        if ($customization === null
            || !in_array($customization->getVendor()->getId(), $userVendorIds, true)) {
            throw HttpException::notFound('Customization request not found.');
        }

        return $customization;
    }
}
