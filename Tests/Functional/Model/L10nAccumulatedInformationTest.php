<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Constants;
use Localizationteam\L10nmgr\Model\L10nAccumulatedInformation;
use Localizationteam\L10nmgr\Services\TranslationDetailsService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Tree\View\PageTreeView;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers _calculateInternalAccumulatedInformationsArray() (via getInfoArray()), the shared
 * page-tree/exclude/include filtering pipeline that ExcelXmlView/CatXmlView/L10nHtmlListView's
 * render() and ConfigurationModuleController route through. The tree is built manually (setting
 * PageTreeView::$tree directly, exactly like L10nConfiguration::getL10nAccumulatedInformationsObjectForLanguage()
 * itself does for its non-recursive/single-page branch) rather than exercising the real tree-walking
 * factory - the shallower factory-level coverage for that lives in L10nConfigurationDatabaseTest.php.
 */
class L10nAccumulatedInformationTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['scheduler'];

    protected array $testExtensionsToLoad = ['localizationteam/l10nmgr'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_translations.csv');
        $this->setUpBackendUser(1);
        // TranslationDetailsService caches system languages on a process-wide static property -
        // reset it so each test method resolves languages fresh against the site config it writes.
        TranslationDetailsService::$systemLanguages = [];
        $this->writeSite();
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
  1:
    title: German
    enabled: true
    languageId: 1
    base: '/de/'
    typo3Language: de
    locale: de_DE.UTF-8
    iso-639-1: de
    navigationTitle: Deutsch
    hreflang: de-DE
    direction: ltr
    flag: de
YAML);
        $this->get(CacheManager::class)->getCache('core')->remove('sites-configuration');
    }

    /**
     * @param array<int, array{row: array<string, mixed>, HTML?: string}> $rows
     */
    private function buildTree(array $rows): PageTreeView
    {
        $tree = new PageTreeView();
        $tree->tree = $rows;
        return $tree;
    }

    private function pageRow(int $uid, array $overrides = []): array
    {
        return array_replace([
            'uid' => $uid,
            'pid' => 0,
            'title' => 'Page ' . $uid,
            'doktype' => 1,
        ], $overrides);
    }

    #[Test]
    public function getInfoArrayIncludesTheDefaultLanguageRecordUnderTheRequestedTargetLanguage(): void
    {
        $tree = $this->buildTree([['row' => $this->pageRow(1), 'HTML' => '']]);
        $l10ncfg = ['pid' => 1, 'tablelist' => 'tt_content', 'exclude' => '', 'include' => ''];
        $subject = new L10nAccumulatedInformation($tree, $l10ncfg, 1);

        $result = $subject->getInfoArray();

        self::assertArrayHasKey('tt_content', $result[1]['items'] ?? []);
        self::assertArrayHasKey(10, $result[1]['items']['tt_content']);
    }

    #[Test]
    public function getInfoArrayDoesNotIncludeTranslationRecordsOnlyTheirDefaultLanguageSource(): void
    {
        // uid=11 is the German translation of uid=10 (sys_language_uid=1, l18n_parent=10) -
        // getRecordsToTranslateFromTable() only selects default-language records to translate from.
        $tree = $this->buildTree([['row' => $this->pageRow(1), 'HTML' => '']]);
        $l10ncfg = ['pid' => 1, 'tablelist' => 'tt_content', 'exclude' => '', 'include' => ''];
        $subject = new L10nAccumulatedInformation($tree, $l10ncfg, 1);

        $result = $subject->getInfoArray();

        self::assertArrayNotHasKey(11, $result[1]['items']['tt_content']);
    }

    #[Test]
    public function getInfoArraySkipsPagesWithADisallowedDoktype(): void
    {
        // 255 = Recycler, in EmConfiguration's default disallowDoktypes list ("255, ---div---").
        $tree = $this->buildTree([['row' => $this->pageRow(999, ['doktype' => 255]), 'HTML' => '']]);
        $l10ncfg = ['pid' => 1, 'tablelist' => 'tt_content', 'exclude' => '', 'include' => ''];
        $subject = new L10nAccumulatedInformation($tree, $l10ncfg, 1);

        $result = $subject->getInfoArray();

        self::assertArrayNotHasKey(999, $result);
    }

    #[Test]
    public function getInfoArraySkipsPagesExplicitlyMarkedAsExcluded(): void
    {
        $tree = $this->buildTree([
            ['row' => $this->pageRow(1, ['l10nmgr_configuration' => Constants::L10NMGR_CONFIGURATION_EXCLUDE]), 'HTML' => ''],
        ]);
        $l10ncfg = ['pid' => 1, 'tablelist' => 'tt_content', 'exclude' => '', 'include' => ''];
        $subject = new L10nAccumulatedInformation($tree, $l10ncfg, 1);

        $result = $subject->getInfoArray();

        self::assertArrayNotHasKey(1, $result);
    }

    #[Test]
    public function getInfoArraySkipsPagesRestrictedForTheRequestedLanguage(): void
    {
        $tree = $this->buildTree([
            ['row' => $this->pageRow(1, [Constants::L10NMGR_LANGUAGE_RESTRICTION_FIELDNAME => '1']), 'HTML' => ''],
        ]);
        $l10ncfg = ['pid' => 1, 'tablelist' => 'tt_content', 'exclude' => '', 'include' => ''];
        $subject = new L10nAccumulatedInformation($tree, $l10ncfg, 1);

        $result = $subject->getInfoArray();

        self::assertArrayNotHasKey(1, $result);
    }

    #[Test]
    public function getInfoArrayDoesNotRestrictAPageForALanguageNotInItsRestrictionList(): void
    {
        // Restriction only lists language 2 - language 1 (the one we're indexing for) must pass through.
        $tree = $this->buildTree([
            ['row' => $this->pageRow(1, [Constants::L10NMGR_LANGUAGE_RESTRICTION_FIELDNAME => '2']), 'HTML' => ''],
        ]);
        $l10ncfg = ['pid' => 1, 'tablelist' => 'tt_content', 'exclude' => '', 'include' => ''];
        $subject = new L10nAccumulatedInformation($tree, $l10ncfg, 1);

        $result = $subject->getInfoArray();

        self::assertArrayHasKey(1, $result);
    }

    #[Test]
    public function getInfoArrayIncludesThePageOwningAnExcludeSubpagesSettingButExcludesItsSubpages(): void
    {
        // uid=1 is the root page, uid=2 is its subpage (pages.csv). Setting
        // l10nmgr_configuration_next_level=EXCLUDE on page 1 means "exclude subpages of page 1" -
        // page 1 itself must stay included, only page 2 must be excluded.
        $this->getConnectionPool()->getConnectionForTable('pages')->update(
            'pages',
            ['l10nmgr_configuration_next_level' => Constants::L10NMGR_CONFIGURATION_EXCLUDE],
            ['uid' => 1]
        );
        $tree = $this->buildTree([
            ['row' => $this->pageRow(1, ['l10nmgr_configuration' => Constants::L10NMGR_CONFIGURATION_DEFAULT]), 'HTML' => ''],
            ['row' => $this->pageRow(2, ['pid' => 1, 'l10nmgr_configuration' => Constants::L10NMGR_CONFIGURATION_DEFAULT]), 'HTML' => ''],
        ]);
        $l10ncfg = ['pid' => 1, 'tablelist' => 'tt_content', 'exclude' => '', 'include' => ''];
        $subject = new L10nAccumulatedInformation($tree, $l10ncfg, 1);

        $result = $subject->getInfoArray();

        self::assertArrayHasKey(1, $result, 'the page owning the "exclude subpages" setting must stay included');
        self::assertArrayNotHasKey(2, $result, 'its subpage must be excluded');
    }

    #[Test]
    public function getInfoArrayHonoursTheExcludeListForIndividualRecords(): void
    {
        $tree = $this->buildTree([['row' => $this->pageRow(1), 'HTML' => '']]);
        $l10ncfg = ['pid' => 1, 'tablelist' => 'tt_content', 'exclude' => 'tt_content:10', 'include' => ''];
        $subject = new L10nAccumulatedInformation($tree, $l10ncfg, 1);

        $result = $subject->getInfoArray();

        self::assertArrayNotHasKey(10, $result[1]['items']['tt_content'] ?? []);
    }

    #[Test]
    public function getFieldCountAndGetWordCountAreZeroBeforeGetInfoArrayIsCalled(): void
    {
        $tree = $this->buildTree([]);
        $l10ncfg = ['pid' => 1, 'tablelist' => 'tt_content', 'exclude' => '', 'include' => ''];
        $subject = new L10nAccumulatedInformation($tree, $l10ncfg, 1);

        self::assertSame(0, $subject->getFieldCount());
        self::assertSame(0, $subject->getWordCount());
    }

    #[Test]
    public function getInfoArrayIncreasesFieldAndWordCountsForProcessedContent(): void
    {
        $tree = $this->buildTree([['row' => $this->pageRow(1), 'HTML' => '']]);
        $l10ncfg = ['pid' => 1, 'tablelist' => 'tt_content', 'exclude' => '', 'include' => ''];
        $subject = new L10nAccumulatedInformation($tree, $l10ncfg, 1);

        $subject->getInfoArray();

        self::assertGreaterThan(0, $subject->getFieldCount());
        self::assertGreaterThan(0, $subject->getWordCount());
    }

    #[Test]
    public function getInfoArrayIsIdempotentOnASecondCall(): void
    {
        $tree = $this->buildTree([['row' => $this->pageRow(1), 'HTML' => '']]);
        $l10ncfg = ['pid' => 1, 'tablelist' => 'tt_content', 'exclude' => '', 'include' => ''];
        $subject = new L10nAccumulatedInformation($tree, $l10ncfg, 1);

        $subject->getInfoArray();
        $fieldCountAfterFirstCall = $subject->getFieldCount();
        $wordCountAfterFirstCall = $subject->getWordCount();
        $subject->getInfoArray();

        self::assertGreaterThan(0, $fieldCountAfterFirstCall);
        self::assertSame($fieldCountAfterFirstCall, $subject->getFieldCount(), 'a second call must not double the field count');
        self::assertSame($wordCountAfterFirstCall, $subject->getWordCount(), 'a second call must not double the word count');
    }
}
