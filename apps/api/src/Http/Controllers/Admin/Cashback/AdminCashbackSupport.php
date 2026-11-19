<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Cashback;

use Bayti\Api\Domain\Cashback\CashbackAward;
use Bayti\Api\Domain\Cashback\CashbackAwardRepository;
use Bayti\Api\Domain\Cashback\CashbackRule;
use Bayti\Api\Domain\Cashback\CashbackRuleRepository;
use Bayti\Api\Domain\Cashback\CashbackRuleTarget;
use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Serializers\CashbackSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared helpers for the admin cashback controllers (actor, id parsing,
 * repository access, rule shapes with budget stats + target labels).
 */
trait AdminCashbackSupport
{
    private function actor(ServerRequestInterface $request): User
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }
        return $user;
    }

    /** @param array<string, string> $args */
    private function idArg(array $args, string $what): int
    {
        $raw = (string) ($args['id'] ?? '');
        if (!ctype_digit($raw) || (int) $raw < 1) {
            throw HttpException::notFound("{$what} not found.");
        }
        return (int) $raw;
    }

    private function ruleRepo(EntityManagerInterface $em): CashbackRuleRepository
    {
        /** @var CashbackRuleRepository $repo */
        $repo = $em->getRepository(CashbackRule::class);
        return $repo;
    }

    private function awardRepo(EntityManagerInterface $em): CashbackAwardRepository
    {
        /** @var CashbackAwardRepository $repo */
        $repo = $em->getRepository(CashbackAward::class);
        return $repo;
    }

    private function findRule(EntityManagerInterface $em, int $id): CashbackRule
    {
        $rule = $this->ruleRepo($em)->find($id);
        if (!$rule instanceof CashbackRule) {
            throw HttpException::notFound('Cashback rule not found.');
        }
        return $rule;
    }

    private function findAward(EntityManagerInterface $em, int $id): CashbackAward
    {
        $award = $this->awardRepo($em)->find($id);
        if (!$award instanceof CashbackAward) {
            throw HttpException::notFound('Cashback award not found.');
        }
        return $award;
    }

    /**
     * Rule detail shape with budget stats and target display names.
     *
     * @return array<string, mixed>
     */
    private function ruleDetail(EntityManagerInterface $em, CashbackSerializer $serializer, CashbackRule $rule): array
    {
        $id = (int) $rule->getId();
        $repo = $this->ruleRepo($em);
        $stats = [
            'spent'        => $repo->spentByRuleIds([$id])[$id] ?? '0.00',
            'awards_count' => $repo->awardCountsByRuleIds([$id])[$id] ?? 0,
        ];
        $labels = [];
        foreach ($rule->getTargets() as $t) {
            $label = null;
            try {
                $label = match ($t->getTargetType()) {
                    CashbackRuleTarget::TYPE_VENDOR   => $em->find(Vendor::class, $t->getTargetId())?->getName(),
                    CashbackRuleTarget::TYPE_PRODUCT  => $em->find(Product::class, $t->getTargetId())?->getName(),
                    CashbackRuleTarget::TYPE_CATEGORY => $em->find(Category::class, $t->getTargetId())?->getName(),
                    default => null,
                };
            } catch (\Throwable) {
                $label = null;
            }
            if ($label !== null) {
                $labels[$t->getTargetType() . ':' . $t->getTargetId()] = $label;
            }
        }
        return $serializer->ruleShape($rule, $stats, $labels);
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return [];
        }
        /** @var array<string, mixed> $body */
        return $body;
    }

    private function reasonFrom(ServerRequestInterface $request, bool $required): string
    {
        $raw = $this->body($request)['reason'] ?? null;
        $reason = is_string($raw) ? trim($raw) : '';
        if ($required && ($reason === '' || mb_strlen($reason) > 255)) {
            throw HttpException::validation(['reason' => ['A reason (1-255 characters) is required.']]);
        }
        return mb_substr($reason, 0, 255);
    }
}
