<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Controller;

/***************************************************************
 * Copyright notice
 * (c) 2006 Kasper Skårhøj <kasperYYYY@typo3.com>
 * All rights reserved
 * This script is part of the TYPO3 project. The TYPO3 project is
 * free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 * The GNU General Public License can be found at
 * http://www.gnu.org/copyleft/gpl.html.
 * This script is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use Doctrine\DBAL\DBALException;
use Exception;
use Localizationteam\L10nmgr\Model\CatXmlImportManager;
use Localizationteam\L10nmgr\Model\Dto\EmConfiguration;
use Localizationteam\L10nmgr\Model\L10nBaseService;
use Localizationteam\L10nmgr\Model\L10nConfiguration;
use Localizationteam\L10nmgr\Model\MkPreviewLinkService;
use Localizationteam\L10nmgr\Model\TranslationData;
use Localizationteam\L10nmgr\Model\TranslationDataFactory;
use Localizationteam\L10nmgr\Services\NotificationService;
use Localizationteam\L10nmgr\View\CatXmlView;
use Localizationteam\L10nmgr\View\ExcelXmlView;
use Localizationteam\L10nmgr\View\ExportViewInterface;
use Localizationteam\L10nmgr\View\L10nConfigurationDetailView;
use Localizationteam\L10nmgr\View\L10nHtmlListView;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Configuration\TranslationConfigurationProvider;
use TYPO3\CMS\Backend\Routing\Exception\ResourceNotFoundException;
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Messaging\AbstractMessage;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageRendererResolver;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;

/**
 * l10nmgr module Configuration Manager
 *
 * @author Kasper Skårhøj <kasperYYYY@typo3.com>
 * @author Daniel Zielinski <d.zielinski@l10ntech.de>
 * @author Daniel Pötzinger <poetzinger@aoemedia.de>
 * @author Fabian Seltmann <fs@marketing-factory.de>
 * @author Andreas Otto <andreas.otto@dkd.de>
 * @author Jo Hasenau <info@cybercraft.de>
 * @author Stefano Kowalke <info@arroba-it.de>
 */

/**
 * Translation management tool
 *
 * @author Kasper Skaarhoj <kasperYYYY@typo3.com>
 */
class LocalizationManager extends BaseModule
{
    /** @var int Default language to export */
    protected int $sysLanguage = 0; // Internal

    /** @var int Forced source language to export */
    public int $previewLanguage = 0;

    protected ModuleTemplate $moduleTemplate;

    protected StandaloneView $view;

    protected array $pageinfo;

    protected array $settings = [
        'across' => 'acrossL10nmgrConfig.dst',
        'dejaVu' => 'dejaVuL10nmgrConfig.dvflt',
        'memoq' => 'memoQ.mqres',
        'memoq2013-2014' => 'XMLConverter_TYPO3_l10nmgr_v3.6.mqres',
        'transit' => 'StarTransit_XML_UTF_TYPO3.FFD',
        'sdltrados2007' => 'SDLTradosTagEditor.ini',
        'sdltrados2009' => 'TYPO3_l10nmgr.sdlfiletype',
        'sdltrados2011-2014' => 'TYPO3_ConfigurationManager_v3.6.free.sdlftsettings',
        'sdlpassolo' => 'SDLPassolo.xfg',
    ];

    public function __construct(
        protected readonly IconFactory $iconFactory,
        protected readonly EmConfiguration $emConfiguration,
        protected readonly ModuleTemplateFactory $moduleTemplateFactory,
        protected readonly L10nBaseService $l10nBaseService,
    ) {
        $this->getLanguageService()
            ->includeLLFile('EXT:l10nmgr/Resources/Private/Language/Modules/LocalizationManager/locallang.xlf');
    }

    /**
     * Injects the request object for the current request or subrequest
     * Then checks for module functions that have hooked in, and renders menu etc.
     *
     * @return ResponseInterface the response with the content
     * @throws ResourceNotFoundException
     * @throws RouteNotFoundException
     */
    public function mainAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->moduleTemplate = $this->moduleTemplateFactory->create($request);

        // @extensionScannerIgnoreLine
        $this->init($request);

        // Checking for first level external objects
        $this->checkExtObj();

        $this->main();

        $this->moduleTemplate->setContent($this->content);

        return new HtmlResponse($this->moduleTemplate->renderContent());
    }

    public function init(ServerRequestInterface $request): void
    {
        /** @var Route $route */
        $route = $request->getAttribute('route');
        $this->MCONF['name'] = $route->getOption('moduleConfiguration')['name'];

        parent::init($request);
    }

    /**
     * Main function of the module. Write the content to
     *
     * @throws ResourceNotFoundException
     * @throws RouteNotFoundException
     */
    protected function main()
    {
        $backendUser = $this->getBackendUser();

        // Get language to export/import
        $this->sysLanguage = (int)($this->MOD_SETTINGS['lang'] ?? 0);

        // Javascript
        $this->moduleTemplate->addJavaScriptCode(
            'jumpToUrl',
            '
function jumpToUrl(URL) {
window.location.href = URL;
return false;
}
'
        );

        $this->moduleTemplate
            ->setTitle('L10N Manager')
            ->setForm('<form action="" method="post" enctype="multipart/form-data">');

        // Find l10n configuration record
        $l10nConfiguration = $this->getL10NConfiguration();
        if ($l10nConfiguration->isLoaded()) {
            // Setting page id
            // @extensionScannerIgnoreLine
            $this->id = $l10nConfiguration->getPid();

            // @extensionScannerIgnoreLine
            $this->pageinfo = BackendUtility::readPageAccess(
                $this->id,
                $backendUser->getPagePermsClause(Permission::PAGE_SHOW)
            );

            $access = is_array($this->pageinfo);
            // @extensionScannerIgnoreLine
            if ($this->id && $access) {
                $action = (string)($this->MOD_SETTINGS['action'] ?? '');
                $title = $this->MOD_MENU['action'][$action] ?? '';

                $addParams = sprintf('&srcPID=%d&exportUID=%d', rawurlencode(GeneralUtility::_GET('srcPID')), $l10nConfiguration->getId());
                $selectMenus = [];
                // @extensionScannerIgnoreLine
                $selectMenus[] = self::getFuncMenu(
                    $this->id,
                    'SET[action]',
                    $action,
                    $this->MOD_MENU['action'] ?? [],
                    '',
                    $addParams,
                    $this->getLanguageService()->getLL('general.export.choose.action.title')
                );

                // @extensionScannerIgnoreLine
                $selectMenus[] = self::getFuncMenu(
                    $this->id,
                    'SET[lang]',
                    (string)$this->sysLanguage,
                    $this->MOD_MENU['lang'] ?? [],
                    '',
                    $addParams,
                    $this->getLanguageService()->getLL('export.overview.targetlanguage.label')
                );

                $checkBoxes = [];
                // @extensionScannerIgnoreLine
                $checkBoxes[] = self::getFuncCheck(
                    $this->id,
                    'SET[onlyChangedContent]',
                    $this->MOD_SETTINGS['onlyChangedContent'] ?? '',
                    '',
                    $addParams,
                    '',
                    $this->getLanguageService()->getLL('export.xml.new.title')
                );
                // @extensionScannerIgnoreLine
                $checkBoxes[] = self::getFuncCheck(
                    $this->id,
                    'SET[noHidden]',
                    $this->MOD_SETTINGS['noHidden'] ?? '',
                    '',
                    $addParams,
                    '',
                    $this->getLanguageService()->getLL('export.xml.noHidden.title')
                );

                // Render content:
                $userCanEditTranslations = count($this->MOD_MENU['lang'] ?? []) > 0;

                $moduleContent = [];
                if ($userCanEditTranslations) {
                    $moduleContent = $this->moduleContent($l10nConfiguration);
                }

                // Create and render view to show details for the current L10N Manager configuration
                $configurationTable = $this->renderConfigurationTable($l10nConfiguration);

                $this->view = $this->getFluidTemplateObject();
                $this->view->assignMultiple([
                    'title' => $title,
                    'selectMenues' => $selectMenus,
                    'checkBoxes' => $checkBoxes,
                    'userCanEditTranslations' => $userCanEditTranslations,
                    'moduleAction' => $action,
                    'moduleContent' => $moduleContent,
                    'configurationTable' => $configurationTable,
                    'isRteInstalled' => ExtensionManagementUtility::isLoaded('rte_ckeditor'),
                ]);

                $this->content = $this->view->render();
            }
        }
    }

    /**
     * Returns a selector box "function menu" for a module
     * Requires the JS function jumpToUrl() to be available
     * See Inside TYPO3 for details about how to use / make Function menus
     *
     * @param mixed $mainParams The "&id=" parameter value to be sent to the module, but it can be also a parameter array which will be passed instead of the &id=...
     * @param string $elementName The form elements name, probably something like "SET[...]
     * @param string $currentValue The value to be selected currently.
     * @param array $menuItems An array with the menu items for the selector box
     * @param string $script The script to send the &id to, if empty it's automatically found
     * @param string $addParams Additional parameters to pass to the script.
     * @param string $label
     *
     * @return array
     * @throws ResourceNotFoundException
     * @throws RouteNotFoundException
     */
    public static function getFuncMenu(
        $mainParams,
        string $elementName,
        string $currentValue,
        array $menuItems,
        string $script = '',
        string $addParams = '',
        string $label = ''
    ): array {
        if (empty($menuItems)) {
            return [];
        }
        $scriptUrl = self::buildScriptUrl($mainParams, $addParams, $script);
        $options = [];
        foreach ($menuItems as $value => $text) {
            $options[] = [
                'value' => htmlspecialchars((string)$value),
                'selected' => ($currentValue === (string)$value),
                'label' => htmlspecialchars((string)$text, ENT_COMPAT, 'UTF-8', false),
            ];
        }
        $label = $label !== '' ? htmlspecialchars($label) : '';
        if (count($options) > 0) {
            $onChange = 'jumpToUrl(' . GeneralUtility::quoteJSvalue($scriptUrl . '&' . $elementName . '=') . '+this.options[this.selectedIndex].value,this);';

            return [
                'label' => $label,
                'elementName' => $elementName,
                'onChange' => $onChange,
                'options' => $options,
            ];
        }

        return [];
    }

    /**
     * Builds the URL to the current script with given arguments
     *
     * @param mixed $mainParams $id is the "&id=" parameter value to be sent to the module, but it can be also a parameter array which will be passed instead of the &id=...
     * @param string $addParams Additional parameters to pass to the script.
     * @param string $script The script to send the &id to, if empty it's automatically found
     * @return string The completes script URL
     * @throws ResourceNotFoundException
     * @throws RouteNotFoundException
     */
    protected static function buildScriptUrl($mainParams, string $addParams, string $script = ''): string
    {
        if (!is_array($mainParams)) {
            $mainParams = ['id' => $mainParams];
        }
        if (!$script) {
            $script = basename(Environment::getCurrentScript());
        }
        if (GeneralUtility::_GP('route')) {
            /** @var Router $router */
            $router = GeneralUtility::makeInstance(Router::class);
            $route = $router->match(GeneralUtility::_GP('route'));
            /** @var UriBuilder $uriBuilder */
            $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
            $scriptUrl = (string)$uriBuilder->buildUriFromRoute($route->getOption('_identifier'));
            $scriptUrl .= $addParams;
        } elseif ($script === 'index.php' && GeneralUtility::_GET('M')) {
            $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
            $scriptUrl = $uriBuilder->buildUriFromRoute(GeneralUtility::_GET('M'), $mainParams) . $addParams;
        } else {
            $scriptUrl = $script . '?' . GeneralUtility::implodeArrayForUrl('', $mainParams) . $addParams;
        }
        return $scriptUrl;
    }

    /**
     * Checkbox function menu.
     * Works like ->getFuncMenu() but takes no $menuItem array since this is a simple checkbox.
     *
     * @param mixed $mainParams $id is the "&id=" parameter value to be sent to the module, but it can be also a parameter array which will be passed instead of the &id=...
     * @param string $elementName The form elements name, probably something like "SET[...]
     * @param string $currentValue The value to be selected currently.
     * @param string $script The script to send the &id to, if empty it's automatically found
     * @param string $addParams Additional parameters to pass to the script.
     * @param string $tagParams Additional attributes for the checkbox input tag
     * @param string $label
     * @return array
     * @throws ResourceNotFoundException
     * @throws RouteNotFoundException
     * @see getFuncMenu()
     */
    public static function getFuncCheck(
        $mainParams,
        string $elementName,
        string $currentValue,
        string $script = '',
        string $addParams = '',
        string $tagParams = '',
        string $label = ''
    ): array {
        $scriptUrl = self::buildScriptUrl($mainParams, $addParams, $script);
        $onClick = 'jumpToUrl(' . GeneralUtility::quoteJSvalue($scriptUrl . '&' . $elementName . '=') . '+(this.checked?1:0),this);';

        return [
            'onClick' => $onClick,
            'elementName' => $elementName,
            'checked' => ($currentValue ? ' checked="checked"' : ''),
            'tagParams' => ($tagParams ? ' ' . $tagParams : ''),
            'label' => htmlspecialchars($label),
        ];
    }

    /**
     * Creating module content
     *
     * @throws ResourceNotFoundException
     * @throws RouteNotFoundException
     */
    protected function moduleContent(L10nConfiguration $l10NConfiguration): array
    {
        $subcontent = [];

        switch ($this->MOD_SETTINGS['action'] ?? '') {
            case 'inlineEdit':
            case 'link':
                $subcontent = $this->linkOverviewAndOnlineTranslationAction($l10NConfiguration, $subcontent);
                break;
            case 'export_excel':
                $subcontent = $this->excelExportImportAction($l10NConfiguration);
                break;
            case 'export_xml':
                $subcontent = $this->exportImportXmlAction($l10NConfiguration);
                break;
        }

        return $subcontent;
    }

    /**
     * @param L10nConfiguration $l10NConfiguration
     * @return array
     */
    protected function inlineEditAction(L10nConfiguration $l10NConfiguration): array
    {
        // simple init of translation object:
        /** @var TranslationData $translationData */
        $translationData = GeneralUtility::makeInstance(TranslationData::class);
        $translationData->setTranslationData((array)GeneralUtility::_POST('translation'));
        $translationData->setLanguage($this->sysLanguage);
        $translationData->setPreviewLanguage($this->previewLanguage);
        // See, if incoming translation is available, if so, submit it
        if (GeneralUtility::_POST('saveInline')) {
            $this->l10nBaseService->saveTranslation($l10NConfiguration, $translationData);
        }

        // Buttons:
        $info = [];
        $info['saveConfirmation'] = 'return confirm(\'' . $this->getLanguageService()->getLL('inlineedit.save.alert.title') . '\');';
        $info['cancelConfirmation'] = 'return confirm(\'' . $this->getLanguageService()->getLL('inlineedit.cancel.alert.title') . '\');';

        return $info;
    }

    protected function makePreviewLanguageMenu(): array
    {
        $selectOptions = ['0' => '-default-'];
        $selectOptions += $this->MOD_MENU['lang'];

        // @extensionScannerIgnoreLine
        return self::getFuncMenu(
            $this->id,
            'export_xml_forcepreviewlanguage',
            (string)$this->previewLanguage,
            $selectOptions,
            '',
            '',
            $this->getLanguageService()->getLL('export.xml.source-language.title')
        );
    }

    /**
     * @param L10nConfiguration $l10nConfiguration
     * @return array
     * @throws ResourceNotFoundException
     * @throws RouteNotFoundException
     */
    protected function excelExportImportAction(L10nConfiguration $l10nConfiguration): array
    {
        $existingExportsOverview = '';
        $isImport = false;
        $importSuccess = false;
        $internalFlashMessage = '';
        $flashMessageHtml = '';
        $messagePlaceholder = '###MESSAGE###';
        $flashMessageRenderer = GeneralUtility::makeInstance(FlashMessageRendererResolver::class);

        $importAsDefaultLanguage = GeneralUtility::_POST('import_asdefaultlanguage');
        $importExcel = GeneralUtility::_POST('import_excel');
        $exportExcel = GeneralUtility::_POST('export_excel');
        $checkExports = GeneralUtility::_POST('check_exports');

        if ($importAsDefaultLanguage == '1') {
            $this->l10nBaseService->setImportAsDefaultLanguage(true);
        }

        // Read uploaded file:
        if ($importExcel && !empty($_FILES['uploaded_import_file']['tmp_name']) && is_uploaded_file($_FILES['uploaded_import_file']['tmp_name'])) {
            $isImport = true;
            $uploadedTempFile = GeneralUtility::upload_to_tempfile($_FILES['uploaded_import_file']['tmp_name']);
            /** @var TranslationDataFactory $factory */
            $factory = GeneralUtility::makeInstance(TranslationDataFactory::class);
            // TODO: catch exception
            $translationData = $factory->getTranslationDataFromExcelXMLFile($uploadedTempFile);
            $translationData->setLanguage($this->sysLanguage);
            $translationData->setPreviewLanguage($this->previewLanguage);
            GeneralUtility::unlink_tempfile($uploadedTempFile);
            $this->l10nBaseService->saveTranslation($l10nConfiguration, $translationData);
            $importSuccess = true;

            $status = AbstractMessage::INFO;
            $flashMessageData = [
                'message' => $messagePlaceholder,
                'title' => $this->getLanguageService()->getLL('import.success.message'),
                'severity' => $status,
            ];
            $flashMessage = FlashMessage::createFromArray($flashMessageData);
            $flashMessageHtml = str_replace(
                $messagePlaceholder,
                '',
                $flashMessageRenderer->resolve()->render([$flashMessage])
            );
        }

        // If export of XML is asked for, do that (this will exit and push a file for download)
        if ($exportExcel) {
            // Render the XML
            /** @var ExcelXmlView $viewClass */
            $viewClass = GeneralUtility::makeInstance(ExcelXmlView::class, $l10nConfiguration, $this->sysLanguage);
            $export_xml_forcepreviewlanguage = (int)GeneralUtility::_POST('export_xml_forcepreviewlanguage');

            if ($export_xml_forcepreviewlanguage > 0) {
                $viewClass->setForcedSourceLanguage($export_xml_forcepreviewlanguage);
            }

            if ($this->MOD_SETTINGS['onlyChangedContent'] ?? false) {
                $viewClass->setModeOnlyChanged();
            }

            if ($this->MOD_SETTINGS['noHidden'] ?? false) {
                $viewClass->setModeNoHidden();
            }

            // Check the export
            if ($checkExports && $viewClass->checkExports()) {
                $status = AbstractMessage::INFO;
                $flashMessageData = [
                    'message' => $messagePlaceholder,
                    'title' => $this->getLanguageService()->getLL('export.process.duplicate.title'),
                    'severity' => $status,
                ];
                $flashMessage = FlashMessage::createFromArray($flashMessageData);
                $flashMessageHtml = str_replace(
                    $messagePlaceholder,
                    $this->getLanguageService()->getLL('export.process.duplicate.message'),
                    $flashMessageRenderer->resolve()->render([$flashMessage])
                );

                $existingExportsOverview = $viewClass->renderExports();
            } else {
                try {
                    // Prepare a success message for display
                    $title = $this->getLanguageService()->getLL('export.download.success');
                    $status = AbstractMessage::OK;

                    $flashMessageData = [
                        'message' => $messagePlaceholder,
                        'title' => $title,
                        'severity' => $status,
                    ];
                    $flashMessage = FlashMessage::createFromArray($flashMessageData);

                    $filename = $this->downloadXML($viewClass);
                    $link = sprintf('<a href="%s" target="_blank">%s</a>', $filename, $filename);
                    $flashMessageHtml = str_replace(
                        $messagePlaceholder,
                        sprintf($this->getLanguageService()->getLL('export.download.success.detail'), $link),
                        $flashMessageRenderer->resolve()->render([$flashMessage])
                    );
                } catch (Exception $e) {
                    // Prepare an error message for display
                    $status = AbstractMessage::ERROR;
                    $flashMessageData = [
                        'message' => $messagePlaceholder,
                        'title' => $this->getLanguageService()->getLL('export.download.error'),
                        'severity' => $status,
                    ];
                    $flashMessage = FlashMessage::createFromArray($flashMessageData);
                    $flashMessageHtml = str_replace(
                        $messagePlaceholder,
                        $e->getMessage() . ' (' . $e->getCode() . ')',
                        $flashMessageRenderer->resolve()->render([$flashMessage])
                    );
                }
                $internalFlashMessage = $viewClass->renderInternalMessagesAsFlashMessage((string) $status);
                $viewClass->saveExportInformation();
            }
        }

        return [
            'existingExportsOverview' => $existingExportsOverview,
            'isImport' => $isImport,
            'importSuccess' => $importSuccess,
            'previewLanguageMenu' => $this->makePreviewLanguageMenu(),
            'flashMessageHtml' => $flashMessageHtml,
            'internalFlashMessage' => $internalFlashMessage,
        ];
    }

    /**
     * Sends download header and calls render method of the view.
     * Used for excelXML and CATXML.
     *
     * @param ExportViewInterface $xmlView Object for generating the XML export
     *
     * @return string $filename
     * @throws SiteNotFoundException
     */
    protected function downloadXML(ExportViewInterface $xmlView): string
    {
        // Save content to the disk and get the file name
        return $xmlView->render();
    }

    /**
     * @param L10nConfiguration $l10nConfiguration
     * @return array
     * @throws RouteNotFoundException
     * @throws DBALException
     * @throws \Doctrine\DBAL\Driver\Exception
     * @throws \Doctrine\DBAL\Exception
     * @throws \TYPO3\CMS\Core\Exception
     */
    protected function catXMLExportImportAction(L10nConfiguration $l10nConfiguration): array
    {
        $internalFlashMessage = '';
        $messagePlaceholder = '###MESSAGE###';
        $flashMessageRenderer = GeneralUtility::makeInstance(FlashMessageRendererResolver::class);
        $existingExportsOverview = '';
        $flashMessages = [];

        $importXml = GeneralUtility::_POST('import_xml');
        $exportXml = GeneralUtility::_POST('export_xml');
        $importAsDefaultLanguage = (bool)(GeneralUtility::_POST('import_asdefaultlanguage') ?? false);
        $deleteLocalizationsBeforeImport = (bool)(GeneralUtility::_POST('import_delL10N') ?? false);
        $checkExports = (bool)(GeneralUtility::_POST('check_exports') ?? false);

        // Read uploaded file:
        if ($importXml && !empty($_FILES['uploaded_import_file']['tmp_name']) && is_uploaded_file($_FILES['uploaded_import_file']['tmp_name'])) {
            $uploadedTempFile = GeneralUtility::upload_to_tempfile($_FILES['uploaded_import_file']['tmp_name']);
            /** @var TranslationDataFactory $factory */
            $factory = GeneralUtility::makeInstance(TranslationDataFactory::class);

            if ($importAsDefaultLanguage) {
                $this->l10nBaseService->setImportAsDefaultLanguage(true);
            }

            // Relevant processing of XML Import with the help of the Importmanager
            /** @var CatXmlImportManager $importManager */
            $importManager = GeneralUtility::makeInstance(
                CatXmlImportManager::class,
                $uploadedTempFile,
                $this->sysLanguage,
                $xmlString = ''
            );

            if ($importManager->parseAndCheckXMLFile() === false) {
                $status = AbstractMessage::ERROR;
                $flashMessageData = [
                    'message' => $messagePlaceholder,
                    'title' => $this->getLanguageService()->getLL('import.error.title'),
                    'severity' => $status,
                ];
                $flashMessage = FlashMessage::createFromArray($flashMessageData);
                $flashMessages[] = str_replace(
                    $messagePlaceholder,
                    $importManager->getErrorMessages(),
                    $flashMessageRenderer->resolve()->render([$flashMessage]),
                );
            } else {
                if ($deleteLocalizationsBeforeImport) {
                    $delCount = $importManager->delL10N($importManager->getDelL10NDataFromCATXMLNodes($importManager->getXMLNodes()));
                    $message = sprintf(
                        $this->getLanguageService()->getLL('import.xml.delL10N.count.message'),
                        $delCount
                    );

                    $status = AbstractMessage::INFO;
                    $flashMessageData = [
                        'message' => $messagePlaceholder,
                        'title' => $this->getLanguageService()->getLL('import.xml.delL10N.message'),
                        'severity' => $status,
                    ];
                    $flashMessage = FlashMessage::createFromArray($flashMessageData);
                    $flashMessages[] = str_replace(
                        $messagePlaceholder,
                        $message,
                        $flashMessageRenderer->resolve()->render([$flashMessage]),
                    );
                }
                if (GeneralUtility::_POST('make_preview_link') == '1' && ExtensionManagementUtility::isLoaded('workspaces')) {
                    $pageIds = $importManager->getPidsFromCATXMLNodes($importManager->getXMLNodes());
                    $actionInfo .= '<b>' . $this->getLanguageService()->getLL('import.xml.preview_links.title') . '</b><br />';
                    /** @var MkPreviewLinkService $mkPreviewLinks */
                    $mkPreviewLinks = GeneralUtility::makeInstance(
                        MkPreviewLinkService::class,
                        $importManager->headerData['t3_workspaceId'] ?? 0,
                        $importManager->headerData['t3_sysLang'] ?? 0,
                        $pageIds
                    );
                    $actionInfo .= $mkPreviewLinks->renderPreviewLinks($mkPreviewLinks->mkPreviewLinks());
                }
                if (!empty($importManager->headerData['t3_sourceLang']) && !empty($importManager->headerData['t3_targetLang'])
                    && $importManager->headerData['t3_sourceLang'] === $importManager->headerData['t3_targetLang']) {
                    $this->previewLanguage = $this->sysLanguage;
                }
                $translationData = $factory->getTranslationDataFromCATXMLNodes($importManager->getXMLNodes());
                $translationData->setLanguage($this->sysLanguage);
                $translationData->setPreviewLanguage($this->previewLanguage);

                unset($importManager);

                $this->l10nBaseService->saveTranslation($l10nConfiguration, $translationData);

                $status = AbstractMessage::OK;
                $flashMessageData = [
                    'message' => $messagePlaceholder,
                    'title' => $this->getLanguageService()->getLL('general.import.done'),
                    'severity' => $status,
                ];
                $flashMessage = FlashMessage::createFromArray($flashMessageData);
                $flashMessages[] = str_replace(
                    $messagePlaceholder,
                    'Command count:' . $this->l10nBaseService->lastTCEMAINCommandsCount,
                    $flashMessageRenderer->resolve()->render([$flashMessage]),
                );
            }
            GeneralUtility::unlink_tempfile($uploadedTempFile);
        }

        // If export of XML is asked for, do that (this will exit and push a file for download, or upload to FTP is option is checked)
        if ($exportXml) {
            // Render the XML
            /** @var CatXmlView $viewClass */
            $viewClass = GeneralUtility::makeInstance(CatXmlView::class, $l10nConfiguration, $this->sysLanguage);
            $export_xml_forcepreviewlanguage = (int)GeneralUtility::_POST('export_xml_forcepreviewlanguage');

            if ($export_xml_forcepreviewlanguage > 0) {
                $viewClass->setForcedSourceLanguage($export_xml_forcepreviewlanguage);
            }

            if ($this->MOD_SETTINGS['onlyChangedContent'] ?? false) {
                $viewClass->setModeOnlyChanged();
            }

            if ($this->MOD_SETTINGS['noHidden'] ?? false) {
                $viewClass->setModeNoHidden();
            }

            // Check the export
            if ($checkExports && $viewClass->checkExports()) {
                $status = AbstractMessage::INFO;
                $flashMessageData = [
                    'message' => $messagePlaceholder,
                    'title' => $this->getLanguageService()->getLL('export.process.duplicate.title'),
                    'severity' => $status,
                ];
                $flashMessage = FlashMessage::createFromArray($flashMessageData);
                $flashMessages[] = str_replace(
                    $messagePlaceholder,
                    $this->getLanguageService()->getLL('export.process.duplicate.message'),
                    $flashMessageRenderer->resolve()->render([$flashMessage]),
                );
                $existingExportsOverview = $viewClass->renderExports();
            } else {
                // Upload to FTP
                if (GeneralUtility::_POST('ftp_upload') == '1') {
                    try {
                        $filename = $this->uploadToFtp($viewClass);

                        // Send a mail notification
                        /** @var NotificationService $notificationService */
                        $notificationService = GeneralUtility::makeInstance(NotificationService::class);
                        $notificationService->sendMail($filename, $l10nConfiguration, $this->sysLanguage, $this->emConfiguration);

                        // Prepare a success message for display
                        $status = AbstractMessage::OK;
                        $flashMessageData = [
                            'message' => $messagePlaceholder,
                            'title' => $this->getLanguageService()->getLL('export.ftp.success'),
                            'severity' => $status,
                        ];
                        $flashMessage = FlashMessage::createFromArray($flashMessageData);
                        $flashMessages[] = str_replace(
                            $messagePlaceholder,
                            sprintf(
                                $this->getLanguageService()->getLL('export.ftp.success.detail'),
                                $this->emConfiguration->getFtpServerPath() . $filename
                            ),
                            $flashMessageRenderer->resolve()->render([$flashMessage])
                        );
                    } catch (Exception $e) {
                        // Prepare an error message for display
                        $status = AbstractMessage::ERROR;
                        $flashMessageData = [
                            'message' => $messagePlaceholder,
                            'title' => $this->getLanguageService()->getLL('export.ftp.error'),
                            'severity' => $status,
                        ];
                        $flashMessage = FlashMessage::createFromArray($flashMessageData);
                        $flashMessages[] = str_replace(
                            $messagePlaceholder,
                            $e->getMessage() . ' (' . $e->getCode() . ')',
                            $flashMessageRenderer->resolve()->render([$flashMessage])
                        );
                    }
                    // Download the XML file
                } else {
                    try {
                        $filename = $this->downloadXML($viewClass);
                        // Prepare a success message for display
                        $link = sprintf('<a href="%s" target="_blank">%s</a>', $filename, $filename);
                        $status = AbstractMessage::OK;
                        $flashMessageData = [
                            'message' => $messagePlaceholder,
                            'title' => $this->getLanguageService()->getLL('export.download.success'),
                            'severity' => $status,
                        ];
                        $flashMessage = FlashMessage::createFromArray($flashMessageData);
                        $flashMessages[] = str_replace(
                            $messagePlaceholder,
                            sprintf($this->getLanguageService()->getLL('export.download.success.detail'), $link),
                            $flashMessageRenderer->resolve()->render([$flashMessage])
                        );
                    } catch (Exception $e) {
                        // Prepare an error message for display
                        $status = AbstractMessage::ERROR;
                        $flashMessageData = [
                            'message' => $messagePlaceholder,
                            'title' => $this->getLanguageService()->getLL('export.download.error'),
                            'severity' => $status,
                        ];
                        $flashMessage = FlashMessage::createFromArray($flashMessageData);
                        $flashMessages[] = str_replace(
                            $messagePlaceholder,
                            $e->getMessage() . ' (' . $e->getCode() . ')',
                            $flashMessageRenderer->resolve()->render([$flashMessage])
                        );
                    }
                }

                $internalFlashMessage = $viewClass->renderInternalMessagesAsFlashMessage((string)$status);

                $viewClass->saveExportInformation();
            }
        }

        return [
            'settingsFiles' => $this->getTabContentXmlDownloads(),
            'existingExportsOverview' => $existingExportsOverview,
            'flashMessages' => $flashMessages,
            'internalFlashMessage' => $internalFlashMessage,
            'previewLanguageMenu' => $this->makePreviewLanguageMenu(),
            'workspacesLoaded' => ExtensionManagementUtility::isLoaded('workspaces')
        ];
    }

    /**
     * @return array
     * @throws RouteNotFoundException
     */
    protected function getTabContentXmlDownloads(): array
    {
        $files = [];
        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
        foreach ($this->settings as $settingId => $settingFileName) {
            $absoluteFileName = GeneralUtility::getFileAbsFileName('EXT:l10nmgr/Configuration/Settings/' . $settingFileName);
            if (is_file($absoluteFileName) && is_readable($absoluteFileName)) {
                $size = GeneralUtility::formatSize((int)filesize($absoluteFileName), ' Bytes| KB| MB| GB');
                $href = $uriBuilder->buildUriFromRoute('download_setting', ['setting' => $settingId]);
                $label = $this->getLanguageService()->getLL('file.settings.' . $settingId . '.title') . ' (' . $size . ')';

                $files[$settingId] = [
                    'absoluteFilename' => GeneralUtility::getFileAbsFileName('EXT:l10nmgr/Configuration/Settings/' . $settingFileName),
                    'href' => $href,
                    'label' => $label,
                ];
            }
        }

        return $files;
    }

    /**
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function downloadSetting(ServerRequestInterface $request): ResponseInterface
    {
        $settingId = $request->getQueryParams()['setting'];
        $absoluteFileName = GeneralUtility::getFileAbsFileName('EXT:l10nmgr/Configuration/Settings/' . $this->getSetting($settingId));

        $body = new Stream('php://temp', 'wb+');
        $body->write(file_get_contents($absoluteFileName));
        $body->rewind();
        return (new Response())
            ->withAddedHeader('Content-Length', (string)(filesize($absoluteFileName) ?: ''))
            ->withAddedHeader('Content-Disposition', 'attachment; filename="' . PathUtility::basename($absoluteFileName) . '"')
            ->withBody($body);
    }

    /**
     * @param string $key
     * @return string
     */
    protected function getSetting(string $key): string
    {
        return $this->settings[$key] ?? '';
    }

    /**
     * Uploads the XML export to the FTP server
     *
     * @param CatXmlView $xmlView Object for generating the XML export
     *
     * @return string The file name, if successful
     * @throws Exception
     */
    protected function uploadToFtp(CatXmlView $xmlView): string
    {
        // Save content to the disk and get the file name
        $filename = $xmlView->render();
        $xmlFileName = basename($filename);
        // Try connecting to FTP server and uploading the file
        // If any step fails, an exception is thrown
        $connection = ftp_connect($this->emConfiguration->getFtpServer());
        if ($connection) {
            if (@ftp_login(
                $connection,
                $this->emConfiguration->getFtpServerUsername(),
                $this->emConfiguration->getFtpServerPassword()
            )) {
                if (ftp_put(
                    $connection,
                    $this->emConfiguration->getFtpServerPath() . $xmlFileName,
                    Environment::getPublicPath() . '/' . $filename,
                    FTP_BINARY
                )) {
                    ftp_close($connection);
                } else {
                    ftp_close($connection);
                    throw new Exception(sprintf(
                        $this->getLanguageService()->getLL('export.ftp.upload_failed'),
                        $filename,
                        $this->emConfiguration->getFtpServerPath()
                    ), 1326906926);
                }
            } else {
                ftp_close($connection);
                throw new Exception(sprintf(
                    $this->getLanguageService()->getLL('export.ftp.login_failed'),
                    $this->emConfiguration->getFtpServerUsername()
                ), 1326906772);
            }
        } else {
            throw new Exception($this->getLanguageService()->getLL('export.ftp.connection_failed'), 1326906675);
        }
        // If everything went well, return the file's base name
        return $xmlFileName;
    }

    /**
     * Adds items to the ->MOD_MENU array. Used for the function menu selector.
     */
    public function menuConfig(): void
    {
        $this->MOD_MENU = [
            'action' => [
                '' => $this->getLanguageService()->getLL('general.action.blank.title'),
                'link' => $this->getLanguageService()->getLL('general.action.edit.link.title'),
                'inlineEdit' => $this->getLanguageService()->getLL('general.action.edit.inline.title'),
                'export_excel' => $this->getLanguageService()->getLL('general.action.export.excel.title'),
                'export_xml' => $this->getLanguageService()->getLL('general.action.export.xml.title'),
            ],
            'lang' => [],
            'onlyChangedContent' => '',
            'check_exports' => 1,
            'noHidden' => '',
        ];

        $configurationId = (int)($GLOBALS['TYPO3_REQUEST']->getQueryParams()['exportUID'] ?? 0);
        $configuration = BackendUtility::getRecord('tx_l10nmgr_cfg', $configurationId);
        $targetLanguages = [];
        if (!empty($configuration['targetLanguages'])) {
            $targetLanguages = array_flip(GeneralUtility::intExplode(',', $configuration['targetLanguages'], true));
        }

        // TODO: Migrate to SiteConfiguration
        // Load system languages into menu and check against allowed languages:
        /** @var TranslationConfigurationProvider $t8Tools */
        $t8Tools = GeneralUtility::makeInstance(TranslationConfigurationProvider::class);
        $systemLanguages = $t8Tools->getSystemLanguages();
        foreach ($systemLanguages as $systemLanguage) {
            if (!empty($targetLanguages) && !isset($targetLanguages[$systemLanguage['uid']])) {
                continue;
            }
            if (empty($this->MOD_MENU['lang'])) {
                $this->MOD_MENU['lang'] = [];
            }
            if ($systemLanguage['uid'] > 0
                && $this->emConfiguration->isEnableHiddenLanguages()
                && $this->getBackendUser()->checkLanguageAccess($systemLanguage['uid'])
            ) {
                $this->MOD_MENU['lang'][$systemLanguage['uid']] = $systemLanguage['title'];
            }
        }
        parent::menuConfig();
    }

    /**
     * @return StandaloneView
     */
    protected function getFluidTemplateObject(): StandaloneView
    {
        $view = GeneralUtility::makeInstance(StandaloneView::class);
        $view->setLayoutRootPaths([GeneralUtility::getFileAbsFileName('EXT:l10nmgr/Resources/Private/Layouts')]);
        $view->setPartialRootPaths([GeneralUtility::getFileAbsFileName('EXT:l10nmgr/Resources/Private/Partials')]);
        $view->setTemplateRootPaths([GeneralUtility::getFileAbsFileName('EXT:l10nmgr/Resources/Private/Templates')]);

        $view->setTemplatePathAndFilename(GeneralUtility::getFileAbsFileName('EXT:l10nmgr/Resources/Private/Templates/LocalizationManager/Index.html'));

        $view->getRequest()->setControllerExtensionName('l10nmgr');

        return $view;
    }

    /**
     * @return L10nConfiguration
     */
    protected function getL10NConfiguration(): L10nConfiguration
    {
        /** @var L10nConfiguration $l10nConfiguration */
        $l10nConfiguration = GeneralUtility::makeInstance(L10nConfiguration::class);
        $l10nConfiguration->load((int)GeneralUtility::_GP('exportUID'));

        return $l10nConfiguration;
    }

    /**
     * @param L10nConfiguration $l10NConfiguration
     * @param array $result
     * @return array
     */
    protected function linkOverviewAndOnlineTranslationAction(
        L10nConfiguration $l10NConfiguration,
        array $result
    ): array {
        /** @var L10nHTMLListView $htmlListView */
        $htmlListView = GeneralUtility::makeInstance(
            L10nHtmlListView::class,
            $l10NConfiguration,
            $this->sysLanguage,
            $this->moduleTemplate,
        );
        $action = $this->MOD_SETTINGS['action'] ?? '';
        // Render the module content (for all modes):
        if ($action === 'inlineEdit') {
            $result['inlineEdit'] = $this->inlineEditAction($l10NConfiguration);
            $htmlListView->setModeWithInlineEdit();
        }
        //*******************************************
        if ($this->MOD_SETTINGS['onlyChangedContent'] ?? false) {
            $htmlListView->setModeOnlyChanged();
        }
        if ($this->MOD_SETTINGS['noHidden'] ?? false) {
            $htmlListView->setModeNoHidden();
        }
        if ($action === 'link') {
            $htmlListView->setModeShowEditLinks();
        }

        return [
            'sections' => $htmlListView->renderOverview(),
            'inlineEdit' => $result['inlineEdit'] ?? [],
        ];
    }

    /**
     * @param L10nConfiguration $l10NConfiguration
     * @return array
     */
    protected function exportImportXmlAction(L10nConfiguration $l10NConfiguration): array
    {
        $prefs['utf8'] = GeneralUtility::_POST('check_utf8');
        $prefs['noxmlcheck'] = GeneralUtility::_POST('no_check_xml');
        $prefs['check_exports'] = GeneralUtility::_POST('check_exports');
        $this->getBackendUser()->pushModuleData('l10nmgr/cm1/prefs', $prefs);

        return $this->catXMLExportImportAction($l10NConfiguration);
    }

    /**
     * @param L10nConfiguration $l10nConfiguration
     * @return array
     */
    protected function renderConfigurationTable(L10nConfiguration $l10nConfiguration): array
    {
        /** @var L10nConfigurationDetailView $l10nmgrconfigurationView */
        $l10nmgrconfigurationView = GeneralUtility::makeInstance(
            L10nConfigurationDetailView::class,
            $l10nConfiguration
        );

        return $l10nmgrconfigurationView->render();
    }
}
