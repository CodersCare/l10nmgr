<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Controller\ConfigurationModuleController;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers ConfigurationModuleController's own logic: getAllConfigurations()'s page-access
 * filtering, getContent()'s link/path/sourceLanguage enrichment, and initialize()'s
 * access-decision branches.
 *
 * initialize()/handleRequest() need a real ModuleTemplate, which needs a 'route' request
 * attribute (BackendViewFactory::create() reads $request->getAttribute('route')->getOption(...)
 * unconditionally) - built as a real TYPO3\CMS\Backend\Routing\Route instance, not a stub, since
 * it is a simple concrete class. 'module' is a stub, since ModuleInterface has no simple
 * concrete implementation and only getIdentifier()/getTitle() are actually read on this path.
 */
class ConfigurationModuleControllerTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['scheduler'];

    protected array $testExtensionsToLoad = ['localizationteam/l10nmgr'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
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

    private function createSubject(): ConfigurationModuleController
    {
        return $this->get(ConfigurationModuleController::class);
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

    private function createModuleRequest(int $id = 1): ServerRequest
    {
        $module = self::createStub(ModuleInterface::class);
        $module->method('getIdentifier')->willReturn('l10nmgr_configuration');
        $module->method('getTitle')->willReturn('l10nmgr_configuration');

        $request = (new ServerRequest('https://example.com/typo3/module/l10nmgr/configuration'))
            ->withAttribute('module', $module)
            ->withAttribute('route', new Route('/module/l10nmgr/configuration', ['packageName' => 'localizationteam/l10nmgr']))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withQueryParams(['id' => $id]);

        return $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }

    #[Test]
    public function getAllConfigurationsReturnsConfigurationsForAnAccessiblePage(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tx_l10nmgr_cfg.csv');
        $subject = $this->createSubject();

        $result = (new \ReflectionMethod($subject, 'getAllConfigurations'))->invoke($subject);

        self::assertCount(1, $result);
        self::assertSame(1, $result[0]['uid']);
    }

    #[Test]
    public function getAllConfigurationsExcludesConfigurationsPointingAtANonExistentPage(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tx_l10nmgr_cfg.csv');
        $this->getConnectionPool()->getConnectionForTable('tx_l10nmgr_cfg')->insert('tx_l10nmgr_cfg', [
            'uid' => 2,
            'pid' => 999,
            'title' => 'Orphaned Configuration',
            'tablelist' => 'tt_content',
        ]);
        $subject = $this->createSubject();

        $result = (new \ReflectionMethod($subject, 'getAllConfigurations'))->invoke($subject);

        self::assertCount(1, $result);
        self::assertSame(1, $result[0]['uid']);
    }

    #[Test]
    public function getContentAddsALinkAndAPathToEachConfiguration(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tx_l10nmgr_cfg.csv');
        $subject = $this->createSubject();
        $request = $this->createModuleRequest();
        (new \ReflectionMethod($subject, 'initialize'))->invoke($subject, $request);

        $result = (new \ReflectionMethod($subject, 'getContent'))->invoke($subject);

        self::assertStringContainsString('/localization', $result[0]['link']);
        self::assertStringContainsString('exportUID=1', $result[0]['link']);
        self::assertSame('/Root Page/', $result[0]['path']);
    }

    #[Test]
    public function getContentResolvesTheSourceLanguageTitleWhenAForcedSourceLanguageIsSet(): void
    {
        $this->writeSiteConfiguration(rootPageId: 1);
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tx_l10nmgr_cfg.csv');
        $this->getConnectionPool()->getConnectionForTable('tx_l10nmgr_cfg')
            ->update('tx_l10nmgr_cfg', ['forcedSourceLanguage' => 1], ['uid' => 1]);
        $subject = $this->createSubject();
        $request = $this->createModuleRequest();
        (new \ReflectionMethod($subject, 'initialize'))->invoke($subject, $request);

        $result = (new \ReflectionMethod($subject, 'getContent'))->invoke($subject);

        self::assertSame('German', $result[0]['sourceLanguage']);
    }

    #[Test]
    public function getContentFallsBackToTheDefaultLabelWhenNoSourceLanguageIsForced(): void
    {
        $this->writeSiteConfiguration(rootPageId: 1);
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tx_l10nmgr_cfg.csv');
        $subject = $this->createSubject();
        $request = $this->createModuleRequest();
        (new \ReflectionMethod($subject, 'initialize'))->invoke($subject, $request);

        $result = (new \ReflectionMethod($subject, 'getContent'))->invoke($subject);

        self::assertSame('Default', $result[0]['sourceLanguage']);
    }

    #[Test]
    public function getContentFallsBackToTheDefaultLabelWhenTheForcedSourceLanguageDoesNotExistOnTheSite(): void
    {
        $this->writeSiteConfiguration(rootPageId: 1);
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tx_l10nmgr_cfg.csv');
        $this->getConnectionPool()->getConnectionForTable('tx_l10nmgr_cfg')
            ->update('tx_l10nmgr_cfg', ['forcedSourceLanguage' => 99], ['uid' => 1]);
        $subject = $this->createSubject();
        $request = $this->createModuleRequest();
        (new \ReflectionMethod($subject, 'initialize'))->invoke($subject, $request);

        $result = (new \ReflectionMethod($subject, 'getContent'))->invoke($subject);

        self::assertSame('Default', $result[0]['sourceLanguage']);
    }

    #[Test]
    public function initializeGrantsAccessForAnAdminAtRootLevelWhenNoIdIsGiven(): void
    {
        $subject = $this->createSubject();
        $request = $this->createModuleRequest(0);

        (new \ReflectionMethod($subject, 'initialize'))->invoke($subject, $request);

        self::assertSame(0, $subject->id);
        self::assertSame(['title' => '[root-level]', 'uid' => 0, 'pid' => 0], $subject->pageInfo);
    }

    #[Test]
    public function initializeGrantsAccessWhenAnExistingPageIdIsGiven(): void
    {
        $subject = $this->createSubject();
        $request = $this->createModuleRequest(1);

        (new \ReflectionMethod($subject, 'initialize'))->invoke($subject, $request);

        self::assertSame(1, $subject->id);
        self::assertSame(1, $subject->pageInfo['uid']);
    }

    #[Test]
    public function initializeLeavesPageInfoEmptyWhenTheGivenIdDoesNotExist(): void
    {
        $subject = $this->createSubject();
        $request = $this->createModuleRequest(999);

        (new \ReflectionMethod($subject, 'initialize'))->invoke($subject, $request);

        self::assertSame(999, $subject->id);
        self::assertSame([], $subject->pageInfo);
    }
}
