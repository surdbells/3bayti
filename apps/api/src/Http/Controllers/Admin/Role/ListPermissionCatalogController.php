<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\Admin\Role;

use Bayti\Api\Domain\Authz\PermissionCatalog;
use Bayti\Api\Http\Responder;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /v3/admin/permission-catalog — modules+permissions and role presets for the matrix UI. */
final class ListPermissionCatalogController
{
    use Responder;
    public function __construct(protected readonly ResponseFactoryInterface $responseFactory) {}
    protected function getResponseFactory(): ResponseFactoryInterface { return $this->responseFactory; }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $modules = [];
        foreach (PermissionCatalog::modules() as $slug => $module) {
            $permissions = [];
            foreach ($module['permissions'] as $key => $label) {
                $permissions[] = ['key' => $key, 'label' => $label];
            }
            $modules[] = ['module' => $slug, 'label' => $module['label'], 'permissions' => $permissions];
        }

        $presets = [];
        foreach (PermissionCatalog::systemRoles() as $slug => $def) {
            $presets[] = [
                'slug' => $slug,
                'name' => $def['name'],
                'description' => $def['description'],
                'permissions' => $def['permissions'],
            ];
        }

        return $this->ok(['data' => ['modules' => $modules, 'presets' => $presets]]);
    }
}
