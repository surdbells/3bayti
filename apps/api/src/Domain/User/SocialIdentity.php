<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\User;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Federated sign-in identity — links a verified provider account
 * (Google or Apple, via Firebase) to a platform {@see User}.
 *
 * One user may hold several identities (e.g. both a Google and an Apple
 * login that resolve to the same account). A given (provider,
 * provider_uid) pair, however, belongs to AT MOST ONE user — the
 * UNIQUE(provider, provider_uid) constraint is both the integrity guard
 * and the lookup key for the "find or create" social-login flow.
 *
 * provider_uid
 * ------------
 * The provider's stable subject identifier — Google's `sub`, Apple's
 * `sub`. Pulled from the Firebase token's `firebase.identities` map
 * (falling back to the token `sub`). This NEVER changes for a given
 * provider account, which is why we key on it rather than email (email
 * can change or be absent, especially for Apple "Hide My Email").
 *
 * email
 * -----
 * Best-effort copy of the email the provider asserted at link time.
 * Nullable because Apple private-relay logins may omit it. Purely
 * informational (shown in "connected accounts" UI) — never used as a
 * security identifier.
 */
#[ORM\Entity(repositoryClass: SocialIdentityRepository::class)]
#[ORM\Table(name: 'social_identities')]
#[ORM\Index(columns: ['user_id'], name: 'idx_social_identities_user')]
#[ORM\UniqueConstraint(name: 'uniq_social_identity_provider_uid', columns: ['provider', 'provider_uid'])]
class SocialIdentity
{
    public const PROVIDER_GOOGLE = 'google';
    public const PROVIDER_APPLE = 'apple';

    public const ALL_PROVIDERS = [
        self::PROVIDER_GOOGLE,
        self::PROVIDER_APPLE,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'string', length: 16)]
    private string $provider;

    #[ORM\Column(name: 'provider_uid', type: 'string', length: 255)]
    private string $providerUid;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(
        User $user,
        string $provider,
        string $providerUid,
        ?string $email = null,
    ) {
        if (!in_array($provider, self::ALL_PROVIDERS, true)) {
            throw new \InvalidArgumentException("Unknown social provider: {$provider}");
        }

        $this->user = $user;
        $this->provider = $provider;
        $this->providerUid = $providerUid;
        $this->email = ($email === null || $email === '') ? null : strtolower(trim($email));
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int                 { return $this->id; }
    public function getUser(): User               { return $this->user; }
    public function getProvider(): string         { return $this->provider; }
    public function getProviderUid(): string      { return $this->providerUid; }
    public function getEmail(): ?string           { return $this->email; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
}
