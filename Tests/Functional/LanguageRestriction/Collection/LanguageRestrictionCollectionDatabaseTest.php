<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\LanguageRestriction\Collection\LanguageRestrictionCollection;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers loadContents()/getCollectedRecords(), the actual database query, built directly via the
 * inherited create() factory (bypassing the static load() factory's own SiteFinder-based language
 * resolution, which is a separate, independently-testable concern - see loadResolvesLanguage...
 * below for that half). The pages table has the l10nmgr_language_restriction column available in
 * any functional test loading this extension, because l10nmgr's own
 * Configuration/TCA/Overrides/pages.php registers 'pages' as restrictable by default.
 */
class LanguageRestrictionCollectionDatabaseTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['scheduler'];

    protected array $testExtensionsToLoad = ['localizationteam/l10nmgr'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages_with_restrictions.csv');
    }

    protected function tearDown(): void
    {
        $sitesPath = Environment::getConfigPath() . '/sites/';
        if (is_dir($sitesPath)) {
            GeneralUtility::rmdir($sitesPath, true);
        }
        $this->get(CacheManager::class)->getCache('core')->remove('sites-configuration');
        parent::tearDown();
    }

    private function writeSiteConfiguration(int $rootPageId): void
    {
        $siteConfigPath = Environment::getConfigPath() . '/sites/test-site/';
        GeneralUtility::mkdir_deep($siteConfigPath);
        GeneralUtility::writeFile($siteConfigPath . 'config.yaml', <<<YAML
rootPageId: {$rootPageId}
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

    private function createCollectionForLanguage(int $languageId): LanguageRestrictionCollection
    {
        return LanguageRestrictionCollection::create([
            'uid' => $languageId,
            'title' => 'Language ' . $languageId,
            'description' => 'Restriction Collection',
            'table_name' => 'pages',
        ]);
    }

    #[Test]
    public function loadContentsFindsOnlyPagesRestrictedToTheGivenLanguage(): void
    {
        $subject = $this->createCollectionForLanguage(1);

        $subject->loadContents();

        $uids = array_column($subject->getItems(), 'uid');
        self::assertSame([1], $uids);
    }

    #[Test]
    public function loadContentsMatchesAnyOfMultipleCommaSeparatedRestrictionValues(): void
    {
        $subject = $this->createCollectionForLanguage(2);

        $subject->loadContents();

        $uids = array_column($subject->getItems(), 'uid');
        self::assertSame([1], $uids);
    }

    #[Test]
    public function loadContentsExcludesDeletedPagesEvenIfTheyWouldOtherwiseMatch(): void
    {
        $subject = $this->createCollectionForLanguage(1);

        $subject->loadContents();

        $uids = array_column($subject->getItems(), 'uid');
        self::assertNotContains(4, $uids, 'page uid 4 is deleted and restricted to language 1, it must not appear');
    }

    #[Test]
    public function loadContentsFindsNoPagesForALanguageNoPageIsRestrictedTo(): void
    {
        $subject = $this->createCollectionForLanguage(999);

        $subject->loadContents();

        self::assertSame([], $subject->getItems());
    }

    #[Test]
    public function loadContentsReplacesRatherThanAccumulatesOnRepeatedCalls(): void
    {
        $subject = $this->createCollectionForLanguage(1);
        $subject->loadContents();
        self::assertCount(1, $subject->getItems());

        $subject->loadContents();

        self::assertCount(1, $subject->getItems(), 'a second loadContents() call must not duplicate the already-loaded items');
    }

    #[Test]
    public function loadResolvesTheLanguageFromTheSiteAtTheGivenPageId(): void
    {
        $this->writeSiteConfiguration(rootPageId: 1);

        $collection = LanguageRestrictionCollection::load(languageId: 0, tableName: 'pages', pageId: 1);

        self::assertSame(0, $collection->getUid());
        self::assertSame('English', $collection->getTitle());
    }

    #[Test]
    public function loadFallsBackToUidZeroAndAnEmptyTitleWhenNoSiteCanBeResolved(): void
    {
        // No site configuration written in this test - getSiteByPageId() cannot resolve pageId 1
        // to any site, LanguageRestrictionCollection::getLanguage() throws, and load() catches it.
        $collection = LanguageRestrictionCollection::load(languageId: 0, tableName: 'pages', pageId: 1);

        self::assertSame(0, $collection->getUid());
        self::assertSame('', $collection->getTitle());
    }
}
