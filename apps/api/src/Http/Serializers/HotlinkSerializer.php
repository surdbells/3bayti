<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Hotlink\Hotlink;

/**
 * Shapes a Hotlink for the API, including the ready-to-share short URL built
 * off WEB_APP_URL (…/s/{code}). The public short link lives on the storefront
 * domain; the web app's /s/:code route resolves it and navigates.
 */
final class HotlinkSerializer
{
    private readonly string $webBase;

    public function __construct()
    {
        $webBase = $_ENV['WEB_APP_URL'] ?? 'https://3bayti.ae';
        $this->webBase = rtrim(is_string($webBase) ? $webBase : 'https://3bayti.ae', '/');
    }

    /**
     * @return array<string, mixed>
     */
    public function shape(Hotlink $hotlink): array
    {
        return [
            'code'        => $hotlink->getCode(),
            'short_url'   => $this->webBase . '/s/' . $hotlink->getCode(),
            'target_type' => $hotlink->getTargetType(),
            'target_slug' => $hotlink->getTargetSlug(),
            'click_count' => $hotlink->getClickCount(),
        ];
    }
}
