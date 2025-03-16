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
/**
 * Module 'L10N Manager' for the 'l10nmgr' extension.
 *
 * @author Kasper Skårhøj <kasperYYYY@typo3.com>
 */

use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Exception as DBALException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;

/**
 * Translation management tool
 *
 * @authorKasper Skaarhoj <kasperYYYY@typo3.com>
 * @author Jo Hasenau <info@cybercraft.de>
 * @author Stefano Kowalke <info@arroba-it.de>
 */
class ConfigurationManager extends BaseModule
{
    public array $pageinfo = [];

    /**
     * @var array Cache of the page details already fetched from the database
     */
    protected array $pageDetails = [];

    protected ModuleTemplate $moduleTemplate;

    protected StandaloneView $view;

    public function __construct(
        protected readonly IconFactory $iconFactory,
        protected readonly ModuleTemplateFactory $moduleTemplateFactory,
        protected readonly UriBuilder $uriBuilder,
    ) {
        $this->getLanguageService()
            ->includeLLFile('EXT:l10nmgr/Resources/Private/Language/Modules/ConfigurationManager/locallang.xlf');
    }

    /**
     * Injects the request object for the current request or subrequest
     * Then checks for module functions that have hooked in, and renders menu etc.
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface the response with the content
     * @throws DBALException
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
     * Main function of the module. Write the content to $this->content
     * If you chose "web" as main module, you will need to consider the $this->id parameter which will contain the uid-number of the page clicked in the page tree
     * @throws DBALException
     * @throws RouteNotFoundException
     */
    public function main(): void
    {
        $backendUser = $this->getBackendUser();

        // The page will show only if there is a valid page and if this page
        // may be viewed by the user
        // @extensionScannerIgnoreLine
        $this->pageinfo = BackendUtility::readPageAccess(
            $this->id,
            $backendUser->getPagePermsClause(Permission::PAGE_SHOW)
        );
        if ($this->pageinfo) {
            $this->moduleTemplate->getDocHeaderComponent()->setMetaInformation($this->pageinfo);
        }

        $access = is_array($this->pageinfo);
        // @extensionScannerIgnoreLine
        if (($this->id && $access) || ($backendUser->isAdmin() && ! $this->id)) {
            // @extensionScannerIgnoreLine
            if (!$this->id && $backendUser->isAdmin()) {
                $this->pageinfo = ['title' => '[root-level]', 'uid' => 0, 'pid' => 0];
            }
            $this->view = $this->getFluidTemplateObject();
            $this->view->assign('configurations', $this->getContent());
            $this->content .= $this->view->render();
        }
    }

    /**
     * Generates and returns the content of the module
     *
     * @throws DBALException
     * @throws RouteNotFoundException
     */
    protected function getContent(): array
    {
        // Get the available configurations
        $l10nConfigurations = $this->getAllConfigurations();
        foreach ($l10nConfigurations as $key => $l10nConfiguation) {
            $l10nConfigurations[$key]['link'] = (string)$this->uriBuilder->buildUriFromRoute('LocalizationManager', [
                    'id' => $l10nConfiguation['pid'] ?? 0,
                    // @extensionScannerIgnoreLine
                    'srcPID' => $this->id,
                    'exportUID' => $l10nConfiguation['uid'] ?? 0,
                ]);
            $pagePath = BackendUtility::getRecordPath($l10nConfiguation['pid'] ?? 0, '1', 20, 50);
            $l10nConfigurations[$key]['path'] = (is_array($pagePath)) ? ($pagePath[1] ?? '') : $pagePath;
        }

        return $l10nConfigurations;
    }

    /**
     * Returns all l10nmgr configurations to which the current user has access, based on page permissions
     *
     * @return array List of l10nmgr configurations
     * @throws DBALException
     */
    protected function getAllConfigurations(): array
    {
        // Read all l10nmgr configurations from the database
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_l10nmgr_cfg');
        $configurations = $queryBuilder->select('*')
            ->from('tx_l10nmgr_cfg')
            ->orderBy('title')
            ->executeQuery()
            ->fetchAllAssociative();
        // Filter out the configurations which the user is allowed to see, base on the page access rights
        $pagePermissionsClause = $this->getBackendUser()->getPagePermsClause(1);
        $allowedConfigurations = [];
        foreach ($configurations as $row) {
            if (BackendUtility::readPageAccess($row['pid'], $pagePermissionsClause) !== false) {
                $allowedConfigurations[] = $row;
            }
        }
        return $allowedConfigurations;
    }

    /**
     * Renders a detailed view of a l10nmgr configuration
     *
     * @param array $configuration A configuration record from the database
     *
     * @return string The HTML to display
     */
    protected function renderConfigurationDetails(array $configuration): string
    {
        $parentPageArray = $this->getPageDetails($configuration['pid'] ?? 0);
        $languageArray = $this->getPageDetails($configuration['sourceLangStaticId'] ?? 0);
        $details = '<table class="table table-striped table-hover" border="0" cellspacing="0" cellpadding="0">';
        $details .= '<tr>';
        $details .= '<td>' . $this->getLanguageService()->getLL('general.list.infodetail.pid.title') . '</td>';
        $details .= '<td>' . $parentPageArray['title'] ?? '' . ' (' . $parentPageArray['uid'] ?? 0 . ')</td>';
        $details .= '</tr><tr class="db_list_normal">';
        $details .= '<td>' . $this->getLanguageService()->getLL('general.list.infodetail.title.title') . '</td>';
        $details .= '<td>' . $configuration['title'] ?? '' . '</td>';
        $details .= '</tr><tr class="db_list_normal">';
        $details .= '<td>' . $this->getLanguageService()->getLL('general.list.infodetail.filenameprefix.title') . '</td>';
        $details .= '<td>' . $configuration['filenameprefix'] ?? '' . '</td>';
        $details .= '</tr><tr class="db_list_normal">';
        $details .= '<td>' . $this->getLanguageService()->getLL('general.list.infodetail.depth.title') . '</td>';
        $details .= '<td>' . $configuration['depth'] ?? '' . '</td>';
        $details .= '</tr><tr class="db_list_normal">';
        $details .= '<td>' . $this->getLanguageService()->getLL('general.list.infodetail.sourceLangStaticId.title') . '</td>';
        $details .= '<td>' . ((empty($languageArray['lg_name_en'])) ? $this->getLanguageService()->getLL('general.list.infodetail.default') : $languageArray['lg_name_en']) . '</td>';
        $details .= '</tr><tr class="db_list_normal">';
        $details .= '<td>' . $this->getLanguageService()->getLL('general.list.infodetail.tablelist.title') . '</td>';
        $details .= '<td>' . $configuration['tablelist'] ?? '' . '</td>';
        $details .= '</tr><tr class="db_list_normal">';
        $details .= '<td>' . $this->getLanguageService()->getLL('general.list.infodetail.exclude.title') . '</td>';
        $details .= '<td>' . $configuration['exclude'] ?? '' . '</td>';
        $details .= '</tr><tr class="db_list_normal">';
        $details .= '<td>' . $this->getLanguageService()->getLL('general.list.infodetail.include.title') . '</td>';
        $details .= '<td>' . $configuration['include'] ?? '' . '</td>';
        $details .= '</tr><tr class="db_list_normal">';
        $details .= '<td>' . $this->getLanguageService()->getLL('general.list.infodetail.displaymode.title') . '</td>';
        $details .= '<td>' . $configuration['displaymode'] ?? '' . '</td>';
        $details .= '</tr>';
        $details .= '</table>';
        return $details;
    }

    /**
     * Returns the details of a given page record, possibly from cache if already fetched earlier
     *
     * @param int $uid Id of a page
     *
     * @return array Page record from the database
     */
    protected function getPageDetails(int $uid): array
    {
        if (isset($this->pageDetails[$uid])) {
            $record = $this->pageDetails[$uid];
        } else {
            $record = BackendUtility::getRecord('pages', $uid);
            $this->pageDetails[$uid] = $record;
        }
        return $record;
    }

    protected function getFluidTemplateObject(): StandaloneView
    {
        $view = GeneralUtility::makeInstance(StandaloneView::class);
        $view->setLayoutRootPaths([GeneralUtility::getFileAbsFileName('EXT:l10nmgr/Resources/Private/Layouts')]);
        $view->setPartialRootPaths([GeneralUtility::getFileAbsFileName('EXT:l10nmgr/Resources/Private/Partials')]);
        $view->setTemplateRootPaths([GeneralUtility::getFileAbsFileName('EXT:l10nmgr/Resources/Private/Templates')]);

        $view->setTemplatePathAndFilename(GeneralUtility::getFileAbsFileName('EXT:l10nmgr/Resources/Private/Templates/ConfigurationManager/Index.html'));

        $view->getRequest()->withControllerExtensionName('l10nmgr');

        return $view;
    }
}
