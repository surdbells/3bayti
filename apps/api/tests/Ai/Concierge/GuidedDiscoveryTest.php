<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Ai\Concierge;

use Bayti\Api\Ai\Concierge\ConciergeCopy;
use Bayti\Api\Ai\Concierge\ConciergeIntent;
use Bayti\Api\Ai\Concierge\ConciergeRanker;
use Bayti\Api\Ai\Concierge\ConciergeService;
use Bayti\Api\Ai\Concierge\ConversationHistory;
use Bayti\Api\Ai\Concierge\DiscoveryDecision;
use Bayti\Api\Ai\Concierge\DiscoveryLexicon;
use Bayti\Api\Ai\Concierge\DiscoveryPlanner;
use Bayti\Api\Ai\Concierge\IntentParser;
use Bayti\Api\Ai\Concierge\ProductRetrievalService;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Scripted AI provider recording every completeJson call. */
final class GuidedAiProvider implements \Bayti\Api\Ai\AiProviderInterface
{
    public bool $enabled = true;
    /** @var list<array<string, mixed>> */
    public array $queue = [];
    /** @var list<array{system: string, user: string}> */
    public array $calls = [];

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function embedModel(): ?string
    {
        return null;
    }

    public function completeJson(string $system, string $user, array $schema, string $schemaName = 'result'): array
    {
        $this->calls[] = ['system' => $system, 'user' => $user];
        return array_shift($this->queue) ?? [];
    }

    public function embed(array $texts): array
    {
        return array_map(static fn (): array => [], $texts);
    }
}

#[CoversClass(DiscoveryPlanner::class)]
#[CoversClass(DiscoveryLexicon::class)]
#[CoversClass(DiscoveryDecision::class)]
#[CoversClass(ConciergeCopy::class)]
#[CoversClass(ConciergeService::class)]
final class GuidedDiscoveryTest extends TestCase
{
    // ===== DiscoveryPlanner =====

    #[Test]
    public function asksTheHighestPriorityUnfilledSlotFirstOneQuestionPerTurn(): void
    {
        $d = $this->plan(ConciergeIntent::keywordFallback('help me'), 'help me', [], []);
        self::assertSame(DiscoveryDecision::ACTION_ASK, $d->action);
        self::assertSame('recipient', $d->questionSlot);
        self::assertSame(['recipient'], $d->askedSlots);

        // Recipient answered (asked) => occasion next, and so on in priority order.
        $d = $this->plan(ConciergeIntent::keywordFallback('For me'), 'For me', [], ['recipient']);
        self::assertSame('occasion', $d->questionSlot);
        self::assertSame(['recipient', 'occasion'], $d->askedSlots);

        $d = $this->plan(ConciergeIntent::keywordFallback('no preference'), 'No preference', [], ['recipient', 'occasion']);
        self::assertSame('type', $d->questionSlot);

        $d = $this->plan(ConciergeIntent::keywordFallback('x'), 'No preference', [], ['recipient', 'occasion', 'type']);
        self::assertSame('colour', $d->questionSlot);
        self::assertSame(['recipient', 'occasion', 'type', 'colour'], $d->askedSlots);

        $d = $this->plan(ConciergeIntent::keywordFallback('x'), 'No preference', [], ['recipient', 'occasion', 'type', 'colour']);
        self::assertSame('style', $d->questionSlot);

        $d = $this->plan(ConciergeIntent::keywordFallback('x'), 'No preference', [], ['recipient', 'occasion', 'type', 'colour', 'style']);
        self::assertSame('budget', $d->questionSlot);
        self::assertSame(DiscoveryPlanner::SLOTS, $d->askedSlots);
        self::assertSame(['recipient', 'occasion', 'type', 'colour', 'style', 'budget'], DiscoveryPlanner::SLOTS);
    }

    #[Test]
    public function slotsFilledByTheIntentOrTheShoppersWordsAreSkipped(): void
    {
        // Model intent fills occasion + type: ask recipient, then style next.
        $intent = new ConciergeIntent('abaya', null, ['eid'], [], [], null, null, ['abaya'], [], false, 0.9);
        $d = $this->plan($intent, 'abaya for eid', [], []);
        self::assertSame('recipient', $d->questionSlot);
        self::assertSame(['occasion', 'type'], $d->filledSlots);

        $d = $this->plan($intent, 'For me', [['role' => 'user', 'text' => 'abaya for eid']], ['recipient']);
        self::assertSame('colour', $d->questionSlot);

        // A palette fills the COLOUR slot; a cut/finish fills the STYLE slot.
        $d = $this->plan(ConciergeIntent::keywordFallback('pastel kaftan'), 'pastel kaftan', [], []);
        self::assertSame(['type', 'colour'], $d->filledSlots);
        $d = $this->plan(ConciergeIntent::keywordFallback('embellished kaftan'), 'embellished kaftan', [], []);
        self::assertSame(['type', 'style'], $d->filledSlots);
        $d = $this->plan(ConciergeIntent::keywordFallback('قفطان مطرز بألوان ترابية'), 'قفطان مطرز بألوان ترابية', [], []);
        self::assertSame(['type', 'colour', 'style'], $d->filledSlots);
        // The model's palette style fills colour, its cut style fills style.
        $m = new ConciergeIntent('kaftan', null, [], [], ['jewel', 'minimal'], null, null, [], [], false, 0.9);
        self::assertSame(['type', 'colour', 'style'], DiscoveryPlanner::filledSlots($m, ['x']));

        // isGift fills recipient.
        $gift = new ConciergeIntent(null, null, [], [], [], null, null, [], [], true, 0.9);
        self::assertSame('occasion', $this->plan($gift, 'a present', [], [])->questionSlot);

        // AI off: the lexicon recognises the words (Arabic too).
        $d = $this->plan(ConciergeIntent::keywordFallback('عباية للعيد'), 'عباية للعيد', [], []);
        self::assertSame(['occasion', 'type'], $d->filledSlots);
        self::assertSame('recipient', $d->questionSlot);
    }

    #[Test]
    public function anAskedSlotCountsAsFilledAndAtMostSixQuestionsAreAsked(): void
    {
        self::assertSame(6, DiscoveryPlanner::MAX_QUESTIONS);
        // The answer to the 6th question: every slot asked, and Ain's latest
        // message is that question — guided results, not a refinement.
        $history = [
            ['role' => 'user', 'text' => 'No preference'],
            ['role' => 'ain', 'text' => ConciergeCopy::question('budget', 'en')],
        ];
        $d = $this->plan(ConciergeIntent::keywordFallback('No preference'), 'No preference', $history, DiscoveryPlanner::SLOTS);

        self::assertSame(DiscoveryDecision::ACTION_RESULTS, $d->action);
        self::assertNull($d->questionSlot);
        self::assertFalse($d->completeBefore);
        // Products shown => every slot marked (discovery complete).
        self::assertSame(DiscoveryPlanner::SLOTS, $d->askedSlots);
        self::assertTrue($d->isGuidedResults());

        // Five asked + the budget given in the answer: results too.
        $d = $this->plan(ConciergeIntent::keywordFallback('Under 300 AED'), 'Under 300 AED', [], ['recipient', 'occasion', 'type', 'colour', 'style']);
        self::assertSame(DiscoveryDecision::ACTION_RESULTS, $d->action);
        self::assertTrue($d->isGuidedResults());
    }

    #[Test]
    public function allSlotsFilledShowsProducts(): void
    {
        $intent = new ConciergeIntent('abaya', null, ['eid'], ['black'], ['embellished'], null, 500.0, ['abaya'], [], true, 0.9);
        $d = $this->plan($intent, 'Under 500 AED', [], ['recipient']);
        self::assertSame(DiscoveryDecision::ACTION_RESULTS, $d->action);
    }

    #[Test]
    public function showNowOrAnExplicitShowMeSkipsTheQuestions(): void
    {
        $d = $this->plan(ConciergeIntent::keywordFallback('x'), 'Show me what you have', [], [], true);
        self::assertSame(DiscoveryDecision::ACTION_RESULTS, $d->action);
        self::assertTrue($d->showNow);

        foreach (['show me', 'Show me what you have', 'just show me', 'please show me abayas', 'skip', 'أريني ما لديكِ', 'فقط اعرضي', 'ماذا لديكِ؟'] as $msg) {
            $d = $this->plan(ConciergeIntent::keywordFallback($msg), $msg, [], ['recipient']);
            self::assertSame(DiscoveryDecision::ACTION_RESULTS, $d->action, $msg);
            self::assertTrue(DiscoveryLexicon::isShowNow($msg), $msg);
        }
        self::assertFalse(DiscoveryLexicon::isShowNow('a showy abaya'));
        self::assertFalse(DiscoveryLexicon::isShowNow('For me'));
    }

    #[Test]
    public function aSpecificFirstMessageStillAsksEveryRemainingSlot(): void
    {
        // Occasion, type, colour and style given: no shortcut — Ain still asks
        // the recipient, then the budget, before any products.
        $msg = 'an elegant black abaya for a wedding';
        $d = $this->plan(ConciergeIntent::keywordFallback($msg), $msg, [], []);
        self::assertSame(DiscoveryDecision::ACTION_ASK, $d->action);
        self::assertSame('recipient', $d->questionSlot);
        self::assertSame(['occasion', 'type', 'colour', 'style'], $d->filledSlots);

        $history = [['role' => 'user', 'text' => $msg], ['role' => 'ain', 'text' => ConciergeCopy::question('recipient', 'en')]];
        $d = $this->plan(ConciergeIntent::keywordFallback('For me'), 'For me', $history, ['recipient']);
        self::assertSame('budget', $d->questionSlot);
        self::assertSame(['recipient', 'budget'], $d->askedSlots);

        $history = [...$history, ['role' => 'user', 'text' => 'For me'], ['role' => 'ain', 'text' => ConciergeCopy::question('budget', 'en')]];
        $d = $this->plan(ConciergeIntent::keywordFallback('Under 300 AED'), 'Under 300 AED', $history, ['recipient', 'budget']);
        self::assertSame(DiscoveryDecision::ACTION_RESULTS, $d->action);
        self::assertTrue($d->isGuidedResults());

        // Even a message that fills every slot but the budget gets that question.
        $msg = 'an embellished black abaya for my mother for eid';
        $d = $this->plan(ConciergeIntent::keywordFallback($msg), $msg, [], []);
        self::assertSame('budget', $d->questionSlot);
    }

    #[Test]
    public function aSlotBeforeTheLatestAskedOneIsNeverReAsked(): void
    {
        // The first message (which gave the type) scrolled out of the history:
        // later slots were asked after it, so the type is not asked again.
        $d = $this->plan(ConciergeIntent::keywordFallback('No preference'), 'No preference', [], ['recipient', 'occasion', 'colour', 'style']);
        self::assertSame('budget', $d->questionSlot);
        self::assertNull(DiscoveryPlanner::nextSlot(['recipient', 'budget'], []));
        self::assertSame('occasion', DiscoveryPlanner::nextSlot(['recipient'], ['type']));
    }

    #[Test]
    public function onceProductsWereShownRefinementsGoStraightToResults(): void
    {
        $d = $this->plan(ConciergeIntent::keywordFallback('in navy'), 'in navy', [], DiscoveryPlanner::SLOTS);
        self::assertSame(DiscoveryDecision::ACTION_RESULTS, $d->action);
        self::assertTrue($d->completeBefore);
        self::assertFalse($d->isGuidedResults());
    }

    #[Test]
    public function aLegacyClientWithoutAskedSlotsNeverLoops(): void
    {
        // History holds Ain's 4 question templates: the legacy 4-question cap applies.
        $history = [];
        foreach (['recipient', 'occasion', 'type', 'colour'] as $i => $slot) {
            $history[] = ['role' => 'user', 'text' => $i === 0 ? 'help me' : 'No preference'];
            $history[] = ['role' => 'ain', 'text' => ConciergeCopy::question($slot, 'en')];
        }
        $d = $this->plan(ConciergeIntent::keywordFallback('No preference'), 'No preference', $history, null);
        self::assertSame(DiscoveryDecision::ACTION_RESULTS, $d->action);

        // The retired combined question (older history) counts as colour AND style.
        $old = [
            ['role' => 'user', 'text' => 'help me'],
            ['role' => 'ain', 'text' => ConciergeCopy::question('recipient', 'en')],
            ['role' => 'user', 'text' => 'For me'],
            ['role' => 'ain', 'text' => 'Any colours or style you love?'],
        ];
        self::assertSame(['recipient', 'colour', 'style'], DiscoveryPlanner::inferAskedFromHistory(ConversationHistory::fromMixed($old)));
        $d = $this->plan(ConciergeIntent::keywordFallback('Pastels'), 'Pastels', $old, null);
        self::assertSame('budget', $d->questionSlot);
        $d = $this->plan(ConciergeIntent::keywordFallback('Pastels'), 'Pastels', $old, ['recipient', 'style_or_colour']);
        self::assertSame('budget', $d->questionSlot);
        self::assertSame(['recipient', 'colour', 'style', 'budget'], $d->askedSlots);
        // An older client's "results shown" echo (the 5 old slot names) is complete.
        $d = $this->plan(ConciergeIntent::keywordFallback('in navy'), 'in navy', [], ['recipient', 'occasion', 'type', 'style_or_colour', 'budget']);
        self::assertSame(DiscoveryDecision::ACTION_RESULTS, $d->action);
        self::assertTrue($d->completeBefore);

        // A legacy client playing a whole conversation (its history cut to 8
        // entries) always ends in results and never repeats a question.
        $history = [];
        $slots = [];
        $q = 'help me';
        for ($turn = 0; $turn < 8; $turn++) {
            $d = $this->plan(ConciergeIntent::keywordFallback($q), $q, array_slice($history, -8), null);
            if ($d->action === DiscoveryDecision::ACTION_RESULTS) {
                break;
            }
            self::assertNotContains($d->questionSlot, $slots);
            $slots[] = $d->questionSlot;
            $history[] = ['role' => 'user', 'text' => $q];
            $history[] = ['role' => 'ain', 'text' => ConciergeCopy::question((string) $d->questionSlot, 'en')];
            $q = 'No preference';
        }
        self::assertSame(DiscoveryDecision::ACTION_RESULTS, $d->action);
        self::assertCount(DiscoveryPlanner::LEGACY_MAX_QUESTIONS, $slots);

        // Mid-way: the asked slots are inferred from the templates (no repeats).
        $d = $this->plan(ConciergeIntent::keywordFallback('For me'), 'For me', [
            ['role' => 'user', 'text' => 'help me'],
            ['role' => 'ain', 'text' => ConciergeCopy::question('recipient', 'ar')],
        ], null);
        self::assertSame('occasion', $d->questionSlot);
        self::assertSame(['recipient', 'occasion'], $d->askedSlots);

        // A conversation that already showed results (or predates guided
        // discovery) is complete: no questions.
        $d = $this->plan(ConciergeIntent::keywordFallback('cheaper'), 'cheaper', [
            ['role' => 'user', 'text' => 'abaya'],
            ['role' => 'ain', 'text' => 'Here are some pieces for “abaya”.'],
        ], null);
        self::assertSame(DiscoveryDecision::ACTION_RESULTS, $d->action);

        // A first message from a legacy client still gets ONE question.
        $d = $this->plan(ConciergeIntent::keywordFallback('abaya'), 'abaya', [], null);
        self::assertSame('recipient', $d->questionSlot);

        // A greeting is not a request: the guided flow still starts afterwards.
        $d = $this->plan(ConciergeIntent::keywordFallback('abaya'), 'abaya', [
            ['role' => 'user', 'text' => 'hello'],
            ['role' => 'ain', 'text' => ConciergeCopy::reply(ConciergeCopy::KIND_GREETING, 'en')],
        ], null);
        self::assertSame('recipient', $d->questionSlot);
    }

    #[Test]
    public function smallTalkKeepsTheAskedSlots(): void
    {
        $d = $this->plan(ConciergeIntent::keywordFallback(''), 'thanks', [], ['recipient'], false, true);
        self::assertSame(DiscoveryDecision::ACTION_SMALL_TALK, $d->action);
        self::assertSame(['recipient'], $d->askedSlots);
        self::assertNull($d->questionSlot);
    }

    #[Test]
    public function askedSlotsAreSanitised(): void
    {
        self::assertNull(DiscoveryPlanner::sanitizeAskedSlots(null));
        self::assertNull(DiscoveryPlanner::sanitizeAskedSlots('recipient'));
        self::assertSame([], DiscoveryPlanner::sanitizeAskedSlots([]));
        self::assertSame(
            ['occasion', 'recipient'],
            DiscoveryPlanner::sanitizeAskedSlots(['OCCASION', 'bogus', 42, 'recipient', 'occasion', ['x']]),
        );
        self::assertCount(6, DiscoveryPlanner::sanitizeAskedSlots([...DiscoveryPlanner::SLOTS, ...DiscoveryPlanner::SLOTS]) ?? []);
        // The retired combined slot means both colour and style were asked.
        self::assertSame(['type', 'colour', 'style'], DiscoveryPlanner::sanitizeAskedSlots(['type', 'style_or_colour', 'colour']));
    }

    // ===== DiscoveryLexicon =====

    #[Test]
    public function everyPredefinedChipIsUnderstoodInBothLocales(): void
    {
        $expect = [
            'recipient' => static fn (array $x): bool => $x['recipient'] !== null,
            'occasion' => static fn (array $x): bool => $x['occasions'] !== [],
            'type' => static fn (array $x): bool => $x['type'] !== null,
            'colour' => static fn (array $x): bool => $x['colours'] !== [] || array_filter($x['styles'], [DiscoveryLexicon::class, 'isColourStyle']) !== [],
            'style' => static fn (array $x): bool => $x['styles'] !== [],
            'budget' => static fn (array $x): bool => $x['budget_min'] !== null || $x['budget_max'] !== null,
        ];
        foreach (['en', 'ar'] as $locale) {
            foreach (DiscoveryPlanner::SLOTS as $slot) {
                $chips = ConciergeCopy::questionChips($slot, $locale);
                foreach (array_slice($chips, 0, -2) as $chip) {
                    self::assertTrue($expect[$slot](DiscoveryLexicon::extract([$chip])), "{$locale}/{$slot}: {$chip}");
                    self::assertTrue(DiscoveryLexicon::isConsumed($chip), "{$locale}: {$chip} is chip text, not a search keyword");
                    // Each answer fills ONLY its own slot (a colour chip never skips the style question).
                    self::assertSame([$slot], DiscoveryPlanner::filledSlots(ConciergeIntent::keywordFallback(''), [$chip]), "{$locale}/{$slot} fills only itself: {$chip}");
                }
            }
        }
    }

    #[Test]
    public function budgetsAreParsedInEnglishAndArabic(): void
    {
        $b = static fn (string $t): array => [DiscoveryLexicon::extract([$t])['budget_min'], DiscoveryLexicon::extract([$t])['budget_max']];
        self::assertSame([null, 300.0], $b('Under 300 AED'));
        self::assertSame([300.0, 700.0], $b('300–700 AED'));
        self::assertSame([700.0, null], $b('700+ AED'));
        self::assertSame([null, 800.0], $b('abaya under 800'));
        self::assertSame([null, 300.0], $b('أقل من ٣٠٠ درهم'));
        self::assertSame([700.0, null], $b('أكثر من 700 درهم'));
        // An abaya size range is not a budget.
        self::assertSame([null, null], $b('size 52-54 abaya'));
    }

    #[Test]
    public function enrichFillsOnlyMissingFieldsAndDropsChipKeywords(): void
    {
        $intent = new ConciergeIntent(null, null, [], [], [], null, null, ['Under 300 AED', 'Classic black', 'abaya'], [], false, 0.0);
        $e = DiscoveryLexicon::enrich($intent, ['abaya', 'A gift', 'Eid', 'Classic black', 'Under 300 AED'], true);

        self::assertSame('abaya', $e->productType);
        self::assertSame(['eid'], $e->occasions);
        self::assertSame(['black'], $e->colours);
        self::assertSame([], $e->styles, '"Classic black" is a colour answer');
        self::assertSame(300.0, $e->budgetMax);
        self::assertTrue($e->isGift);
        self::assertSame([], $e->keywords);

        // The model's own fields always win.
        $model = new ConciergeIntent('kaftan', null, ['wedding'], ['navy'], [], null, 900.0, ['kaftan'], [], false, 0.9);
        $e = DiscoveryLexicon::enrich($model, ['abaya', 'Eid', 'Under 300 AED'], false);
        self::assertSame('kaftan', $e->productType);
        self::assertSame(['wedding'], $e->occasions);
        self::assertSame(900.0, $e->budgetMax);
        self::assertSame(['kaftan'], $e->keywords);
    }

    // ===== ConciergeCopy =====

    #[Test]
    public function questionChipsAlwaysEndWithNoPreferenceAndShowMe(): void
    {
        foreach (['en', 'ar'] as $locale) {
            foreach (DiscoveryPlanner::SLOTS as $slot) {
                $chips = ConciergeCopy::questionChips($slot, $locale);
                self::assertGreaterThanOrEqual(4, count($chips));
                self::assertLessThanOrEqual(ConciergeCopy::MAX_QUESTION_CHIPS, count($chips));
                self::assertSame(ConciergeCopy::noPreferenceChip($locale), $chips[count($chips) - 2]);
                self::assertSame(ConciergeCopy::showNowChip($locale), $chips[count($chips) - 1]);
                self::assertTrue(DiscoveryLexicon::isShowNow($chips[count($chips) - 1]));
                self::assertMatchesRegularExpression('/[?؟]$/u', ConciergeCopy::question($slot, $locale));
                self::assertSame($slot, ConciergeCopy::questionSlotFor(ConciergeCopy::question($slot, $locale)));
            }
        }
        self::assertSame(['For me', 'A gift', 'No preference', 'Show me what you have'], ConciergeCopy::questionChips('recipient', 'en'));
        self::assertSame(['Eid', 'Wedding', 'Everyday', 'Work', 'Ramadan', 'Party / evening', 'No preference', 'Show me what you have'], ConciergeCopy::questionChips('occasion', 'en'));
        self::assertSame(['لي أنا', 'هدية', 'لا يهم', 'أريني ما لديكِ'], ConciergeCopy::questionChips('recipient', 'ar'));
        self::assertSame(['Under 300 AED', '300–700 AED', '700+ AED', 'No preference', 'Show me what you have'], ConciergeCopy::questionChips('budget', 'en'));
        self::assertNull(ConciergeCopy::questionSlotFor('Here are some pieces.'));
    }

    #[Test]
    public function refineChipsTargetWhatIsStillOpenAndMergeTheModelsIdeas(): void
    {
        $open = ConciergeIntent::keywordFallback('abaya');
        $chips = ConciergeCopy::refineChips($open, 'en', ['In navy', 'in black']);
        self::assertSame(['In black', 'Under 300 AED', 'More embellished', 'In navy', 'For a wedding instead', 'Show more like these'], $chips);

        $narrow = new ConciergeIntent('abaya', null, ['wedding'], ['black'], ['embellished'], null, 500.0, [], [], true, 0.9);
        $chips = ConciergeCopy::refineChips($narrow, 'ar');
        self::assertSame(['شيء بألوان أكثر', 'مشابه لكن أرخص', 'شيء أبسط', 'للعيد بدلاً من ذلك', 'أريني المزيد مثلها', 'شيء لي بدلاً من ذلك'], $chips);
        self::assertLessThanOrEqual(ConciergeCopy::MAX_REFINE_CHIPS, count(ConciergeCopy::refineChips($open, 'en', ['a', 'b', 'c'], ['x', 'y'])));
    }

    // ===== ConciergeService end-to-end (AI off and on) =====

    #[Test]
    public function aiOffGuidedConversationAsksThenShowsFilteredProducts(): void
    {
        $ai = new GuidedAiProvider();
        $ai->enabled = false;
        /** @var list<array<string, mixed>> $filters */
        $filters = [];
        $repo = $this->createMock(ProductRepository::class);
        $repo->method('findActivePaginated')->willReturnCallback(function (array $f) use (&$filters): array {
            $filters[] = $f;
            return ['items' => [$this->product(5, 'Black Abaya')], 'total' => 1];
        });
        $svc = $this->service($ai, $repo);

        $history = [];
        $asked = [];
        $turns = [];
        foreach (['abaya', 'For me', 'Eid', 'Classic black', 'Casual', 'Under 300 AED'] as $msg) {
            $r = $svc->ask($msg, 'en', 12, $history, $asked);
            $turns[] = $r;
            $history[] = ['role' => 'user', 'text' => $msg];
            $history[] = ['role' => 'ain', 'text' => $r->reply];
            $asked = $r->askedSlots;
        }

        // 'abaya' fills type: recipient → occasion → colour → style → budget, then results.
        self::assertSame(['recipient', 'occasion', 'colour', 'style', 'budget', null], array_map(static fn ($r) => $r->questionSlot, $turns));
        foreach (array_slice($turns, 0, 5) as $q) {
            self::assertTrue($q->needsClarification);
            self::assertSame([], $q->items);
        }
        self::assertSame(ConciergeCopy::question('occasion', 'en'), $turns[1]->reply);
        self::assertSame(ConciergeCopy::questionChips('occasion', 'en'), $turns[1]->followUps);

        $final = $turns[5];
        self::assertFalse($final->needsClarification);
        self::assertSame([5], $final->productIds());
        self::assertSame(DiscoveryPlanner::SLOTS, $final->askedSlots);
        // Retrieval used the answers as real filters, not chip text as a search.
        self::assertSame('abaya', $filters[0]['searchQuery']);
        self::assertSame(['black'], $filters[0]['colors']);
        self::assertSame(300.0, $filters[0]['maxPrice']);
        self::assertSame(['casual'], $final->intent->styles);
        self::assertSame(['eid'], $final->intent->occasions);
        self::assertSame('Here are some pieces for “black abaya for eid”.', $final->reply);
        self::assertLessThanOrEqual(6, count($final->followUps));
        self::assertContains('Show more like these', $final->followUps);
    }

    #[Test]
    public function aGuidedQuestionNeverRetrievesAndMakesOneModelCall(): void
    {
        $ai = new GuidedAiProvider();
        $ai->queue = [[
            'product_type' => 'kaftan', 'keywords' => ['kaftan'], 'is_gift' => true,
            'reply' => 'A kaftan gift, lovely!', 'follow_ups' => [], 'needs_clarification' => false, 'small_talk' => false,
        ]];
        $repo = $this->createMock(ProductRepository::class);
        $repo->expects(self::never())->method('findActivePaginated');

        $r = $this->service($ai, $repo)->ask('a kaftan for my mother', 'ar', 12, [], []);

        self::assertCount(1, $ai->calls);
        self::assertSame('occasion', $r->questionSlot);
        self::assertSame(ConciergeCopy::question('occasion', 'ar'), $r->reply);
        self::assertSame(ConciergeCopy::questionChips('occasion', 'ar'), $r->followUps);
        self::assertSame(['occasion'], $r->askedSlots);
    }

    #[Test]
    public function theSkipChipShowsProductsWithAnHonestReply(): void
    {
        $ai = new GuidedAiProvider();
        $ai->enabled = false;
        $r = $this->service($ai, $this->repoReturning([$this->product(1, 'Kaftan')]))
            ->ask('أريني ما لديكِ', 'ar', 12, [
                ['role' => 'user', 'text' => 'مرحبا'],
                ['role' => 'ain', 'text' => ConciergeCopy::reply(ConciergeCopy::KIND_GREETING, 'ar')],
            ], [], true);

        self::assertSame([1], $r->productIds());
        self::assertNull($r->questionSlot);
        self::assertSame(ConciergeCopy::reply(ConciergeCopy::KIND_PICKS, 'ar'), $r->reply);
    }

    // ===== review fixes =====

    #[Test]
    public function aiOffRefineChipAfterGuidedResultsKeepsEveryGuidedConstraint(): void
    {
        $ai = new GuidedAiProvider();
        $ai->enabled = false;
        /** @var list<array<string, mixed>> $filters */
        $filters = [];
        $repo = $this->createMock(ProductRepository::class);
        $repo->method('findActivePaginated')->willReturnCallback(function (array $f) use (&$filters): array {
            $filters[] = $f;
            return ['items' => [$this->product(5, 'Abaya')], 'total' => 1];
        });
        $svc = $this->service($ai, $repo);

        // (Fits the 8-entry history the clients send, so the first message is still visible.)
        [$history, $asked, $last] = $this->converse($svc, ['an abaya for eid', 'For me', 'Classic black', 'Under 300 AED']);
        self::assertNull($last->questionSlot);
        self::assertSame(DiscoveryPlanner::SLOTS, $asked);

        // "Something more colourful": type, occasion and budget survive; the new
        // style replaces "classic black"; chip text is never a search phrase.
        $filters = [];
        $r = $svc->ask('Something more colourful', 'en', 12, $history, $asked);
        self::assertSame('abaya', $r->intent->productType);
        self::assertSame(['eid'], $r->intent->occasions);
        self::assertSame(300.0, $r->intent->budgetMax);
        self::assertSame(['colourful'], $r->intent->styles);
        self::assertSame([], $r->intent->colours);
        self::assertSame([], $r->intent->keywords);
        self::assertSame('abaya', $filters[0]['searchQuery']);
        self::assertSame(300.0, $filters[0]['maxPrice']);
        self::assertSame([5], $r->productIds());

        // "For a wedding instead" swaps only the occasion.
        $r = $svc->ask('For a wedding instead', 'en', 12, $history, $asked);
        self::assertSame(['wedding'], $r->intent->occasions);
        self::assertSame('abaya', $r->intent->productType);
        self::assertSame(['black'], $r->intent->colours);
        self::assertSame(300.0, $r->intent->budgetMax);

        // "Show more like these" keeps everything.
        $r = $svc->ask('Show more like these', 'en', 12, $history, $asked);
        self::assertSame('abaya', $r->intent->productType);
        self::assertSame(['eid'], $r->intent->occasions);
        self::assertSame(300.0, $r->intent->budgetMax);
        self::assertSame([], $r->intent->keywords);
    }

    #[Test]
    public function aiOffGuidedResultsKeepTheFirstMessagesFreeTextDetail(): void
    {
        $ai = new GuidedAiProvider();
        $ai->enabled = false;
        /** @var list<array<string, mixed>> $filters */
        $filters = [];
        $repo = $this->createMock(ProductRepository::class);
        $repo->method('findActivePaginated')->willReturnCallback(function (array $f) use (&$filters): array {
            $filters[] = $f;
            return ['items' => [$this->product(9, 'Pearl Abaya')], 'total' => 1];
        });
        $svc = $this->service($ai, $repo);

        // All six questions: the first message is still in the (12-entry) history.
        [, , $last] = $this->converse($svc, ['something with pearls', 'For me', 'Eid', 'Abaya', 'No preference', 'No preference', 'Under 300 AED']);

        self::assertNull($last->questionSlot);
        self::assertSame(['pearls'], $last->intent->keywords);
        self::assertSame('abaya', $last->intent->productType);
        self::assertSame(300.0, $last->intent->budgetMax);
        self::assertSame('abaya pearls', $filters[0]['searchQuery']);
        self::assertSame('pearls', DiscoveryLexicon::residualKeyword('something with pearls'));
        self::assertSame('', DiscoveryLexicon::residualKeyword('help me'));
        self::assertSame(['pearls'], DiscoveryLexicon::residualKeywords(['something with pearls', 'Show me what you have', 'thanks', 'In black']));
    }

    #[Test]
    public function theSkipAlwaysWinsOverAModelSmallTalkFlag(): void
    {
        $d = $this->plan(ConciergeIntent::keywordFallback('x'), 'Show me what you have', [], ['recipient'], true, true);
        self::assertSame(DiscoveryDecision::ACTION_RESULTS, $d->action);

        // Wording alone (no flag) is a skip too.
        $d = $this->plan(ConciergeIntent::keywordFallback('x'), 'أريني ما لديكِ', [], ['recipient'], false, true);
        self::assertSame(DiscoveryDecision::ACTION_RESULTS, $d->action);
    }

    #[Test]
    public function midDiscoveryAChipAnswerIsAnAnswerEvenIfTheModelCallsItSmallTalk(): void
    {
        foreach (['No preference', 'لا يهم', 'Eid'] as $msg) {
            $d = $this->plan(ConciergeIntent::keywordFallback($msg), $msg, [
                ['role' => 'user', 'text' => 'help me'],
                ['role' => 'ain', 'text' => ConciergeCopy::question('recipient', 'en')],
                ['role' => 'user', 'text' => 'For me'],
                ['role' => 'ain', 'text' => ConciergeCopy::question('occasion', 'en')],
            ], ['recipient', 'occasion'], false, true);
            self::assertSame(DiscoveryDecision::ACTION_ASK, $d->action, $msg);
            self::assertSame('type', $d->questionSlot, $msg);
        }

        // Real small talk mid-discovery keeps the pending question on offer.
        $d = $this->plan(ConciergeIntent::keywordFallback('thanks'), 'thanks', [
            ['role' => 'user', 'text' => 'help me'],
            ['role' => 'ain', 'text' => ConciergeCopy::question('recipient', 'en')],
        ], ['recipient'], false, true);
        self::assertSame(DiscoveryDecision::ACTION_SMALL_TALK, $d->action);
        self::assertSame('recipient', $d->pendingSlot);
        self::assertSame(['recipient'], $d->askedSlots);
    }

    #[Test]
    public function smallTalkMidDiscoveryReoffersThePendingQuestionsChips(): void
    {
        $ai = new GuidedAiProvider();
        $ai->queue = [[
            'keywords' => [], 'is_gift' => false, 'reply' => 'You are welcome! Is it for you or a gift?',
            'follow_ups' => ['Hello'], 'needs_clarification' => false, 'small_talk' => true,
        ]];
        $repo = $this->createMock(ProductRepository::class);
        $repo->expects(self::never())->method('findActivePaginated');

        $r = $this->service($ai, $repo)->ask('thanks', 'en', 12, [
            ['role' => 'user', 'text' => 'help me'],
            ['role' => 'ain', 'text' => ConciergeCopy::question('recipient', 'en')],
        ], ['recipient']);

        // The friendly line, then the pending question template (the model's own
        // question is dropped: Ain asks exactly one, and it stays recognisable).
        self::assertSame('You are welcome! ' . ConciergeCopy::question('recipient', 'en'), $r->reply);
        self::assertSame('recipient', ConciergeCopy::questionSlotFor($r->reply));
        self::assertSame(ConciergeCopy::questionChips('recipient', 'en'), $r->followUps);
        self::assertSame('recipient', $r->questionSlot);
        self::assertSame(['recipient'], $r->askedSlots, 'the same question, not a new one');
        self::assertTrue($r->needsClarification);

        // With AI off the pending question itself is repeated.
        $off = new GuidedAiProvider();
        $off->enabled = false;
        $r = $this->service($off, $repo)->ask('thanks', 'ar', 12, [
            ['role' => 'user', 'text' => 'ساعديني'],
            ['role' => 'ain', 'text' => ConciergeCopy::question('recipient', 'ar')],
        ], ['recipient']);
        self::assertSame(ConciergeCopy::question('recipient', 'ar'), $r->reply);
        self::assertSame(ConciergeCopy::questionChips('recipient', 'ar'), $r->followUps);
    }

    #[Test]
    public function legacyClientsGetTheSkipChipWithinTheThreeTheyShow(): void
    {
        foreach (['en', 'ar'] as $locale) {
            foreach (DiscoveryPlanner::SLOTS as $slot) {
                $chips = ConciergeCopy::questionChips($slot, $locale, true);
                self::assertCount(3, $chips, "{$locale}/{$slot}");
                self::assertSame(ConciergeCopy::showNowChip($locale), $chips[2]);
            }
        }

        $ai = new GuidedAiProvider();
        $ai->enabled = false;
        $repo = $this->createMock(ProductRepository::class);
        $r = $this->service($ai, $repo)->ask('abaya', 'en', 12, [], null);
        self::assertSame(['For me', 'A gift', 'Show me what you have'], $r->followUps);

        // ...on every guided question of the legacy flow (occasion has 6 answers).
        $r = $this->service($ai, $repo)->ask('For me', 'en', 12, [
            ['role' => 'user', 'text' => 'abaya'],
            ['role' => 'ain', 'text' => ConciergeCopy::question('recipient', 'en')],
        ], null);
        self::assertSame('occasion', $r->questionSlot);
        self::assertSame(['Eid', 'Wedding', 'Show me what you have'], $r->followUps);

        // New clients (asked_slots sent) keep the full set.
        $r = $this->service($ai, $repo)->ask('abaya', 'en', 12, [], []);
        self::assertSame(ConciergeCopy::questionChips('recipient', 'en'), $r->followUps);
    }

    #[Test]
    public function anOpeningGreetingGoesStraightIntoTheFirstQuestion(): void
    {
        $ai = new GuidedAiProvider();
        $ai->enabled = false;
        $repo = $this->createMock(ProductRepository::class);
        $repo->expects(self::never())->method('findActivePaginated');
        $svc = $this->service($ai, $repo);

        // Small talk before any question starts the questions too — never a
        // second greeting with preset starter chips.
        $r = $svc->ask('hello', 'en', 12, [], []);
        self::assertSame('recipient', $r->questionSlot);
        self::assertSame(ConciergeCopy::question('recipient', 'en'), $r->reply);
        self::assertSame(ConciergeCopy::questionChips('recipient', 'en'), $r->followUps);
        self::assertSame(['recipient'], $r->askedSlots);
        $r = $svc->ask('For me', 'en', 12, [['role' => 'user', 'text' => 'hello'], ['role' => 'ain', 'text' => $r->reply]], $r->askedSlots);
        self::assertSame('occasion', $r->questionSlot);

        // Opening turn (the greeting is already in the reply): the first question now.
        $r = $svc->ask('مرحبا', 'ar', 12, [], [], false, true);
        self::assertSame('recipient', $r->questionSlot);
        self::assertSame(ConciergeCopy::question('recipient', 'ar'), $r->reply);
        self::assertSame(ConciergeCopy::questionChips('recipient', 'ar'), $r->followUps);

        // The opening line is localized, warm, and recognised as a greeting.
        self::assertStringStartsWith("Hi, I'm Ain, your personal stylist at 3bayti.", ConciergeCopy::opening('en'));
        self::assertStringStartsWith('أهلاً، أنا عين', ConciergeCopy::opening('ar'));
        self::assertTrue(ConciergeCopy::isGreetingReply(ConciergeCopy::opening('ar')));
        self::assertNull(ConciergeCopy::questionSlotFor(ConciergeCopy::opening('en')));
    }

    #[Test]
    public function modelFlaggedSmallTalkAtTheStartStartsTheQuestionsAndNeverSkipsThem(): void
    {
        // Planner: small talk before any question asks the first slot.
        $d = $this->plan(ConciergeIntent::keywordFallback('What can you do?'), 'What can you do?', [], [], false, true);
        self::assertSame(DiscoveryDecision::ACTION_ASK, $d->action);
        self::assertSame('recipient', $d->questionSlot);
        self::assertTrue($d->fromSmallTalk);
        self::assertSame(['recipient'], $d->askedSlots);

        // Service, AI on: "What can you do?" is not on the fixed small-talk list.
        $ai = new GuidedAiProvider();
        $ai->queue = [
            [
                'keywords' => [], 'is_gift' => false,
                'reply' => "I'm Ain, I help you find the perfect pieces. What are you looking for?",
                'follow_ups' => ['An abaya for Eid'], 'needs_clarification' => false, 'small_talk' => true,
            ],
            [
                'keywords' => ['abaya'], 'product_type' => 'abaya', 'is_gift' => true,
                'reply' => 'Let me look for an abaya for your sister.', 'follow_ups' => [],
                'needs_clarification' => false, 'small_talk' => false,
            ],
        ];
        $repo = $this->createMock(ProductRepository::class);
        $repo->expects(self::never())->method('findActivePaginated');
        $svc = $this->service($ai, $repo);

        $r = $svc->ask('What can you do?', 'en', 12, [], []);
        self::assertSame("I'm Ain, I help you find the perfect pieces. " . ConciergeCopy::question('recipient', 'en'), $r->reply);
        self::assertSame(ConciergeCopy::questionChips('recipient', 'en'), $r->followUps);
        self::assertSame(['recipient'], $r->askedSlots, 'never an empty echo after small talk');

        // The real request now continues the questions — no products yet.
        $r = $svc->ask('an abaya for my sister', 'en', 12, [
            ['role' => 'user', 'text' => 'What can you do?'],
            ['role' => 'ain', 'text' => $r->reply],
        ], $r->askedSlots);
        self::assertSame([], $r->items);
        self::assertSame('occasion', $r->questionSlot);
    }

    #[Test]
    public function anOpeningConversationNeverLeadsWithASecondGreeting(): void
    {
        $smallTalk = [
            'keywords' => [], 'is_gift' => false, 'reply' => "Hello! I'm Ain, so happy you're here.",
            'follow_ups' => ['An abaya for Eid'], 'needs_clarification' => false, 'small_talk' => true,
        ];
        $repo = $this->createMock(ProductRepository::class);

        // Already greeted (local opening bubble / WhatsApp prefix): the bare question.
        $ai = new GuidedAiProvider();
        $ai->queue = [$smallTalk];
        $r = $this->service($ai, $repo)->ask('hi', 'en', 12, [], [], false, true);
        self::assertSame(ConciergeCopy::question('recipient', 'en'), $r->reply);
        self::assertSame(ConciergeCopy::questionChips('recipient', 'en'), $r->followUps);

        // Not greeted yet (legacy client): the friendly line, then the question.
        $ai = new GuidedAiProvider();
        $ai->queue = [$smallTalk];
        $r = $this->service($ai, $repo)->ask('hi', 'en', 12, [], null);
        self::assertSame("Hello! I'm Ain, so happy you're here. " . ConciergeCopy::question('recipient', 'en'), $r->reply);
        self::assertSame('recipient', $r->questionSlot);
    }

    #[Test]
    public function smallTalkAtTheSixthQuestionStillLeadsToTheFirstResults(): void
    {
        $history = [['role' => 'user', 'text' => 'help me']];
        $answers = ['For me', 'Eid', 'Abaya', 'Pastels', 'Minimal'];
        foreach (DiscoveryPlanner::SLOTS as $i => $slot) {
            $history[] = ['role' => 'ain', 'text' => ConciergeCopy::question($slot, 'en')];
            if (isset($answers[$i])) {
                $history[] = ['role' => 'user', 'text' => $answers[$i]];
            }
        }
        // Small talk at the 6th (budget) question: the friendly line + the question.
        $history[] = ['role' => 'user', 'text' => "haha you're sweet"];
        $history[] = ['role' => 'ain', 'text' => ConciergeCopy::withQuestion('Aw, thank you!', ConciergeCopy::question('budget', 'en'))];

        $d = $this->plan(ConciergeIntent::keywordFallback('Under 300 AED'), 'Under 300 AED', $history, DiscoveryPlanner::SLOTS);
        self::assertSame(DiscoveryDecision::ACTION_RESULTS, $d->action);
        self::assertFalse($d->completeBefore, 'the first results, not a refinement');

        // Service, AI on: the model's clarifying question is NOT honoured here.
        $ai = new GuidedAiProvider();
        $ai->queue = [[
            'keywords' => ['abaya'], 'product_type' => 'abaya', 'is_gift' => false,
            'reply' => 'Which shade of pastel?', 'follow_ups' => [],
            'needs_clarification' => true, 'small_talk' => false,
        ]];
        $r = $this->service($ai, $this->repoReturning([$this->product(1, 'Pastel Abaya')]))
            ->ask('Under 300 AED', 'en', 12, $history, DiscoveryPlanner::SLOTS);
        self::assertNotSame([], $r->items);
        self::assertNull($r->questionSlot);
    }

    #[Test]
    public function aFiveSlotGuidedClientKeepsItsFourQuestionCap(): void
    {
        $history = [
            ['role' => 'user', 'text' => 'help me'],
            ['role' => 'ain', 'text' => ConciergeCopy::question('recipient', 'en')],
            ['role' => 'user', 'text' => 'For me'],
            ['role' => 'ain', 'text' => ConciergeCopy::question('occasion', 'en')],
            ['role' => 'user', 'text' => 'Eid'],
            ['role' => 'ain', 'text' => ConciergeCopy::question('type', 'en')],
            ['role' => 'user', 'text' => 'No preference'],
            ['role' => 'ain', 'text' => ConciergeCopy::question('colour', 'en')],
        ];
        // An installed pre-six-slot client drops 'colour' from its echo.
        $d = $this->plan(ConciergeIntent::keywordFallback('Pastels'), 'Pastels', $history, ['recipient', 'occasion', 'type']);
        self::assertSame(DiscoveryDecision::ACTION_RESULTS, $d->action);

        // A six-slot client echoes it and gets the remaining questions.
        $d = $this->plan(ConciergeIntent::keywordFallback('Pastels'), 'Pastels', $history, ['recipient', 'occasion', 'type', 'colour']);
        self::assertSame('style', $d->questionSlot);
    }

    #[Test]
    public function aMinimumBudgetNeverGetsAContradictoryUnder300Chip(): void
    {
        $intent = new ConciergeIntent('abaya', null, [], [], [], 700.0, null, [], [], false, 0.0);
        foreach (['en', 'ar'] as $locale) {
            $chips = ConciergeCopy::refineChips($intent, $locale);
            self::assertNotContains($locale === 'en' ? 'Under 300 AED' : 'أقل من 300 درهم', $chips);
            self::assertContains($locale === 'en' ? 'Similar but cheaper' : 'مشابه لكن أرخص', $chips);
        }
    }

    #[Test]
    public function aThreadRestoredWithoutItsAskedSlotsDoesNotRestartTheQuestions(): void
    {
        $history = [
            ['role' => 'user', 'text' => 'help me'],
            ['role' => 'ain', 'text' => ConciergeCopy::question('recipient', 'en')],
            ['role' => 'user', 'text' => 'Show me what you have'],
            ['role' => 'ain', 'text' => ConciergeCopy::reply(ConciergeCopy::KIND_PICKS, 'en')],
        ];
        $d = $this->plan(ConciergeIntent::keywordFallback('in navy'), 'in navy', $history, []);
        self::assertSame(DiscoveryDecision::ACTION_RESULTS, $d->action);
        self::assertTrue($d->completeBefore);

        // Mid-discovery with [] still continues from the inferred questions.
        $d = $this->plan(ConciergeIntent::keywordFallback('For me'), 'For me', array_slice($history, 0, 2), []);
        self::assertSame('occasion', $d->questionSlot);

        // A greeting reply is not "results shown".
        $d = $this->plan(ConciergeIntent::keywordFallback('abaya'), 'abaya', [
            ['role' => 'user', 'text' => 'how is your day going'],
            ['role' => 'ain', 'text' => ConciergeCopy::reply(ConciergeCopy::KIND_GREETING, 'en')],
        ], []);
        self::assertSame('recipient', $d->questionSlot);
    }

    /**
     * Plays the messages as a new client (echoing asked_slots).
     *
     * @param list<string> $messages
     * @return array{0: list<array{role: string, text: string}>, 1: list<string>, 2: \Bayti\Api\Ai\Concierge\ConciergeResult}
     */
    private function converse(ConciergeService $svc, array $messages): array
    {
        $history = [];
        $asked = [];
        $r = null;
        foreach ($messages as $msg) {
            $r = $svc->ask($msg, 'en', 12, $history, $asked);
            $history[] = ['role' => 'user', 'text' => $msg];
            $history[] = ['role' => 'ain', 'text' => $r->reply];
            $asked = $r->askedSlots;
        }
        self::assertNotNull($r);
        return [$history, $asked, $r];
    }

    // ===== fixtures =====

    /**
     * @param list<array{role: string, text: string}> $history
     * @param list<string>|null $asked
     */
    private function plan(ConciergeIntent $intent, string $query, array $history, ?array $asked, bool $showNow = false, bool $smallTalk = false): DiscoveryDecision
    {
        return DiscoveryPlanner::plan($intent, $query, ConversationHistory::fromMixed($history), $asked, $showNow, $smallTalk);
    }

    private function service(GuidedAiProvider $ai, ProductRepository $repo): ConciergeService
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            static fn (string $class) => $class === Product::class ? $repo : null,
        );
        $store = new \Bayti\Api\Ai\Enrichment\ProductAiAttributesStore($this->createMock(\Doctrine\DBAL\Connection::class));

        return new ConciergeService(new IntentParser($ai), new ProductRetrievalService($em, $ai, $store), new ConciergeRanker($ai));
    }

    /** @param list<Product> $items */
    private function repoReturning(array $items): ProductRepository
    {
        $repo = $this->createMock(ProductRepository::class);
        $repo->method('findActivePaginated')->willReturn(['items' => $items, 'total' => count($items)]);
        return $repo;
    }

    private function product(int $id, string $name): Product
    {
        $v = new Vendor("vendor-{$id}", "Vendor {$id}", "v{$id}@example.test");
        $v->approve();
        $p = new Product(vendor: $v, slug: "p-{$id}", name: $name);
        $p->setStatus('active');
        $p->setPrice('100.00');
        $ref = new \ReflectionProperty(Product::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($p, $id);
        return $p;
    }
}
