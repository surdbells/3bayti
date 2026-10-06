<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Otp;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Setting\OtpProviderSettings;
use Bayti\Api\Domain\Setting\SettingsService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\OtpProviderSettingsSerializer;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /v3/admin/otp/providers   (settings.edit)
 *
 * Set which OTP providers are enabled and in what PRIORITY order the router
 * tries them (first success wins; on error it fails over to the next). Takes
 * effect on the NEXT send — no redeploy, the router reads this live.
 *
 * Body:
 *   {
 *     "providers": [
 *       { "key": "messagecentral", "enabled": true },
 *       { "key": "cequens",        "enabled": true }
 *     ]
 *   }
 *
 * Array ORDER is the priority. Unknown keys are rejected; every KNOWN provider
 * must appear exactly once (so the admin always sends a complete, unambiguous
 * ordering). At least one provider must be enabled — an all-disabled config
 * would silently break OTP, so we refuse it (422).
 */
final class UpdateOtpProvidersController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly SettingsService $settings,
        private readonly OtpProviderSettingsSerializer $serializer,
        private readonly AuditEmitter $audit,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$actor instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $body = $request->getParsedBody();
        if (!is_array($body) || !isset($body['providers']) || !is_array($body['providers'])) {
            throw HttpException::badRequest('Expected a "providers" array.');
        }

        [$order, $enabled] = $this->parseProviders($body['providers']);

        $before = OtpProviderSettings::fromArray($this->settings->get(OtpProviderSettings::KEY));
        $after = OtpProviderSettings::fromArray(['order' => $order, 'enabled' => $enabled]);

        $this->settings->set(OtpProviderSettings::KEY, $after->toArray());

        $this->audit->recordSettingChange(
            request: $request,
            actor: $actor,
            settingKey: OtpProviderSettings::KEY,
            before: $before->toArray(),
            after: $after->toArray(),
        );

        return $this->ok($this->serializer->shape($after));
    }

    /**
     * Validate the providers array → [order, enabledMap]. Enforces: each item
     * is an object with a KNOWN key + boolean enabled; every KNOWN provider
     * present exactly once; at least one enabled.
     *
     * @param array<mixed> $raw
     * @return array{0: list<string>, 1: array<string, bool>}
     */
    private function parseProviders(array $raw): array
    {
        $fieldErrors = [];
        $order = [];
        $enabled = [];

        foreach (array_values($raw) as $i => $item) {
            if (!is_array($item) || !isset($item['key']) || !is_string($item['key'])) {
                $fieldErrors["providers.{$i}.key"] = ['A provider key string is required.'];
                continue;
            }
            $key = $item['key'];
            if (!in_array($key, OtpProviderSettings::KNOWN, true)) {
                $fieldErrors["providers.{$i}.key"] = ["Unknown OTP provider '{$key}'."];
                continue;
            }
            if (in_array($key, $order, true)) {
                $fieldErrors["providers.{$i}.key"] = ["Duplicate OTP provider '{$key}'."];
                continue;
            }
            $order[] = $key;
            $enabled[$key] = (bool) ($item['enabled'] ?? false);
        }

        // Every KNOWN provider must be accounted for (complete ordering).
        foreach (OtpProviderSettings::KNOWN as $known) {
            if (!in_array($known, $order, true)) {
                $fieldErrors['providers'] = ["Every provider must be listed. Missing '{$known}'."];
            }
        }

        if ($fieldErrors === [] && !in_array(true, $enabled, true)) {
            $fieldErrors['providers'] = ['At least one OTP provider must be enabled.'];
        }

        if ($fieldErrors !== []) {
            throw HttpException::validation($fieldErrors);
        }

        return [$order, $enabled];
    }
}
