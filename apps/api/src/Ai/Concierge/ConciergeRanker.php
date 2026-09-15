<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Concierge;

use Bayti\Api\Ai\AiException;
use Bayti\Api\Ai\AiProviderInterface;
use Bayti\Api\Domain\Catalog\Product;

/**
 * Re-orders the retrieved shortlist against the shopper's request and writes a
 * one-line reason per pick.
 *
 * Hard anti-hallucination boundary: the model is given ONLY the shortlist and
 * may return only ids from it. Any id not in the shortlist is dropped, so Ain
 * can never surface a product that wasn't retrieved from the live catalogue.
 * When AI is off or the call fails, the shortlist order is used unchanged.
 */
final class ConciergeRanker
{
    public function __construct(private readonly AiProviderInterface $ai)
    {
    }

    /**
     * @param list<Product> $shortlist already validated (orderable + in stock)
     * @return list<ConciergeItem>
     */
    public function rank(ConciergeIntent $intent, array $shortlist, string $query, int $limit = 12): array
    {
        if ($shortlist === []) {
            return [];
        }

        /** @var array<int, Product> $byId */
        $byId = [];
        foreach ($shortlist as $p) {
            $id = $p->getId();
            if ($id !== null) {
                $byId[$id] = $p;
            }
        }

        if (!$this->ai->isEnabled() || count($byId) <= 1) {
            return $this->passthrough($shortlist, $limit);
        }

        try {
            $raw = $this->ai->completeJson(
                self::systemPrompt(),
                self::userPrompt($query, $byId, $limit),
                self::schema(),
                'concierge_ranking',
            );
        } catch (AiException) {
            return $this->passthrough($shortlist, $limit);
        }

        $ranking = $raw['ranking'] ?? null;
        if (!is_array($ranking)) {
            return $this->passthrough($shortlist, $limit);
        }

        $out = [];
        foreach ($ranking as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = self::intId($row['id'] ?? null);
            if ($id === null || !isset($byId[$id])) {
                continue; // hallucinated / already used — drop.
            }
            $reason = is_string($row['reason'] ?? null) ? trim($row['reason']) : '';
            $out[] = new ConciergeItem($byId[$id], $reason);
            unset($byId[$id]);
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out !== [] ? $out : $this->passthrough($shortlist, $limit);
    }

    /**
     * @param list<Product> $shortlist
     * @return list<ConciergeItem>
     */
    private function passthrough(array $shortlist, int $limit): array
    {
        $out = [];
        foreach (array_slice($shortlist, 0, $limit) as $p) {
            $out[] = new ConciergeItem($p, '');
        }
        return $out;
    }

    private static function intId(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && ctype_digit($v)) {
            return (int) $v;
        }
        return null;
    }

    private static function systemPrompt(): string
    {
        return <<<PROMPT
            You are Ain, a personal shopping stylist for the 3bayti modest-fashion
            marketplace. You are given a shopper request and a list of REAL candidate
            products (id, name, store, price in AED, colours). Choose and order the best
            matches for the request.

            Rules:
            - Use ONLY the given candidate ids. Never invent an id or a product.
            - Order best match first; include only genuinely relevant items.
            - reason: one short, warm sentence (<= 16 words) on why it fits — refer to the
              request (occasion, colour, budget, style). No prices unless helpful.
            - Return at most the requested number of items.
            PROMPT;
    }

    /**
     * @param array<int, Product> $byId
     */
    private static function userPrompt(string $query, array $byId, int $limit): string
    {
        $candidates = [];
        foreach ($byId as $id => $p) {
            $candidates[] = [
                'id' => $id,
                'name' => $p->getName(),
                'store' => $p->getVendor()->getName(),
                'price_aed' => (float) $p->effectivePrice(),
                'colours' => $p->getAvailableColors(),
            ];
        }

        return 'Shopper request: ' . $query . "\n"
            . 'Return at most ' . $limit . " items.\n"
            . 'Candidates: ' . (string) json_encode($candidates);
    }

    /**
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'ranking' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'reason' => ['type' => 'string'],
                        ],
                        'required' => ['id', 'reason'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['ranking'],
            'additionalProperties' => false,
        ];
    }
}
