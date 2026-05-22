<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\Vendor\Label;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorLabel;
use Bayti\Api\Domain\Catalog\VendorLabelRepository;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Vendor label CRUD — all four operations in one controller, routed
 * by HTTP method, to keep the namespace tidy. M3.4-D.
 *
 *   GET    /v3/vendor/labels         — list active labels
 *   POST   /v3/vendor/labels         — create label
 *   PUT    /v3/vendor/labels/{id}    — rename label
 *   DELETE /v3/vendor/labels/{id}    — soft-delete (setActive(false))
 */
final class VendorLabelCrudController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
    ) {}

    protected function getResponseFactory(): ResponseFactoryInterface { return $this->responseFactory; }

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $vendor = $this->vendorOrFail($request);
        /** @var VendorLabelRepository $repo */
        $repo   = $this->em->getRepository(VendorLabel::class);
        $labels = $repo->listActiveByVendor($vendor);
        return $this->ok(['data' => array_map([$this, 'shape'], $labels)]);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $vendor = $this->vendorOrFail($request);
        $body   = (array) ($request->getParsedBody() ?? []);
        $name   = trim((string) ($body['label'] ?? $body['name'] ?? ''));
        if ($name === '') throw HttpException::badRequest('label name is required.');

        $slug  = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '', '-'));
        $slug  = ($slug !== '' ? $slug : 'label') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $label = new VendorLabel($vendor, $slug, $name);

        /** @var VendorLabelRepository $repo */
        $repo = $this->em->getRepository(VendorLabel::class);
        $repo->save($label);

        return $this->created(['data' => $this->shape($label)]);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $vendor  = $this->vendorOrFail($request);
        $id      = (int) $request->getAttribute('id');
        $label   = $this->labelOrFail($id, $vendor);
        $body    = (array) ($request->getParsedBody() ?? []);
        $newName = trim((string) ($body['label'] ?? $body['name'] ?? ''));
        if ($newName !== '') $label->setName($newName);

        /** @var VendorLabelRepository $repo */
        $repo = $this->em->getRepository(VendorLabel::class);
        $repo->save($label);

        return $this->ok(['data' => $this->shape($label)]);
    }

    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $vendor = $this->vendorOrFail($request);
        $id     = (int) $request->getAttribute('id');
        $label  = $this->labelOrFail($id, $vendor);
        $label->setActive(false);

        /** @var VendorLabelRepository $repo */
        $repo = $this->em->getRepository(VendorLabel::class);
        $repo->save($label);

        return $this->noContent();
    }

    // ── helpers ─────────────────────────────────────────────────────

    private function vendorOrFail(ServerRequestInterface $request): Vendor
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        /** @var VendorRepository $repo */
        $repo = $this->em->getRepository(Vendor::class);
        $vendors = $repo->findByOwnerUser($user);
        if (empty($vendors)) throw HttpException::forbidden('No approved vendor account found.');
        return $vendors[0];
    }

    private function labelOrFail(int $id, Vendor $vendor): VendorLabel
    {
        /** @var VendorLabelRepository $repo */
        $repo  = $this->em->getRepository(VendorLabel::class);
        $label = $repo->find($id);
        if ($label === null || $label->getVendor()->getId() !== $vendor->getId()) {
            throw HttpException::notFound('Label not found.');
        }
        return $label;
    }

    /** @return array<string,mixed> */
    private function shape(VendorLabel $l): array
    {
        return [
            'id'           => $l->getId(),
            'label'        => $l->getName(),
            'name'         => $l->getName(),
            'slug'         => $l->getSlug(),
            'display_order'=> $l->getDisplayOrder(),
            'is_active'    => $l->isActive(),
        ];
    }
}
