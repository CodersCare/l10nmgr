<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Controller\LocalizationModuleController;
use Localizationteam\L10nmgr\Model\Dto\EmConfiguration;
use Localizationteam\L10nmgr\Model\L10nConfiguration;
use Localizationteam\L10nmgr\Model\TranslationData;
use Localizationteam\L10nmgr\Services\L10nBaseService;
use Localizationteam\L10nmgr\Services\TranslationDetailsService;
use Localizationteam\L10nmgr\Utility\JobsPathUtility;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers getFuncMenu()/getFuncCheck(), initialize()/getL10NConfiguration(), and main()'s
 * view-assembly cluster. handleRequest() has no dedicated test (thin pass-through to main()).
 */
class LocalizationModuleControllerTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['scheduler'];

    protected array $testExtensionsToLoad = ['localizationteam/l10nmgr'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_groups.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tx_l10nmgr_cfg.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_translations.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
        $this->writeTwoLanguageSite();
        TranslationDetailsService::$systemLanguages = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        $sitesPath = Environment::getConfigPath() . '/sites/';
        if (is_dir($sitesPath)) {
            GeneralUtility::rmdir($sitesPath, true);
        }
        $this->get(CacheManager::class)->getCache('core')->remove('sites-configuration');
        parent::tearDown();
    }

    /**
     * Site with rootPageId=1 (matching pages.csv's root page) and two languages - English (the
     * default, uid=0) and German (uid=1) - so menuConfig()'s language menu has real, non-default
     * data to filter.
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

    private function createSubject(?EmConfiguration $emConfiguration = null): LocalizationModuleController
    {
        return new LocalizationModuleController(
            $this->get(IconFactory::class),
            $this->get(ModuleProvider::class),
            $emConfiguration ?? new EmConfiguration(),
            $this->get(ModuleTemplateFactory::class),
            $this->get(L10nBaseService::class),
        );
    }

    private function createModuleRequest(array $queryParams = [], array $parsedBody = []): ServerRequest
    {
        $module = self::createStub(ModuleInterface::class);
        $module->method('getIdentifier')->willReturn('l10nmgr_management');
        $module->method('getTitle')->willReturn('l10nmgr_management');

        $route = new Route('/module/l10nmgr/configuration/localization', [
            'packageName' => 'localizationteam/l10nmgr',
            '_identifier' => 'l10nmgr_configuration.localize',
        ]);
        $request = (new ServerRequest('https://example.com/typo3/module/l10nmgr/configuration/localization'))
            ->withAttribute('module', $module)
            ->withAttribute('route', $route)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withQueryParams($queryParams);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));

        return $parsedBody === [] ? $request : $request->withParsedBody($parsedBody);
    }

    private function loadConfiguration(int $uid = 1): L10nConfiguration
    {
        $configuration = new L10nConfiguration();
        $configuration->load($uid);
        return $configuration;
    }

    private function getProtectedProperty(object $object, string $name): mixed
    {
        return (new \ReflectionProperty($object, $name))->getValue($object);
    }

    private function createSubjectWithL10nBaseService(L10nBaseService $l10nBaseService): LocalizationModuleController
    {
        return new LocalizationModuleController(
            $this->get(IconFactory::class),
            $this->get(ModuleProvider::class),
            new EmConfiguration(),
            $this->get(ModuleTemplateFactory::class),
            $l10nBaseService,
        );
    }

    private function assertDownloadLinkAndResolveExportedFile(string $html): string
    {
        self::assertMatchesRegularExpression('/href="[^"]+"/', $html);
        preg_match('/href="([^"]+)"/', $html, $matches);
        $query = [];
        parse_str((string)parse_url(htmlspecialchars_decode($matches[1]), PHP_URL_QUERY), $query);
        self::assertArrayHasKey('file', $query);
        return JobsPathUtility::resolvePath('jobs/out/' . $query['file']);
    }

    #[Test]
    public function initializeSetsIdAndSrcPidFromQueryParams(): void
    {
        $subject = $this->createSubject();

        $subject->initialize($this->createModuleRequest(['id' => 5, 'srcPID' => 3]));

        self::assertSame(5, $subject->id);
        self::assertSame(3, $subject->srcPID);
    }

    #[Test]
    public function initializeDefaultsIdAndSrcPidToZeroWhenNeitherQueryNorBodyProvideThem(): void
    {
        $subject = $this->createSubject();

        $subject->initialize($this->createModuleRequest());

        self::assertSame(0, $subject->id);
        self::assertSame(0, $subject->srcPID);
    }

    #[Test]
    public function initializeFallsBackToTheParsedBodyForIdAndSrcPidWhenQueryParamsDoNotProvideThem(): void
    {
        $subject = $this->createSubject();

        $subject->initialize($this->createModuleRequest([], ['id' => 7, 'srcPID' => 9]));

        self::assertSame(7, $subject->id);
        self::assertSame(9, $subject->srcPID);
    }

    #[Test]
    public function initializePrefersQueryParamsOverTheParsedBodyWhenBothProvideIdAndSrcPid(): void
    {
        $subject = $this->createSubject();

        $subject->initialize($this->createModuleRequest(['id' => 5, 'srcPID' => 3], ['id' => 7, 'srcPID' => 9]));

        self::assertSame(5, $subject->id);
        self::assertSame(3, $subject->srcPID);
    }

    #[Test]
    public function initializeSetsMconfNameFromTheCurrentModulesIdentifier(): void
    {
        $subject = $this->createSubject();

        $subject->initialize($this->createModuleRequest());

        self::assertSame('l10nmgr_management', $subject->MCONF['name']);
    }

    #[Test]
    public function initializeBuildsTheStaticActionMenuEntries(): void
    {
        $subject = $this->createSubject();

        $subject->initialize($this->createModuleRequest());

        self::assertSame(['', 'link', 'inlineEdit', 'export_excel', 'export_xml'], array_keys($subject->MOD_MENU['action']));
    }

    #[Test]
    public function initializeDefaultsModSettingsActionAndLangToTheFirstMenuEntry(): void
    {
        $subject = $this->createSubject();

        $subject->initialize($this->createModuleRequest(['id' => 1, 'srcPID' => 1]));

        self::assertSame('', $subject->MOD_SETTINGS['action']);
        self::assertSame('1', $subject->MOD_SETTINGS['lang']);
    }

    #[Test]
    public function initializeIncludesConfiguredNonDefaultLanguagesInTheLanguageMenuButNeverTheDefaultLanguage(): void
    {
        $subject = $this->createSubject();

        $subject->initialize($this->createModuleRequest(['id' => 1, 'srcPID' => 1]));

        self::assertSame([1 => 'German'], $subject->MOD_MENU['lang']);
    }

    #[Test]
    public function initializeLanguageMenuIsUnaffectedByTheEnableHiddenLanguagesToggle(): void
    {
        $subjectWithHiddenLanguagesDisabled = $this->createSubject(new EmConfiguration(['enable_hidden_languages' => 0]));
        $subjectWithHiddenLanguagesEnabled = $this->createSubject(new EmConfiguration(['enable_hidden_languages' => 1]));

        $subjectWithHiddenLanguagesDisabled->initialize($this->createModuleRequest(['id' => 1, 'srcPID' => 1]));
        $subjectWithHiddenLanguagesEnabled->initialize($this->createModuleRequest(['id' => 1, 'srcPID' => 1]));

        self::assertSame($subjectWithHiddenLanguagesDisabled->MOD_MENU['lang'], $subjectWithHiddenLanguagesEnabled->MOD_MENU['lang']);
    }

    #[Test]
    public function getL10NConfigurationLoadsTheRecordIdentifiedByTheExportUidQueryParameter(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->createModuleRequest(['exportUID' => 1]);
        $subject = $this->createSubject();

        $result = (new \ReflectionMethod($subject, 'getL10NConfiguration'))->invoke($subject);

        self::assertTrue($result->isLoaded());
        self::assertSame(1, $result->getUid());
        self::assertSame(1, $result->getPid());
    }

    #[Test]
    public function getL10NConfigurationReturnsAnUnloadedConfigurationWhenNoExportUidIsProvided(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->createModuleRequest();
        $subject = $this->createSubject();

        $result = (new \ReflectionMethod($subject, 'getL10NConfiguration'))->invoke($subject);

        self::assertFalse($result->isLoaded());
    }

    #[Test]
    public function makeFunctionMenuBuildsSelectMenusWithTheCurrentActionAndLanguageMarkedSelected(): void
    {
        $subject = $this->createSubject();
        $subject->initialize($this->createModuleRequest(['id' => 1, 'srcPID' => 1]));
        (new \ReflectionProperty($subject, 'sysLanguage'))->setValue($subject, 1);

        $result = (new \ReflectionMethod($subject, 'makeFunctionMenu'))->invoke($subject, 'export_xml', '');

        self::assertSame('SET[action]', $result['select'][0]['elementName']);
        self::assertTrue(current(array_filter($result['select'][0]['options'], static fn ($o) => $o['value'] === 'export_xml'))['selected']);
        self::assertSame('SET[lang]', $result['select'][1]['elementName']);
        self::assertTrue(current(array_filter($result['select'][1]['options'], static fn ($o) => $o['value'] === '1'))['selected']);
    }

    #[Test]
    public function makeFunctionMenuBuildsCheckboxesForOnlyChangedContentAndNoHidden(): void
    {
        $subject = $this->createSubject();
        $subject->initialize($this->createModuleRequest(['id' => 1, 'srcPID' => 1]));

        $result = (new \ReflectionMethod($subject, 'makeFunctionMenu'))->invoke($subject, '', '');

        self::assertSame('SET[onlyChangedContent]', $result['checkboxes'][0]['elementName']);
        self::assertSame('SET[noHidden]', $result['checkboxes'][1]['elementName']);
    }

    #[Test]
    public function makePreviewLanguageMenuIncludesADefaultOptionAheadOfTheConfiguredLanguages(): void
    {
        $subject = $this->createSubject();
        $subject->initialize($this->createModuleRequest(['id' => 1, 'srcPID' => 1]));

        $result = (new \ReflectionMethod($subject, 'makePreviewLanguageMenu'))->invoke($subject, 0, false);

        self::assertSame(['0', '1'], array_column($result['options'], 'value'));
        self::assertSame('German', $result['options'][1]['label']);
        self::assertArrayNotHasKey('forcedSourceLanguage', $result);
        self::assertFalse($result['onlyForcedSourceLanguage']);
    }

    #[Test]
    public function makePreviewLanguageMenuSetsForcedSourceLanguageAndOnlyForcedSourceLanguageWhenGiven(): void
    {
        $subject = $this->createSubject();
        $subject->initialize($this->createModuleRequest(['id' => 1, 'srcPID' => 1]));

        $result = (new \ReflectionMethod($subject, 'makePreviewLanguageMenu'))->invoke($subject, 1, true);

        self::assertSame(1, $result['forcedSourceLanguage']);
        self::assertTrue($result['onlyForcedSourceLanguage']);
    }

    #[Test]
    public function renderConfigurationTableReturnsTheConfigurationsOwnDetailsWhenLoaded(): void
    {
        $subject = $this->createSubject();

        $result = (new \ReflectionMethod($subject, 'renderConfigurationTable'))->invoke($subject, $this->loadConfiguration());

        self::assertSame('Test Configuration [1]', $result['header']);
        self::assertSame('tt_content', $result['tables']);
    }

    #[Test]
    public function renderConfigurationTableReturnsAnInvalidMarkerWhenTheConfigurationIsNotLoaded(): void
    {
        $subject = $this->createSubject();

        $result = (new \ReflectionMethod($subject, 'renderConfigurationTable'))->invoke($subject, new L10nConfiguration());

        self::assertTrue($result['isInvalid']);
    }

    #[Test]
    public function moduleContentReturnsAnEmptyArrayWhenNoActionIsSelected(): void
    {
        $subject = $this->createSubject();
        $subject->initialize($this->createModuleRequest(['id' => 1, 'srcPID' => 1]));
        $subject->MOD_SETTINGS['action'] = '';

        $result = (new \ReflectionMethod($subject, 'moduleContent'))->invoke($subject, $this->loadConfiguration());

        self::assertSame([], $result);
    }

    #[Test]
    public function moduleContentDispatchesToLinkOverviewAndOnlineTranslationActionForTheLinkAction(): void
    {
        $subject = $this->createSubject();
        $request = $this->createModuleRequest(['id' => 1, 'srcPID' => 1]);
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $subject->initialize($request);
        $subject->MOD_SETTINGS['action'] = 'link';

        $result = (new \ReflectionMethod($subject, 'moduleContent'))->invoke($subject, $this->loadConfiguration());

        self::assertArrayHasKey('sections', $result);
    }

    #[Test]
    public function moduleContentDispatchesToExportImportXmlActionForTheExportXmlAction(): void
    {
        $subject = $this->createSubject();
        $subject->initialize($this->createModuleRequest(['id' => 1, 'srcPID' => 1]));
        $subject->MOD_SETTINGS['action'] = 'export_xml';

        $result = (new \ReflectionMethod($subject, 'moduleContent'))->invoke($subject, $this->loadConfiguration());

        self::assertArrayHasKey('previewLanguageMenu', $result);
    }

    #[Test]
    public function linkOverviewAndOnlineTranslationActionReturnsRenderedSectionsForTheLinkAction(): void
    {
        $subject = $this->createSubject();
        $request = $this->createModuleRequest(['id' => 1, 'srcPID' => 1]);
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $subject->initialize($request);
        $subject->MOD_SETTINGS['action'] = 'link';

        $result = (new \ReflectionMethod($subject, 'linkOverviewAndOnlineTranslationAction'))
            ->invoke($subject, $this->loadConfiguration(), []);

        $rowsAsString = implode('', array_column($result['sections'][1]['rows'], 'html'));
        self::assertStringContainsString('Parent Element', $rowsAsString);
    }

    #[Test]
    public function linkOverviewAndOnlineTranslationActionShowsEditLinksOnlyForTheLinkAction(): void
    {
        $subject = $this->createSubject();
        $request = $this->createModuleRequest(['id' => 1, 'srcPID' => 1]);
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $subject->initialize($request);
        $subject->MOD_SETTINGS['action'] = 'link';

        $result = (new \ReflectionMethod($subject, 'linkOverviewAndOnlineTranslationAction'))
            ->invoke($subject, $this->loadConfiguration(), []);

        $rowsAsString = implode('', array_column($result['sections'][1]['rows'], 'html'));
        self::assertStringContainsString('<a ', $rowsAsString);
    }

    #[Test]
    public function linkOverviewAndOnlineTranslationActionRunsInlineEditActionForTheInlineEditAction(): void
    {
        $subject = $this->createSubject();
        $request = $this->createModuleRequest(['id' => 1, 'srcPID' => 1]);
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $subject->initialize($request);
        $subject->MOD_SETTINGS['action'] = 'inlineEdit';

        $result = (new \ReflectionMethod($subject, 'linkOverviewAndOnlineTranslationAction'))
            ->invoke($subject, $this->loadConfiguration(), []);

        self::assertArrayHasKey('saveConfirmation', $result['inlineEdit']);
    }

    #[Test]
    public function inlineEditActionSavesTheSubmittedTranslationDataWhenSaveInlineIsSet(): void
    {
        $l10nBaseService = $this->createMock(L10nBaseService::class);
        $l10nBaseService->expects(self::once())->method('saveTranslation')->with(
            self::anything(),
            self::callback(static function (TranslationData $translationData): bool {
                return $translationData->getTranslationData() === ['tt_content:11/1/10:header' => 'German Header']
                    && $translationData->getLanguage() === 1
                    && $translationData->getPreviewLanguage() === 0;
            })
        );
        $subject = $this->createSubjectWithL10nBaseService($l10nBaseService);
        $subject->initialize($this->createModuleRequest(['id' => 1, 'srcPID' => 1], [
            'saveInline' => '1',
            'translation' => ['tt_content:11/1/10:header' => 'German Header'],
        ]));
        (new \ReflectionProperty($subject, 'sysLanguage'))->setValue($subject, 1);

        (new \ReflectionMethod($subject, 'inlineEditAction'))->invoke($subject, $this->loadConfiguration());
    }

    #[Test]
    public function inlineEditActionDoesNotCallSaveTranslationWhenSaveInlineIsNotSet(): void
    {
        $l10nBaseService = $this->createMock(L10nBaseService::class);
        $l10nBaseService->expects(self::never())->method('saveTranslation');
        $subject = $this->createSubjectWithL10nBaseService($l10nBaseService);
        $subject->initialize($this->createModuleRequest(['id' => 1, 'srcPID' => 1], [
            'translation' => ['tt_content:11/1/10:header' => 'German Header'],
        ]));

        (new \ReflectionMethod($subject, 'inlineEditAction'))->invoke($subject, $this->loadConfiguration());
    }

    #[Test]
    public function inlineEditActionReturnsEscapedConfirmationStringsForSaveAndCancel(): void
    {
        $subject = $this->createSubject();
        $subject->initialize($this->createModuleRequest(['id' => 1, 'srcPID' => 1]));

        $result = (new \ReflectionMethod($subject, 'inlineEditAction'))->invoke($subject, $this->loadConfiguration());

        self::assertSame(
            'return confirm(' . json_encode('You are about to create/update ALL localizations in this form? Continue?') . ');',
            $result['saveConfirmation']
        );
        self::assertSame(
            'return confirm(' . json_encode('You are about to discard any changes you made. Continue?') . ');',
            $result['cancelConfirmation']
        );
    }

    #[Test]
    public function excelExportImportActionReturnsNoFlashMessageAndNoImportWhenNeitherActionIsRequested(): void
    {
        $subject = $this->createSubject();
        $subject->initialize($this->createModuleRequest(['id' => 1, 'srcPID' => 1]));

        $result = (new \ReflectionMethod($subject, 'excelExportImportAction'))->invoke($subject, $this->loadConfiguration());

        self::assertFalse($result['isImport']);
        self::assertFalse($result['importSuccess']);
        self::assertSame('', $result['flashMessageHtml']);
        self::assertArrayHasKey('previewLanguageMenu', $result);
    }

    #[Test]
    public function excelExportImportActionSetsImportAsDefaultLanguageOnTheServiceWhenTheFlagIsSet(): void
    {
        $l10nBaseService = $this->createMock(L10nBaseService::class);
        $l10nBaseService->expects(self::once())->method('setImportAsDefaultLanguage')->with(true);
        $subject = $this->createSubjectWithL10nBaseService($l10nBaseService);
        $subject->initialize($this->createModuleRequest(['id' => 1, 'srcPID' => 1], ['import_asdefaultlanguage' => '1']));

        (new \ReflectionMethod($subject, 'excelExportImportAction'))->invoke($subject, $this->loadConfiguration());
    }

    #[Test]
    public function excelExportImportActionExportsAndReturnsADownloadLinkWhenExportExcelIsRequested(): void
    {
        $subject = $this->createSubject();
        $subject->initialize($this->createModuleRequest(['id' => 1, 'srcPID' => 1], ['export_excel' => '1']));
        (new \ReflectionProperty($subject, 'sysLanguage'))->setValue($subject, 1);

        $result = (new \ReflectionMethod($subject, 'excelExportImportAction'))->invoke($subject, $this->loadConfiguration());

        $absoluteFile = $this->assertDownloadLinkAndResolveExportedFile($result['flashMessageHtml']);
        self::assertFileExists($absoluteFile);
        unlink($absoluteFile);
    }

    #[Test]
    public function excelExportImportActionShowsExistingExportsWhenCheckExportsFindsADuplicate(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_l10nmgr_exportdata')->insert('tx_l10nmgr_exportdata', [
            'l10ncfg_id' => 1,
            'exportType' => 0,
            'translation_lang' => 1,
            'crdate' => 0,
            'tstamp' => 0,
            'filename' => 'previous-export.xml',
        ]);
        $subject = $this->createSubject();
        $subject->initialize($this->createModuleRequest(['id' => 1, 'srcPID' => 1], ['export_excel' => '1', 'check_exports' => '1']));
        (new \ReflectionProperty($subject, 'sysLanguage'))->setValue($subject, 1);

        $result = (new \ReflectionMethod($subject, 'excelExportImportAction'))->invoke($subject, $this->loadConfiguration());

        self::assertStringContainsString('previous-export.xml', $result['existingExportsOverview']);
    }

    #[Test]
    public function catXMLExportImportActionReturnsNoExistingExportsOrFlashMessagesWhenNeitherImportNorExportIsRequested(): void
    {
        $subject = $this->createSubject();
        $subject->initialize($this->createModuleRequest(['id' => 1, 'srcPID' => 1]));

        $result = (new \ReflectionMethod($subject, 'catXMLExportImportAction'))->invoke($subject, $this->loadConfiguration());

        self::assertSame('', $result['existingExportsOverview']);
        self::assertSame([], $result['flashMessages']);
        self::assertArrayHasKey('across', $result['settingsFiles']);
        self::assertArrayHasKey('previewLanguageMenu', $result);
        self::assertFalse($result['workspacesLoaded']);
    }

    #[Test]
    public function catXMLExportImportActionExportsAndReturnsADownloadLinkWhenExportXmlIsRequested(): void
    {
        $subject = $this->createSubject();
        $subject->initialize($this->createModuleRequest(['id' => 1, 'srcPID' => 1], ['export_xml' => '1']));
        (new \ReflectionProperty($subject, 'sysLanguage'))->setValue($subject, 1);

        $result = (new \ReflectionMethod($subject, 'catXMLExportImportAction'))->invoke($subject, $this->loadConfiguration());

        $absoluteFile = $this->assertDownloadLinkAndResolveExportedFile(implode('', $result['flashMessages']));
        self::assertFileExists($absoluteFile);
        unlink($absoluteFile);
    }

    #[Test]
    public function catXMLExportImportActionShowsExistingExportsWhenCheckExportsFindsADuplicate(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_l10nmgr_exportdata')->insert('tx_l10nmgr_exportdata', [
            'l10ncfg_id' => 1,
            'exportType' => 1,
            'translation_lang' => 1,
            'crdate' => 0,
            'tstamp' => 0,
            'filename' => 'previous-export.xml',
        ]);
        $subject = $this->createSubject();
        $subject->initialize($this->createModuleRequest(['id' => 1, 'srcPID' => 1], ['export_xml' => '1', 'check_exports' => '1']));
        (new \ReflectionProperty($subject, 'sysLanguage'))->setValue($subject, 1);

        $result = (new \ReflectionMethod($subject, 'catXMLExportImportAction'))->invoke($subject, $this->loadConfiguration());

        self::assertStringContainsString('previous-export.xml', $result['existingExportsOverview']);
    }

    #[Test]
    public function downloadExportStreamsTheFileContentsInlineWhenTheUserHasAccess(): void
    {
        $subject = $this->createSubject();
        $subject->initialize($this->createModuleRequest(['id' => 1, 'srcPID' => 1], ['export_xml' => '1']));
        (new \ReflectionProperty($subject, 'sysLanguage'))->setValue($subject, 1);
        $result = (new \ReflectionMethod($subject, 'catXMLExportImportAction'))->invoke($subject, $this->loadConfiguration());
        $absoluteFile = $this->assertDownloadLinkAndResolveExportedFile(implode('', $result['flashMessages']));
        $filename = basename($absoluteFile);

        $response = $subject->downloadExport($this->createModuleRequest(['file' => $filename]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/xml; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('inline; filename="' . $filename . '"', $response->getHeaderLine('Content-Disposition'));
        self::assertSame(file_get_contents($absoluteFile), (string)$response->getBody());
        unlink($absoluteFile);
    }

    #[Test]
    public function downloadExportReturns404WhenTheFileParamIsEmptyOrUnknown(): void
    {
        $subject = $this->createSubject();

        self::assertSame(404, $subject->downloadExport($this->createModuleRequest())->getStatusCode());
        self::assertSame(404, $subject->downloadExport($this->createModuleRequest(['file' => 'doesNotExist.xml']))->getStatusCode());
    }

    #[Test]
    public function downloadExportReturns403ForABackendUserWithoutModuleAccess(): void
    {
        $this->setUpBackendUser(2);
        $subject = $this->createSubject();

        $response = $subject->downloadExport($this->createModuleRequest(['file' => 'whatever.xml']));

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function downloadExportReturns403WhenTheBackendUserLacksAccessToTheExportsPage(): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->insert('pages', [
            'uid' => 3,
            'pid' => 0,
            'doktype' => 1,
            'title' => 'Restricted Page',
            'perms_everybody' => 0,
        ]);
        $this->getConnectionPool()->getConnectionForTable('tx_l10nmgr_exportdata')->insert('tx_l10nmgr_exportdata', [
            'l10ncfg_id' => 1,
            'pid' => 3,
            'exportType' => 1,
            'translation_lang' => 1,
            'crdate' => 0,
            'tstamp' => 0,
            'filename' => 'restricted-export.xml',
        ]);
        $this->setUpBackendUser(3);
        $subject = $this->createSubject();

        $response = $subject->downloadExport($this->createModuleRequest(['file' => 'restricted-export.xml']));

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function getTabContentXmlDownloadsListsOnlyTheSettingsFilesThatActuallyExistOnDisk(): void
    {
        $subject = $this->createSubject();

        $result = (new \ReflectionMethod($subject, 'getTabContentXmlDownloads'))->invoke($subject);

        self::assertSame(
            ['across', 'dejaVu', 'memoq', 'memoq2013-2014', 'sdltrados2007', 'sdltrados2009', 'sdlpassolo'],
            array_keys($result)
        );
        self::assertStringContainsString('setting=across', (string)$result['across']['href']);
    }

    #[Test]
    public function downloadSettingReturnsTheFileContentsWithAttachmentHeadersForAConfiguredSetting(): void
    {
        $subject = $this->createSubject();

        $response = $subject->downloadSetting($this->createModuleRequest(['setting' => 'across']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            'attachment; filename="acrossL10nmgrConfig.dst"',
            $response->getHeaderLine('Content-Disposition')
        );
        self::assertNotEmpty((string)$response->getBody());
    }

    #[Test]
    public function downloadSettingReturns404WhenTheSettingKeyIsUnknownOrMissing(): void
    {
        $subject = $this->createSubject();

        self::assertSame(404, $subject->downloadSetting($this->createModuleRequest(['setting' => 'doesNotExist']))->getStatusCode());
        self::assertSame(404, $subject->downloadSetting($this->createModuleRequest())->getStatusCode());
    }

    #[Test]
    public function downloadSettingReturns403ForABackendUserWithoutModuleAccess(): void
    {
        $this->setUpBackendUser(2);
        $subject = $this->createSubject();

        $response = $subject->downloadSetting($this->createModuleRequest(['setting' => 'across']));

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function exportImportXmlActionPersistsCatXmlPreferencesToTheBackendUsersModuleData(): void
    {
        $subject = $this->createSubject();
        $subject->initialize($this->createModuleRequest(['id' => 1, 'srcPID' => 1], [
            'check_utf8' => '1',
            'no_check_xml' => '',
            'check_exports' => '1',
        ]));

        (new \ReflectionMethod($subject, 'exportImportXmlAction'))->invoke($subject, $this->loadConfiguration());

        self::assertSame(
            ['utf8' => '1', 'noxmlcheck' => '', 'check_exports' => '1'],
            $GLOBALS['BE_USER']->getModuleData('l10nmgr/cm1/prefs')
        );
    }

    #[Test]
    public function mainSetsIdAndPageinfoAndSysLanguageWhenAValidAccessibleConfigurationIsFound(): void
    {
        $subject = $this->createSubject();
        $request = $this->createModuleRequest(['id' => 1, 'srcPID' => 1, 'exportUID' => 1]);
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $subject->initialize($request);

        (new \ReflectionMethod($subject, 'main'))->invoke($subject);

        self::assertSame(1, $subject->id);
        self::assertSame(1, $this->getProtectedProperty($subject, 'pageinfo')['uid']);
        self::assertSame(1, $this->getProtectedProperty($subject, 'sysLanguage'));
    }

    #[Test]
    public function mainLeavesPageinfoFalseWhenTheConfiguredPageIsNotAccessible(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_l10nmgr_cfg')->insert('tx_l10nmgr_cfg', [
            'uid' => 2,
            'pid' => 999,
            'title' => 'Orphaned Configuration',
            'tablelist' => 'tt_content',
        ]);
        $subject = $this->createSubject();
        $request = $this->createModuleRequest(['id' => 1, 'srcPID' => 1, 'exportUID' => 2]);
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $subject->initialize($request);

        (new \ReflectionMethod($subject, 'main'))->invoke($subject);

        self::assertFalse($this->getProtectedProperty($subject, 'pageinfo'));
    }

    #[Test]
    public function mainLeavesPageinfoUninitializedWhenNoL10nConfigurationIsFound(): void
    {
        $subject = $this->createSubject();
        $request = $this->createModuleRequest(['id' => 1, 'srcPID' => 1]);
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $subject->initialize($request);

        (new \ReflectionMethod($subject, 'main'))->invoke($subject);

        $this->expectException(\Error::class);
        $this->getProtectedProperty($subject, 'pageinfo');
    }

    #[Test]
    public function getFuncMenuEscapesSingleQuotesInOptionLabelsLikeItsSiblings(): void
    {
        $menu = LocalizationModuleController::getFuncMenu(
            1,
            'SET[test]',
            'a',
            ['a' => "O'Reilly & <b>Sons</b>"]
        );

        self::assertSame('O&#039;Reilly &amp; &lt;b&gt;Sons&lt;/b&gt;', $menu['options'][0]['label']);
    }

    #[Test]
    public function getFuncCheckMarksTheElementCheckedWhenTheCurrentValueIsTruthy(): void
    {
        $result = LocalizationModuleController::getFuncCheck(1, 'SET[onlyChanged]', '1');

        self::assertSame(' checked="checked"', $result['checked']);
    }

    #[Test]
    public function getFuncCheckLeavesTheElementUncheckedWhenTheCurrentValueIsEmpty(): void
    {
        $result = LocalizationModuleController::getFuncCheck(1, 'SET[onlyChanged]', '');

        self::assertSame('', $result['checked']);
    }

    #[Test]
    public function getFuncCheckEscapesTheLabel(): void
    {
        $result = LocalizationModuleController::getFuncCheck(1, 'SET[onlyChanged]', '', '', '', '', "O'Reilly <b>Sons</b>");

        self::assertSame('O&#039;Reilly &lt;b&gt;Sons&lt;/b&gt;', $result['label']);
    }

    #[Test]
    public function getFuncCheckPrefixesTagParamsWithASpaceWhenGiven(): void
    {
        $result = LocalizationModuleController::getFuncCheck(1, 'SET[onlyChanged]', '', '', '', 'data-test="1"');

        self::assertSame(' data-test="1"', $result['tagParams']);
    }
}
