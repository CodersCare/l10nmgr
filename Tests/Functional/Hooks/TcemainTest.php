<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Hooks\Tcemain;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers siteRelPath() (needs the extension registered as "loaded", which
 * ExtensionManagementUtility::extPath() only sees in a functional bootstrap), calcStat() (needs
 * a real tx_l10nmgr_index table plus the backend user's workspace), and
 * processDatamap_afterDatabaseOperations() (needs a real site configuration plus the pages/
 * tt_content_translations fixtures - editing a default-language record must reindex every target
 * language, not just language 0).
 */
class TcemainTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['scheduler'];

    protected array $testExtensionsToLoad = ['localizationteam/l10nmgr'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tx_l10nmgr_index.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_translations.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['BE_USER']->workspace = 0;
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

    /**
     * Writes a site with rootPageId=1 (matching pages.csv's root page) and two languages, so
     * TranslationDetailsService::setSiteLanguagesByPid() resolves a real SiteLanguage[] rather
     * than falling back to useSystemLanguages().
     */
    private function writeTwoLanguageSite(): void
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
     * @return array<int, array<string, mixed>>
     */
    private function fetchIndexRows(string $table, int $recuid): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_l10nmgr_index');
        return $queryBuilder->select('*')
            ->from('tx_l10nmgr_index')
            ->where(
                $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->eq('recuid', $queryBuilder->createNamedParameter($recuid, \TYPO3\CMS\Core\Database\Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }

    #[Test]
    public function siteRelPathReturnsAPathRelativeToThePublicWebRoot(): void
    {
        $subject = new Tcemain();
        $method = new \ReflectionMethod($subject, 'siteRelPath');

        $result = $method->invoke($subject, 'l10nmgr');

        self::assertStringContainsString('l10nmgr', $result);
        self::assertStringStartsNotWith('/', $result);
    }

    #[Test]
    public function calcStatReturnsEmptyStringWhenNoIndexRecordsMatch(): void
    {
        $subject = new Tcemain();

        $result = $subject->calcStat(['tt_content', 999999], [1]);

        self::assertSame('', $result);
    }

    #[Test]
    public function calcStatReportsAllNewWhenOnlyTheNewFlagIsSet(): void
    {
        $subject = new Tcemain();

        $result = $subject->calcStat(['tt_content', 5], [1]);

        self::assertStringContainsString('flags_new.png', $result);
    }

    #[Test]
    public function calcStatReportsUpdateWhenTheUpdateFlagIsSet(): void
    {
        $subject = new Tcemain();

        $result = $subject->calcStat(['tt_content', 6], [1]);

        self::assertStringContainsString('flags_update.png', $result);
    }

    #[Test]
    public function calcStatReportsUnknownWhenTheUnknownFlagIsSet(): void
    {
        $subject = new Tcemain();

        $result = $subject->calcStat(['tt_content', 7], [1]);

        self::assertStringContainsString('flags_unknown.png', $result);
    }

    #[Test]
    public function calcStatReportsOkWhenOnlyTheNoChangeFlagIsSet(): void
    {
        $subject = new Tcemain();

        $result = $subject->calcStat(['tt_content', 8], [1]);

        self::assertStringContainsString('flags_ok.png', $result);
    }

    #[Test]
    public function calcStatIgnoresRecordsFromADifferentWorkspace(): void
    {
        $subject = new Tcemain();

        $result = $subject->calcStat(['tt_content', 5], [1]);

        self::assertStringContainsString('flags_new.png', $result);
        self::assertStringContainsString('None of 1 elements are translated.', $result, 'workspace=3 row must not be counted');
    }

    #[Test]
    public function calcStatUsesRecpidLookupForThePagesTable(): void
    {
        // recpid=2 is deliberately unique to the pages-table fixture row (hash-pages) in
        // tx_l10nmgr_index.csv - the other rows all share recpid=1, which the "pages" branch would
        // also match, see the finding below.
        $subject = new Tcemain();

        $result = $subject->calcStat(['pages', 2], [1]);

        self::assertStringContainsString('flags_ok.png', $result);
    }

    #[Test]
    public function calcStatForPagesDoesNotLeakRowsFromOtherTablesSharingTheSameRecpid(): void
    {
        // recpid=1 is shared by 4 non-pages fixture rows (new/update/unknown/noChange all on
        // tt_content). Asking for calcStat(['pages', 1], [1]) must not pick any of them up - the
        // "pages" branch now filters by tablename='pages' AND recpid, matching the non-pages
        // branch's tablename+recuid filtering pattern.
        $subject = new Tcemain();

        $result = $subject->calcStat(['pages', 1], [1]);

        self::assertSame('', $result, 'no pages-table row has recpid=1, so nothing should match');
    }

    #[Test]
    public function calcStatWithNoLinkReturnsTheBareImageTagWithoutAnAnchor(): void
    {
        $subject = new Tcemain();

        $result = $subject->calcStat(['tt_content', 5], [1], true);

        self::assertStringNotContainsString('<a href', $result);
        self::assertStringContainsString('<img', $result);
    }

    #[Test]
    public function calcStatWithoutNoLinkWrapsTheImageInAnAnchor(): void
    {
        $subject = new Tcemain();

        $result = $subject->calcStat(['tt_content', 5], [1], false);

        self::assertStringContainsString('<a href', $result);
    }

    #[Test]
    public function processDatamapAfterDatabaseOperationsReindexesEveryLanguageWhenEditingTheDefaultLanguageRecord(): void
    {
        // uid=10 is the default-language (sys_language_uid=0) parent record in
        // tt_content_translations.csv. Editing it must pass languageID=null into
        // indexDetailsRecord() so every site language gets reindexed, not just language 0.
        $this->writeTwoLanguageSite();
        $subject = new Tcemain();
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);

        $subject->processDatamap_afterDatabaseOperations('update', 'tt_content', '10', [], $dataHandler);

        $rows = $this->fetchIndexRows('tt_content', 10);
        $languages = array_column($rows, 'translation_lang');
        self::assertContains(0, $languages);
        self::assertContains(1, $languages);
    }

    #[Test]
    public function processDatamapAfterDatabaseOperationsOnlyReindexesTheEditedLanguageWhenEditingATranslationRecord(): void
    {
        // uid=11 is the German translation (sys_language_uid=1, l18n_parent=10). Editing it
        // re-points $liveRecord to the default-language root (uid=10) but must keep languageID=1,
        // so only that one language's index row gets recomputed.
        $this->writeTwoLanguageSite();
        $subject = new Tcemain();
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);

        $subject->processDatamap_afterDatabaseOperations('update', 'tt_content', '11', [], $dataHandler);

        $rows = $this->fetchIndexRows('tt_content', 10);
        self::assertCount(1, $rows);
        self::assertSame(1, (int)$rows[0]['translation_lang']);
    }

    #[Test]
    public function processDatamapAfterDatabaseOperationsMapsNewRecordIdsUsingSubstNEWwithIDs(): void
    {
        $this->writeTwoLanguageSite();
        $subject = new Tcemain();
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->substNEWwithIDs = ['NEWabc123' => 10];

        $subject->processDatamap_afterDatabaseOperations('new', 'tt_content', 'NEWabc123', [], $dataHandler);

        $rows = $this->fetchIndexRows('tt_content', 10);
        self::assertNotEmpty($rows, 'the "NEW..." id should have been mapped to uid 10 via substNEWwithIDs');
    }

    #[Test]
    public function processDatamapAfterDatabaseOperationsDoesNothingWhenNoLiveRecordCanBeFound(): void
    {
        $subject = new Tcemain();
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);

        $subject->processDatamap_afterDatabaseOperations('update', 'tt_content', '999999', [], $dataHandler);

        self::assertSame([], $this->fetchIndexRows('tt_content', 999999));
    }

    #[Test]
    public function processDatamapAfterDatabaseOperationsReturnsEarlyForTablesWithoutATranslationPointerField(): void
    {
        // be_users has no 'transOrigPointerField' configured in TCA, so the hook must bail out
        // via the `!isset($GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'])` guard.
        $subject = new Tcemain();
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);

        $subject->processDatamap_afterDatabaseOperations('update', 'be_users', '1', [], $dataHandler);

        self::assertSame([], $this->fetchIndexRows('be_users', 1));
    }

    #[Test]
    public function processDatamapAfterDatabaseOperationsIndexesThePageItselfWhenEditingAPageRecord(): void
    {
        $this->writeTwoLanguageSite();
        $subject = new Tcemain();
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);

        $subject->processDatamap_afterDatabaseOperations('update', 'pages', '2', [], $dataHandler);

        $rows = $this->fetchIndexRows('pages', 2);
        self::assertNotEmpty($rows);
    }
}
