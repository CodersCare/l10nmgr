<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Model\L10nConfiguration;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers load() and updateFlexFormDiff(), the two L10nConfiguration methods needing a real
 * database. getL10nAccumulatedInformationsObjectForLanguage() needs a full page-tree/backend-user
 * setup disproportionate to this batch and is deferred alongside the other heavy integration
 * classes (see backlog).
 */
class L10nConfigurationDatabaseTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['scheduler'];

    protected array $testExtensionsToLoad = ['localizationteam/l10nmgr'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tx_l10nmgr_cfg.csv');
    }

    #[Test]
    public function loadPopulatesL10ncfgFromTheDatabaseRecord(): void
    {
        $subject = new L10nConfiguration();

        $subject->load(1);

        self::assertTrue($subject->isLoaded());
        self::assertSame('Test Configuration', $subject->getTitle());
        self::assertSame('tt_content', $subject->getTableList());
    }

    #[Test]
    public function loadOnAnAlreadyLoadedInstanceIsANoOp(): void
    {
        // load() only assigns when $this->l10ncfg === [] - calling it again with a different id
        // must not overwrite already-loaded data.
        $subject = new L10nConfiguration();
        $subject->load(1);

        $subject->load(999999);

        self::assertSame('Test Configuration', $subject->getTitle());
    }

    #[Test]
    public function loadWithAnUnknownIdLeavesL10ncfgEmpty(): void
    {
        $subject = new L10nConfiguration();

        $subject->load(999999);

        self::assertFalse($subject->isLoaded());
    }

    #[Test]
    public function updateFlexFormDiffPersistsTheSerializedDiffForTheGivenLanguage(): void
    {
        $subject = new L10nConfiguration();
        $subject->load(1);

        $subject->updateFlexFormDiff(1, ['field.a' => 'diff-a']);

        $reloaded = new L10nConfiguration();
        $reloaded->load(1);
        $persisted = unserialize($reloaded->l10ncfg['flexformdiff'], ['allowed_classes' => false]);
        self::assertSame(['field.a' => 'diff-a'], $persisted[1]);
    }

    #[Test]
    public function updateFlexFormDiffMergesWithExistingDiffDataForTheSameLanguageAcrossSeparateLoads(): void
    {
        $first = new L10nConfiguration();
        $first->load(1);
        $first->updateFlexFormDiff(1, ['field.a' => 'diff-a']);

        // A fresh load() picks up the persisted diff from the first call, so a second update on a
        // *new* instance correctly merges rather than overwriting.
        $second = new L10nConfiguration();
        $second->load(1);
        $second->updateFlexFormDiff(1, ['field.b' => 'diff-b']);

        $reloaded = new L10nConfiguration();
        $reloaded->load(1);
        $persisted = unserialize($reloaded->l10ncfg['flexformdiff'], ['allowed_classes' => false]);
        self::assertSame(['field.a' => 'diff-a', 'field.b' => 'diff-b'], $persisted[1]);
    }

    #[Test]
    public function updateFlexFormDiffCalledTwiceOnTheSameInstanceDoesNotMergeBecauseInMemoryStateIsStale(): void
    {
        // Characterization of a real, currently-live quirk (found while writing this test, not
        // introduced by it - not fixed here, out of scope for a coverage pass): updateFlexFormDiff()
        // reads/writes a *local copy* of $this->l10ncfg and persists it to the database, but never
        // assigns the updated array back onto $this->l10ncfg. So a second call on the SAME object
        // instance starts from the original (pre-first-call) in-memory flexformdiff again, and its
        // DB write clobbers the first call's persisted data instead of merging with it - unlike
        // calling it on two separately load()-ed instances, which does merge correctly (see above).
        $subject = new L10nConfiguration();
        $subject->load(1);

        $subject->updateFlexFormDiff(1, ['field.a' => 'diff-a']);
        $subject->updateFlexFormDiff(1, ['field.b' => 'diff-b']);

        $reloaded = new L10nConfiguration();
        $reloaded->load(1);
        $persisted = unserialize($reloaded->l10ncfg['flexformdiff'], ['allowed_classes' => false]);
        self::assertSame(['field.b' => 'diff-b'], $persisted[1], 'field.a from the first call was clobbered, not merged');
    }

    #[Test]
    public function updateFlexFormDiffDoesNothingWhenL10ncfgHasNoUid(): void
    {
        $subject = new L10nConfiguration();

        $subject->updateFlexFormDiff(1, ['field.a' => 'diff-a']);

        $reloaded = new L10nConfiguration();
        $reloaded->load(1);
        self::assertSame('', $reloaded->l10ncfg['flexformdiff']);
    }
}
