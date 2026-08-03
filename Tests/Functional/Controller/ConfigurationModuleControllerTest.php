<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Controller\ConfigurationModuleController;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers ConfigurationModuleController's own logic: getAllConfigurations()'s page-access
 * filtering, getContent()'s link/path enrichment, getPageDetails()'s per-request cache, and
 * initialize()'s access-decision branches. renderConfigurationDetails() is exercised too even
 * though it is dead code - not referenced by ConfigurationList.html or anywhere else in the
 * extension, confirmed by grep - since it is still cheap, pure logic worth characterizing.
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

    private function createSubject(): ConfigurationModuleController
    {
        return $this->get(ConfigurationModuleController::class);
    }

    private function createModuleRequest(int $id = 1): ServerRequest
    {
        $module = self::createStub(ModuleInterface::class);
        $module->method('getIdentifier')->willReturn('l10nmgr_configuration');
        $module->method('getTitle')->willReturn('l10nmgr_configuration');

        return (new ServerRequest('https://example.com/typo3/module/l10nmgr/configuration'))
            ->withAttribute('module', $module)
            ->withAttribute('route', new Route('/module/l10nmgr/configuration', ['packageName' => 'localizationteam/l10nmgr']))
            ->withQueryParams(['id' => $id]);
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
    public function renderConfigurationDetailsEscapesFieldValues(): void
    {
        $subject = $this->createSubject();
        $configuration = [
            'pid' => 1,
            'title' => '<script>alert(1)</script>',
            'filenameprefix' => 'export',
            'depth' => 0,
            'sourceLangStaticId' => 0,
            'tablelist' => 'tt_content',
            'exclude' => '',
            'include' => '',
            'displaymode' => '0',
        ];

        $result = (new \ReflectionMethod($subject, 'renderConfigurationDetails'))->invoke($subject, $configuration);

        self::assertStringNotContainsString('<script>', $result);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $result);
    }

    #[Test]
    public function renderConfigurationDetailsFallsBackToDefaultLabelWhenNoSourceLanguageIsSet(): void
    {
        $subject = $this->createSubject();
        $configuration = [
            'pid' => 1,
            'title' => 'Test',
            'filenameprefix' => 'export',
            'depth' => 0,
            'sourceLangStaticId' => 0,
            'tablelist' => 'tt_content',
            'exclude' => '',
            'include' => '',
            'displaymode' => '0',
        ];

        $result = (new \ReflectionMethod($subject, 'renderConfigurationDetails'))->invoke($subject, $configuration);

        self::assertStringContainsString('Default', $result);
    }

    #[Test]
    public function getPageDetailsReturnsTheRecordFromTheDatabaseOnFirstCall(): void
    {
        $subject = $this->createSubject();

        $result = (new \ReflectionMethod($subject, 'getPageDetails'))->invoke($subject, 1);

        self::assertSame('Root Page', $result['title']);
    }

    #[Test]
    public function getPageDetailsReturnsTheCachedRecordOnASecondCallWithoutHittingTheDatabaseAgain(): void
    {
        $subject = $this->createSubject();
        (new \ReflectionMethod($subject, 'getPageDetails'))->invoke($subject, 1);
        $cache = new \ReflectionProperty($subject, 'pageDetails');
        $cached = $cache->getValue($subject);
        $cached[1]['title'] = 'Stale Cached Title';
        $cache->setValue($subject, $cached);

        $result = (new \ReflectionMethod($subject, 'getPageDetails'))->invoke($subject, 1);

        self::assertSame('Stale Cached Title', $result['title']);
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
