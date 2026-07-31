<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Model\L10nAccumulatedInformation;
use Localizationteam\L10nmgr\Model\L10nConfiguration;
use Localizationteam\L10nmgr\Services\TranslationDetailsService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers load() and updateFlexFormDiff(), the two L10nConfiguration methods needing only a real
 * database, plus getL10nAccumulatedInformationsObjectForLanguage()'s non-recursive
 * (depth=0/single starting page) branch - the real tree-walking (depth>0) is PageTreeView core
 * behavior, not this class's own logic. The bulk of what the factory hands off to
 * (L10nAccumulatedInformation's own filtering/counting) is covered directly, with a manually
 * built tree, in L10nAccumulatedInformationTest.php.
 */
class L10nConfigurationDatabaseTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['scheduler'];

    protected array $testExtensionsToLoad = ['localizationteam/l10nmgr'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tx_l10nmgr_cfg.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_translations.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
        // TranslationDetailsService caches system languages on a process-wide static property -
        // reset it so a test method resolves languages fresh against the site config it writes.
        TranslationDetailsService::$systemLanguages = [];
    }

    protected function tearDown(): void
    {
        // The functional test instance directory (including config/sites/) is reused across all
        // test methods in this class - remove what a test wrote so the next method starts clean.
        $sitesPath = Environment::getConfigPath() . '/sites/';
        if (is_dir($sitesPath)) {
            GeneralUtility::rmdir($sitesPath, true);
        }
        $this->get(CacheManager::class)->getCache('core')->remove('sites-configuration');
        parent::tearDown();
    }

    private function writeSite(): void
    {
        $siteConfigPath = Environment::getConfigPath() . '/sites/test-site/';
        GeneralUtility::mkdir_deep($siteConfigPath);
        GeneralUtility::writeFile($siteConfigPath . 'config.yaml', <<<'YAML'
rootPageId: 1
base: 'https://example.com/'
languages:
  0:
    title: English
    enabled: true
    languageId: 0
    base: '/'
    typo3Language: default
    locale: en_US.UTF-8
    iso-639-1: en
    navigationTitle: English
    hreflang: en-US
    direction: ltr
    flag: us
YAML);
        $this->get(CacheManager::class)->getCache('core')->remove('sites-configuration');
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
    public function updateFlexFormDiffCalledTwiceOnTheSameInstanceMergesRatherThanClobbering(): void
    {
        // updateFlexFormDiff() now writes the merged array back onto $this->l10ncfg, so a second
        // call on the SAME object instance sees the first call's data in memory and merges with it,
        // matching the behavior of calling it on two separately load()-ed instances (see above).
        $subject = new L10nConfiguration();
        $subject->load(1);

        $subject->updateFlexFormDiff(1, ['field.a' => 'diff-a']);
        $subject->updateFlexFormDiff(1, ['field.b' => 'diff-b']);

        $reloaded = new L10nConfiguration();
        $reloaded->load(1);
        $persisted = unserialize($reloaded->l10ncfg['flexformdiff'], ['allowed_classes' => false]);
        self::assertSame(['field.a' => 'diff-a', 'field.b' => 'diff-b'], $persisted[1]);
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

    #[Test]
    public function getL10nAccumulatedInformationsObjectForLanguageReturnsAnAccumulatedInformationObject(): void
    {
        // tx_l10nmgr_cfg.csv's fixture row has no 'depth', so this exercises the (int)(... ?? 0)
        // default - the non-recursive, single-starting-page branch.
        $this->writeSite();
        $subject = new L10nConfiguration();
        $subject->load(1);

        $accumObj = $subject->getL10nAccumulatedInformationsObjectForLanguage(1);

        self::assertInstanceOf(L10nAccumulatedInformation::class, $accumObj);
    }

    #[Test]
    public function getL10nAccumulatedInformationsObjectForLanguageBuildsATreeContainingOnlyTheConfiguredPage(): void
    {
        // tx_l10nmgr_cfg.csv's fixture row has pid=1 and depth=0 (default), so the built tree
        // must contain exactly page 1 - not its subpage (uid=2 in pages.csv).
        $this->writeSite();
        $subject = new L10nConfiguration();
        $subject->load(1);

        $accumObj = $subject->getL10nAccumulatedInformationsObjectForLanguage(1);
        $result = $accumObj->getInfoArray();

        self::assertArrayHasKey(1, $result);
        self::assertArrayNotHasKey(2, $result);
        self::assertArrayHasKey(10, $result[1]['items']['tt_content'] ?? []);
    }
}
