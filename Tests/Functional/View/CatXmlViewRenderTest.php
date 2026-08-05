<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Model\L10nConfiguration;
use Localizationteam\L10nmgr\Services\TranslationDetailsService;
use Localizationteam\L10nmgr\Utility\JobsPathUtility;
use Localizationteam\L10nmgr\View\CatXmlView;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers CatXmlView::render() end to end: the real
 * L10nConfiguration::getL10nAccumulatedInformationsObjectForLanguage() tree-walk, driven for real
 * rather than with a manually-built PageTreeView, through to the written export file.
 * getValueForXml()'s own escaping/RTE/CDATA branches are already unit-tested in
 * Tests/Unit/View/CatXmlViewTest.php - not repeated here.
 */
class CatXmlViewRenderTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['scheduler'];

    protected array $testExtensionsToLoad = ['localizationteam/l10nmgr'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_translations.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tx_l10nmgr_cfg.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
        TranslationDetailsService::$systemLanguages = [];
        $this->writeSite();
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

    private function loadConfiguration(): L10nConfiguration
    {
        $configuration = new L10nConfiguration();
        $configuration->load(1);
        return $configuration;
    }

    #[Test]
    public function renderWritesAWellFormedXmlFileContainingTheDefaultLanguageFieldValue(): void
    {
        $subject = new CatXmlView($this->loadConfiguration(), 1);

        $absoluteFile = JobsPathUtility::resolvePath('jobs/out/' . $subject->render());

        self::assertFileExists($absoluteFile);
        $content = file_get_contents($absoluteFile);
        self::assertStringContainsString('<TYPO3L10N>', $content);
        self::assertStringContainsString('table="tt_content"', $content);
        self::assertStringContainsString('elementUid="10"', $content);
        self::assertStringContainsString('Parent Element', $content);
        self::assertStringNotContainsString('elementUid="11"', $content);
        unlink($absoluteFile);
    }

    #[Test]
    public function renderCountsFieldsAndWordsIntoTheHeader(): void
    {
        $subject = new CatXmlView($this->loadConfiguration(), 1);

        $absoluteFile = JobsPathUtility::resolvePath('jobs/out/' . $subject->render());

        $content = file_get_contents($absoluteFile);
        self::assertMatchesRegularExpression('#<t3_count translate="no">[1-9]\d*</t3_count>#', $content);
        self::assertMatchesRegularExpression('#<t3_wordCount translate="no">[1-9]\d*</t3_wordCount>#', $content);
        unlink($absoluteFile);
    }
}
