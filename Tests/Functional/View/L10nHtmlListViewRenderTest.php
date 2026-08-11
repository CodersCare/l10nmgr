<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Model\L10nConfiguration;
use Localizationteam\L10nmgr\Services\TranslationDetailsService;
use Localizationteam\L10nmgr\View\L10nHtmlListView;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\Richtext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers L10nHtmlListView::renderOverview(), including the RTE/CKEditor inline-edit branch (needs
 * rte_ckeditor loaded). getEditLink()'s UriBuilder call is still skipped, opt-in and not the
 * default rendering path. render() itself is an explicit `// TODO: Implement render() method.`
 * stub returning '' - characterized as such, not a bug.
 */
class L10nHtmlListViewRenderTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['scheduler', 'rte_ckeditor'];

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
        $GLOBALS['TYPO3_REQUEST'] = $this->createModuleRequest();
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

    private function createModuleRequest(): ServerRequest
    {
        $request = (new ServerRequest('https://example.com/typo3/module/l10nmgr/configuration/localization'))
            ->withAttribute('route', new Route('/module/l10nmgr/configuration/localization', ['packageName' => 'localizationteam/l10nmgr']))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withQueryParams(['id' => 1]);

        return $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }

    private function loadConfiguration(): L10nConfiguration
    {
        $configuration = new L10nConfiguration();
        $configuration->load(1);
        return $configuration;
    }

    private function createModuleTemplate(): ModuleTemplate
    {
        return $this->get(ModuleTemplateFactory::class)->create($this->createModuleRequest());
    }

    #[Test]
    public function renderOverviewReturnsASectionForThePageContainingTheDefaultLanguageRecord(): void
    {
        $subject = new L10nHtmlListView($this->loadConfiguration(), 1, $this->createModuleTemplate(), $this->get(Richtext::class), $this->get(PageRenderer::class));

        $sections = $subject->renderOverview();

        self::assertArrayHasKey(1, $sections);
        self::assertStringContainsString('Root Page', $sections[1]['head']['title']);
        $rowsAsString = implode('', array_column($sections[1]['rows'], 'html'));
        self::assertStringContainsString('tt_content:10', $rowsAsString);
        self::assertStringContainsString('Parent Element', $rowsAsString);
    }

    #[Test]
    public function renderOverviewDoesNotRenderAnEditLinkWhenModeShowEditLinksIsNotEnabled(): void
    {
        $subject = new L10nHtmlListView($this->loadConfiguration(), 1, $this->createModuleTemplate(), $this->get(Richtext::class), $this->get(PageRenderer::class));

        $sections = $subject->renderOverview();

        $rowsAsString = implode('', array_column($sections[1]['rows'], 'html'));
        self::assertStringNotContainsString('<a ', $rowsAsString);
    }

    #[Test]
    public function renderOverviewBuildsAnRTEEditorForInlineEditableTextFieldsWithoutErroring(): void
    {
        $pageRenderer = $this->get(PageRenderer::class);
        $subject = new L10nHtmlListView($this->loadConfiguration(), 1, $this->createModuleTemplate(), $this->get(Richtext::class), $pageRenderer);
        $subject->setModeWithInlineEdit();

        $sections = $subject->renderOverview();

        $rowsAsString = implode('', array_column($sections[1]['rows'], 'html'));
        self::assertStringContainsString('<textarea', $rowsAsString);
        self::assertStringContainsString('<typo3-rte-ckeditor-ckeditor5', $rowsAsString);

        // The custom element alone is inert without its JS module/CSS actually registered on the
        // page - without these, the browser renders it as a plain unstyled textarea.
        $jsModules = $pageRenderer->getJavaScriptRenderer()->toArray();
        $moduleNames = array_map(static fn (array $item): string => $item['payload']->getName(), $jsModules);
        self::assertContains('@typo3/rte-ckeditor/ckeditor5.js', $moduleNames);
        $cssFiles = (new \ReflectionProperty($pageRenderer, 'cssFiles'))->getValue($pageRenderer);
        // CMS 13's addCssFile() keys by the literal file string passed in (no EXT:->PKG:
        // normalization); match on the 'file' sub-value instead of the outer array key so this
        // isn't tied to that internal, CMS-version-specific key format (see L10N-093).
        $cssFilePaths = array_column($cssFiles, 'file');
        self::assertContains('EXT:rte_ckeditor/Resources/Public/Css/editor.css', $cssFilePaths);
    }

    #[Test]
    public function renderOverviewBuildsAnRTEEditorForAPresetWithoutExternalPluginsWithoutErroring(): void
    {
        // "minimal" ships with core rte_ckeditor and, unlike "default", doesn't import
        // Editor/Plugins.yaml - so editor.externalPlugins is never set. Reproduces GitHub l10nmgr#36.
        $this->getConnectionPool()->getConnectionForTable('pages')->update(
            'pages',
            ['TSconfig' => 'RTE.default.preset = minimal'],
            ['uid' => 1]
        );
        $subject = new L10nHtmlListView($this->loadConfiguration(), 1, $this->createModuleTemplate(), $this->get(Richtext::class), $this->get(PageRenderer::class));
        $subject->setModeWithInlineEdit();

        $sections = $subject->renderOverview();

        $rowsAsString = implode('', array_column($sections[1]['rows'], 'html'));
        self::assertStringContainsString('<typo3-rte-ckeditor-ckeditor5', $rowsAsString);
    }

    #[Test]
    public function renderOverviewStillShowsAnUntranslatedElementWithAnEmptyLabelFieldInOnlyChangedMode(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_empty_label_field.csv');
        $subject = new L10nHtmlListView($this->loadConfiguration(), 1, $this->createModuleTemplate(), $this->get(Richtext::class), $this->get(PageRenderer::class));
        $subject->setModeOnlyChanged();

        $sections = $subject->renderOverview();

        $rowsAsString = implode('', array_column($sections[1]['rows'], 'html'));
        self::assertStringContainsString('tt_content:30', $rowsAsString);
    }

    #[Test]
    public function renderOverviewStillHidesAnAlreadyTranslatedUnchangedElementWithAnEmptyLabelFieldInOnlyChangedMode(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_empty_label_field.csv');
        $subject = new L10nHtmlListView($this->loadConfiguration(), 1, $this->createModuleTemplate(), $this->get(Richtext::class), $this->get(PageRenderer::class));
        $subject->setModeOnlyChanged();

        $sections = $subject->renderOverview();

        $rowsAsString = implode('', array_column($sections[1]['rows'] ?? [], 'html'));
        self::assertStringNotContainsString('tt_content:40', $rowsAsString);
    }

    #[Test]
    public function renderIsAnUnimplementedStubReturningAnEmptyString(): void
    {
        $subject = new L10nHtmlListView($this->loadConfiguration(), 1, $this->createModuleTemplate(), $this->get(Richtext::class), $this->get(PageRenderer::class));

        self::assertSame('', $subject->render());
    }
}
