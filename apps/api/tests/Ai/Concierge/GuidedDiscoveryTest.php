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
        self::assertSame('style_or_colour', $d->questionSlot);
        self::assertSame(['recipient', 'occasion', 'type', 'style_or_colour'], $d->askedSlots);
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
        self::assertSame('style_or_colour', $d->questionSlot);

        // isGift fills recipient.
        $gift = new ConciergeIntent(null, null, [], [], [], null, null, [], [], true, 0.9);
        self::assertSame('occasion', $this->plan($gift, 'a present', [], [])->questionSlot);

        // AI off: the lexicon recognises the words (Arabic too).
        $d = $this->plan(ConciergeIntent::keywordFallback('عباية للعيد'), 'عباية للعيد', [], []);
        self::assertSame(['occasion', 'type'], $d->filledSlots);
        self::assertSame('recipient', $d->questionSlot);
    }

    #[Test]
    public function anAskedSlotCountsAsFilledAndAtMostFourQuestionsAreAsked(): void
    {
        $asked = ['recipient', 'occasion', 'type', 'style_or_colour'];
        $d = $this->plan(ConciergeIntent::keywordFallback('No preference'), 'No preference', [], $asked);

        self::assertSame(DiscoveryDecision::ACTION_RESULTS, $d->action);
        self::assertNull($d->questionSlot);
        // Products shown => every slot marked (discovery complete).
        self::assertSame(DiscoveryPlanner::SLOTS, $d->askedSlots);
        self::assertTrue($d->isGuidedResults());
    }

    #[Test]
    public function allSlotsFilledShowsProducts(): void
    {
        $intent = new ConciergeIntent('abaya', null, ['eid'], ['black'], [], null, 500.0, ['abaya'], [], true, 0.9);
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
    public function aSpecificFirstMessageGoesStraightToResults(): void
    {
        $d = $this->plan(ConciergeIntent::keywordFallback('an elegant black abaya for a wedding'), 'an elegant black abaya for a wedding', [], []);
        self::assertSame(DiscoveryDecision::ACTION_RESULTS, $d->action);
        self::assertFalse($d->isGuidedResults());

        // Two slots only => still a question.
        $d = $this->plan(ConciergeIntent::keywordFallback('black abaya'), 'black abaya', [], []);
        self::assertSame(DiscoveryDecision::ACTION_ASK, $d->action);
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
        // History holds Ain's 4 question templates: the 4-question cap applies.
        $history = [];
        foreach (['recipient', 'occasion', 'type', 'style_or_colour'] as $i => $slot) {
            $history[] = ['role' => 'user', 'text' => $i === 0 ? 'help me' : 'No preference'];
            $history[] = ['role' => 'ain', 'text' => ConciergeCopy::question($slot, 'en')];
        }
        $d = $this->plan(ConciergeIntent::keywordFallback('No preference'), 'No preference', $history, null);
        self::assertSame(DiscoveryDecision::ACTION_RESULTS, $d->action);

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
        self::assertCount(5, DiscoveryPlanner::sanitizeAskedSlots([...DiscoveryPlanner::SLOTS, ...DiscoveryPlanner::SLOTS]) ?? []);
    }

    // ===== DiscoveryLexicon =====

    #[Test]
    public function everyPredefinedChipIsUnderstoodInBothLocales(): void
    {
        $expect = [
            'recipient' => static fn (array $x): bool => $x['recipient'] !== null,
            'occasion' => static fn (array $x): bool => $x['occasions'] !== [],
            'type' => static fn (array $x): bool => $x['type'] !== null,
            'style_or_colour' => static fn (array $x): bool => $x['colours'] !== [] || $x['styles'] !== [],
            'budget' => static fn (array $x): bool => $x['budget_min'] !== null || $x['budget_max'] !== null,
        ];
        foreach (['en', 'ar'] as $locale) {
            foreach (DiscoveryPlanner::SLOTS as $slot) {
                $chips = ConciergeCopy::questionChips($slot, $locale);
                foreach (array_slice($chips, 0, -2) as $chip) {
                    self::assertTrue($expect[$slot](DiscoveryLexicon::extract([$chip])), "{$locale}/{$slot}: {$chip}");
                    self::assertTrue(DiscoveryLexicon::isConsumed($chip), "{$locale}: {$chip} is chip text, not a search keyword");
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
        self::assertSame(['classic'], $e->styles);
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
        foreach (['abaya', 'For me', 'Eid', 'Classic black', 'Under 300 AED'] as $msg) {
            $r = $svc->ask($msg, 'en', 12, $history, $asked);
            $turns[] = $r;
            $history[] = ['role' => 'user', 'text' => $msg];
            $history[] = ['role' => 'ain', 'text' => $r->reply];
            $asked = $r->askedSlots;
        }

        // 'abaya' fills type: recipient → occasion → style → budget, then results.
        self::assertSame(['recipient', 'occasion', 'style_or_colour', 'budget', null], array_map(static fn ($r) => $r->questionSlot, $turns));
        foreach (array_slice($turns, 0, 4) as $q) {
            self::assertTrue($q->needsClarification);
            self::assertSame([], $q->items);
        }
        self::assertSame(ConciergeCopy::question('occasion', 'en'), $turns[1]->reply);
        self::assertSame(ConciergeCopy::questionChips('occasion', 'en'), $turns[1]->followUps);

        $final = $turns[4];
        self::assertFalse($final->needsClarification);
        self::assertSame([5], $final->productIds());
        self::assertSame(DiscoveryPlanner::SLOTS, $final->askedSlots);
        // Retrieval used the answers as real filters, not chip text as a search.
        self::assertSame('abaya', $filters[0]['searchQuery']);
        self::assertSame(['black'], $filters[0]['colors']);
        self::assertSame(300.0, $filters[0]['maxPrice']);
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

        [, , $last] = $this->converse($svc, ['something with pearls', 'For me', 'Eid', 'Abaya', 'Under 300 AED']);

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

        self::assertSame('You are welcome! Is it for you or a gift?', $r->reply);
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
