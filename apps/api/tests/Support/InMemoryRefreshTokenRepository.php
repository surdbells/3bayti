<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Support;

use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\RefreshTokenRepository;
use Bayti\Api\Domain\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * user_refresh_tokens in memory, for HTTP tests that follow one session
 * across several requests (sign in, refresh, change password, merge,
 * logout, refresh again) without a database.
 *
 * Mirrors the real repository's semantics, which RefreshTokenRepositoryDqlTest
 * pins at the SQL level: ending a session relabels 'rotated' rows (their
 * revoked_at kept) and revokes still-active ones, other reasons untouched;
 * reassignUser moves every row. Rows are the same objects the controllers
 * mutate, so a rotation done by RefreshController is visible here at once.
 */
final class InMemoryRefreshTokenRepository extends RefreshTokenRepository
{
    /** @var array<string, RefreshToken> keyed by jti */
    private array $rows = [];

    public function __construct(EntityManagerInterface $em)
    {
        parent::__construct($em, new ClassMetadata(RefreshToken::class));
    }

    public function findByJti(string $jti): ?RefreshToken
    {
        return $this->rows[$jti] ?? null;
    }

    public function save(RefreshToken $token, bool $flush = true): void
    {
        $this->rows[$token->getJti()] = $token;
    }

    public function revokeFamily(string $familyId, string $reason): int
    {
        return $this->endSessionsWhere(
            static fn (RefreshToken $t): bool => $t->getFamilyId() === $familyId,
            $reason,
        );
    }

    public function revokeAllForUser(User $user, string $reason): int
    {
        return $this->endSessionsWhere(
            static fn (RefreshToken $t): bool => $t->getUser() === $user,
            $reason,
        );
    }

    public function reassignUser(User $from, User $to): int
    {
        $moved = 0;
        $userProp = new \ReflectionProperty(RefreshToken::class, 'user');
        foreach ($this->rows as $row) {
            if ($row->getUser() === $from) {
                $userProp->setValue($row, $to);
                $moved++;
            }
        }
        return $moved;
    }

    /** @param callable(RefreshToken): bool $inScope */
    private function endSessionsWhere(callable $inScope, string $reason): int
    {
        $changed = 0;
        $reasonProp = new \ReflectionProperty(RefreshToken::class, 'revokedReason');
        foreach ($this->rows as $row) {
            if (!$inScope($row)) {
                continue;
            }
            if ($row->wasRotated()) {
                $reasonProp->setValue($row, $reason);
                $changed++;
            } elseif (!$row->isRevoked()) {
                $row->revoke($reason);
                $changed++;
            }
        }
        return $changed;
    }
}
