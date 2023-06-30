<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Model;

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

use Localizationteam\L10nmgr\Constants;
use Localizationteam\L10nmgr\Event\L10nAccumulatedInformationIsProcessed;
use Localizationteam\L10nmgr\LanguageRestriction\Collection\LanguageRestrictionCollection;
use Localizationteam\L10nmgr\Model\Dto\EmConfiguration;
use Localizationteam\L10nmgr\Model\Tools\Tools;
use Localizationteam\L10nmgr\Traits\BackendUserTrait;
use PDO;
use TYPO3\CMS\Backend\Tree\View\PageTreeView;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\EventDispatcher\EventDispatcher;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;

/**
 * l10nAccumulatedInformation
 * calculates accumulated information for a l10n.
 *Needs a tree object and a l10ncfg to work.
 * This object is a value object (means it has no identity and can therefore be created and deleted “everywhere”).
 * However this object should be generated by the relevant factory method in the l10nconfiguration object.
 * This object represents the relevant records which belongs to a l10ncfg in the concrete pagetree!
 * The main method is the getInfoArrayForLanguage() which returns the $accum Array with the accumulated informations.
 */
class L10nAccumulatedInformation
{
    use BackendUserTrait;

    /**
     * @var string The status of this object, set to processed if internal variables are calculated.
     */
    protected string $objectStatus = 'new';

    /**
     * @var PageTreeView
     */
    protected PageTreeView $tree;

    /**
     * @var array Selected l10nmgr configuration
     */
    protected array $l10ncfg = [];

    /**
     * @var array List of not allowed doktypes
     */
    protected array $disallowDoktypes = ['--div--', '255'];

    /**
     * @var int sys_language_uid of target language
     */
    protected int $sysLang;

    /**
     * @var int sys_language_uid of forced source language
     */
    protected int $forcedPreviewLanguage = 0;

    /**
     * @var bool
     */
    protected bool $noHidden;

    /**
     * @var array Information about collected data for translation
     */
    protected array $_accumulatedInformations = [];

    /**
     * @var int Field count, might be needed by translation agencies
     */
    protected int $_fieldCount = 0;

    /**
     * @var int Word count, might be needed by translation agencies
     */
    protected int $_wordCount = 0;

    /**
     * @var array Index of pages to be excluded from translation
     */
    protected array $excludeIndex = [];

    /**
     * @var array Index of pages to be included with translation
     */
    protected array $includeIndex = [];

    /**
     * @var EmConfiguration
     */
    protected EmConfiguration $emConfiguration;

    /**
     * Check for deprecated configuration throws false positive in extension scanner.
     *
     * @param PageTreeView $tree
     * @param array $l10ncfg
     * @param int $sysLang
     */
    public function __construct(PageTreeView $tree, array $l10ncfg, int $sysLang)
    {
        $this->emConfiguration = GeneralUtility::makeInstance(EmConfiguration::class);
        $this->disallowDoktypes = GeneralUtility::trimExplode(',', $this->emConfiguration->getDisallowDoktypes());
        $this->tree = $tree;
        $this->l10ncfg = $l10ncfg;
        $this->sysLang = $sysLang;
    }

    /**
     * @param int $prevLangId
     */
    public function setForcedPreviewLanguage(int $prevLangId): void
    {
        $this->forcedPreviewLanguage = $prevLangId;
    }

    /**
     * return information array with accumulated information.
     * This way client classes have access to the accumulated array directly.
     * And can read this array in order to create some output...
     *
     * @param bool $noHidden
     * @return array Complete Information array
     */
    public function getInfoArray(bool $noHidden = false): array
    {
        $this->noHidden = $noHidden;
        $this->process();
        return $this->_accumulatedInformations;
    }

    /**
     * @throws \Doctrine\DBAL\DBALException
     * @throws \TYPO3\CMS\Core\Exception\SiteNotFoundException
     */
    protected function process(): void
    {
        if ($this->objectStatus !== 'processed') {
            $this->_calculateInternalAccumulatedInformationsArray();
            $event = new L10nAccumulatedInformationIsProcessed($this->_accumulatedInformations, $this->l10ncfg);
            $eventDispatcher = GeneralUtility::makeInstance(EventDispatcher::class);
            $eventDispatcher->dispatch($event);
            $this->_accumulatedInformations = $event->getAccumulatedInformation();
        }
        $this->objectStatus = 'processed';
    }

    /** set internal _accumulatedInformation array.
     * Is called from constructor and uses the given tree, lang and l10ncfg
     *
     *
     * @throws \TYPO3\CMS\Core\Exception\SiteNotFoundException
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function _calculateInternalAccumulatedInformationsArray(): void
    {
        $tree = $this->tree;
        $l10ncfg = $this->l10ncfg;
        $accum = [];
        $sysLang = $this->sysLang;
        // FlexForm Diff data:
        $flexFormDiff = [];
        if (!empty($l10ncfg['flexformdiff'])) {
            $flexFormDiff = unserialize($l10ncfg['flexformdiff']);
            $flexFormDiff = $flexFormDiff[$sysLang] ?? [];
        }
        $this->excludeIndex = array_flip(GeneralUtility::trimExplode(',', $l10ncfg['exclude'] ?? '', true));
        // Init:
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        /** @var Tools $t8Tools */
        $t8Tools = GeneralUtility::makeInstance(Tools::class);
        $t8Tools->verbose = false; // Otherwise it will show records which has fields but none editable.
        if (!empty($l10ncfg['incfcewithdefaultlanguage'])) {
            $t8Tools->includeFceWithDefaultLanguage = true;
        }
        // Set preview language (only first one in list is supported):
        if ($this->forcedPreviewLanguage !== 0) {
            $previewLanguage = $this->forcedPreviewLanguage;
        } else {
            $previewLanguage = current(
                GeneralUtility::intExplode(
                    ',',
                    $this->getBackendUser()->getTSConfig()['options.']['additionalPreviewLanguages'] ?? ''
                )
            );
        }
        if ($previewLanguage) {
            if (!empty($l10ncfg['onlyForcedSourceLanguage'])) {
                $t8Tools->onlyForcedSourceLanguage = true;
            }
            $t8Tools->previewLanguages = [$previewLanguage];
        }

        $fileList = [];
        // Traverse tree elements:
        foreach ($tree->tree as $treeElement) {
            $pageId = $treeElement['row']['uid'] ?? 0;
            if (isset($treeElement['row']['l10nmgr_configuration'])) {
                if ($treeElement['row']['l10nmgr_configuration'] === Constants::L10NMGR_CONFIGURATION_DEFAULT) {
                    /** @var RootlineUtility $rootlineUtility */
                    $rootlineUtility = GeneralUtility::makeInstance(RootlineUtility::class, $pageId);
                    $rootline = $rootlineUtility->get();
                    if (!empty($rootline)) {
                        foreach ($rootline as $rootlinePage) {
                            if (isset($rootlinePage['l10nmgr_configuration_next_level'])) {
                                if ($rootlinePage['l10nmgr_configuration_next_level'] === Constants::L10NMGR_CONFIGURATION_DEFAULT) {
                                    continue;
                                }
                                if ($rootlinePage['l10nmgr_configuration_next_level'] === Constants::L10NMGR_CONFIGURATION_NONE || $rootlinePage['l10nmgr_configuration_next_level'] === Constants::L10NMGR_CONFIGURATION_INCLUDE) {
                                    break;
                                }
                                if ($rootlinePage['l10nmgr_configuration_next_level'] === Constants::L10NMGR_CONFIGURATION_EXCLUDE) {
                                    $this->excludeIndex['pages:' . $pageId] = 1;
                                    break;
                                }
                            }
                        }
                    }
                } elseif ($treeElement['row']['l10nmgr_configuration'] === Constants::L10NMGR_CONFIGURATION_EXCLUDE) {
                    $this->excludeIndex['pages:' . $pageId] = 1;
                }
            }
            if (!empty($treeElement['row'][Constants::L10NMGR_LANGUAGE_RESTRICTION_FIELDNAME])) {
                /** @var LanguageRestrictionCollection $languageIsRestricted */
                $languageIsRestricted = LanguageRestrictionCollection::load(
                    $sysLang,
                    true,
                    'pages',
                    Constants::L10NMGR_LANGUAGE_RESTRICTION_FIELDNAME
                );
                if ($languageIsRestricted->hasItem((int)$pageId)) {
                    $this->excludeIndex['pages:' . $pageId] = 1;
                    continue;
                }
            }
            if (!isset($this->excludeIndex['pages:' . $pageId]) && !in_array(
                $treeElement['row']['doktype'] ?? '',
                $this->disallowDoktypes
            )
            ) {
                $accum[$pageId]['header']['title'] = $treeElement['row']['title'] ?? '';
                $accum[$pageId]['header']['icon'] = $treeElement['HTML'] ?? '';
                $accum[$pageId]['header']['prevLang'] = $previewLanguage;
                $accum[$pageId]['header']['url'] = (string)$siteFinder->getSiteByPageId($pageId)->getRouter()->generateUri($pageId);
                $accum[$pageId]['items'] = [];
                // Traverse tables:
                if (!empty($GLOBALS['TCA'])) {
                    foreach ($GLOBALS['TCA'] as $table => $cfg) {
                        // Only those tables we want to work on:
                        if (GeneralUtility::inList($l10ncfg['tablelist'] ?? '', $table)) {
                            if ($table === 'pages') {
                                $row = BackendUtility::getRecordWSOL('pages', $pageId);
                                if ($t8Tools->canUserEditRecord($table, $row)) {
                                    $accum[$pageId]['items'][$table][$pageId] = $t8Tools->translationDetails(
                                        'pages',
                                        $row,
                                        $sysLang,
                                        $flexFormDiff,
                                        $previewLanguage
                                    );
                                    $this->_increaseInternalCounters($accum[$pageId]['items'][$table][$pageId]['fields'] ?? '');
                                }
                            } else {
                                $allRows = $t8Tools->getRecordsToTranslateFromTable(
                                    $table,
                                    $pageId,
                                    0,
                                    (bool)($l10ncfg['sortexports'] ?? false),
                                    $this->noHidden
                                );
                                if (empty($allRows)) {
                                    continue;
                                }
                                // Now, for each record, look for localization:
                                foreach ($allRows as $row) {
                                    $rowUid = (int)($row['uid'] ?? 0);
                                    if (isset($this->excludeIndex[$table . ':' . $rowUid]) || $rowUid === 0) {
                                        continue;
                                    }
                                    if (!empty($row[Constants::L10NMGR_LANGUAGE_RESTRICTION_FIELDNAME])) {
                                        /** @var LanguageRestrictionCollection $languageIsRestricted */
                                        $languageIsRestricted = LanguageRestrictionCollection::load(
                                            $sysLang,
                                            true,
                                            $table,
                                            Constants::L10NMGR_LANGUAGE_RESTRICTION_FIELDNAME
                                        );
                                        if ($languageIsRestricted->hasItem($rowUid)) {
                                            $this->excludeIndex[$table . ':' . $rowUid] = 1;
                                            continue;
                                        }
                                    }
                                    BackendUtility::workspaceOL($table, $row);
                                    if (empty($row)) {
                                        continue;
                                    }

                                    $accum[$pageId]['items'][$table][$rowUid] = $t8Tools->translationDetails(
                                        $table,
                                        $row,
                                        $sysLang,
                                        $flexFormDiff,
                                        $previewLanguage
                                    );
                                    if (empty($accum[$pageId]['items'][$table][$rowUid])) {
                                        // if there is no record available anymore, skip to the next row
                                        // records might be disabled when onlyForcedSourceLanguage is set
                                        continue;
                                    }
                                    if ($table === 'sys_file_reference' && isset($row['uid_local'])) {
                                        $fileList[] = (int)$row['uid_local'];
                                    }
                                    $this->_increaseInternalCounters($accum[$pageId]['items'][$table][$rowUid]['fields'] ?? []);
                                }
                            }
                        }
                    }
                }
            }
        }

        if (!empty($fileList) && GeneralUtility::inList($l10ncfg['tablelist'] ?? '', 'sys_file_metadata')) {
            $metaDataUids = $this->getFileMetaDataUids($fileList);
            if (!empty($metaDataUids)) {
                if (!isset($l10ncfg['include'])) {
                    $l10ncfg['include'] = '';
                } elseif (!empty($l10ncfg['include'])) {
                    $l10ncfg['include'] .= ',';
                }
                $metaDataIncludes = implode(',', array_map(fn (int $uid): string => 'sys_file_metadata:' . $uid, $metaDataUids));
                $l10ncfg['include'] .= $metaDataIncludes;
            }
        }

        $this->addPagesMarkedAsIncluded($l10ncfg['include'] ?? '', $l10ncfg['exclude'] ?? '');
        foreach ($this->includeIndex as $recId => $rec) {
            list($table, $uid) = explode(':', $recId);
            $row = BackendUtility::getRecordWSOL($table, $uid);
            if (!empty($row)) {
                $rowUid = $row['uid'] ?? 0;
                $accum[-1]['items'][$table][$rowUid] = $t8Tools->translationDetails(
                    $table,
                    $row,
                    $sysLang,
                    $flexFormDiff,
                    $previewLanguage
                );
                $this->_increaseInternalCounters($accum[-1]['items'][$table][$rowUid]['fields'] ?? []);
            }
        }
        // debug($accum);
        $this->_accumulatedInformations = $accum;
    }

    /**
     * @param int[] $fileUids List of file uids
     * @return int[] List of metadata uids
     */
    protected function getFileMetaDataUids(array $fileUids): array
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_file_metadata');
        $metaData = $queryBuilder->select('uid')
            ->from('sys_file_metadata')
            ->where(
                $queryBuilder->expr()->eq(
                    'sys_language_uid',
                    0
                ),
                $queryBuilder->expr()->in(
                    'file',
                    array_unique($fileUids)
                )
            )
            ->orderBy('uid')
            ->execute()
            ->fetchAllAssociative();

        return array_column($metaData, 'uid');
    }

    /**
     * @param array $fieldsArray
     */
    protected function _increaseInternalCounters(array $fieldsArray): void
    {
        if (is_array($fieldsArray)) {
            $this->_fieldCount = $this->_fieldCount + count($fieldsArray);
            if (function_exists('str_word_count')) {
                foreach ($fieldsArray as $v) {
                    $this->_wordCount = $this->_wordCount + str_word_count($v['defaultValue'] ?? '');
                }
            }
        }
    }

    /**
     * @param string $indexList
     * @param string $excludeList
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function addPagesMarkedAsIncluded(string $indexList, string $excludeList): void
    {
        $this->includeIndex = [];
        $this->excludeIndex = array_flip(GeneralUtility::trimExplode(',', $excludeList, true));
        if ($indexList) {
            $this->includeIndex = array_flip(GeneralUtility::trimExplode(',', $indexList, true));
        }
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $explicitlyIncludedPages = $queryBuilder->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq(
                    'l10nmgr_configuration',
                    $queryBuilder->createNamedParameter(Constants::L10NMGR_CONFIGURATION_INCLUDE, PDO::PARAM_INT)
                )
            )
            ->orderBy('uid')
            ->execute()
            ->fetchAll();

        if (!empty($explicitlyIncludedPages)) {
            foreach ($explicitlyIncludedPages as $page) {
                if (!isset($this->excludeIndex['pages:' . $page['uid'] ?? 0]) && !in_array(
                    $page['doktype'] ?? '',
                    $this->disallowDoktypes
                )
                ) {
                    $this->includeIndex['pages:' . $page['uid'] ?? 0] = 1;
                }
            }
        }
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $includingParentPages = $queryBuilder->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq(
                    'l10nmgr_configuration_next_level',
                    $queryBuilder->createNamedParameter(Constants::L10NMGR_CONFIGURATION_INCLUDE, PDO::PARAM_INT)
                )
            )
            ->orderBy('uid')
            ->execute()
            ->fetchAll();

        if (!empty($includingParentPages)) {
            foreach ($includingParentPages as $parentPage) {
                $this->addSubPagesRecursively($parentPage['uid'] ?? 0);
            }
        }
    }

    /**
     * Walks through a tree branch and checks if pages are to be included
     * Will ignore pages with explicit l10nmgr_configuration settings but still walk through their subpages
     * @param int $uid
     * @param int $level
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function addSubPagesRecursively(int $uid, int $level = 0): void
    {
        $level++;
        if ($uid > 0 && $level < 100) {
            /** @var QueryBuilder $queryBuilder */
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
            $subPages = $queryBuilder->select('uid', 'pid', 'l10nmgr_configuration', 'l10nmgr_configuration_next_level')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->eq(
                        'pid',
                        $queryBuilder->createNamedParameter((int)$uid, PDO::PARAM_INT)
                    )
                )
                ->orderBy('uid')
                ->execute()
                ->fetchAll();

            if (!empty($subPages)) {
                foreach ($subPages as $page) {
                    if (isset($page['l10nmgr_configuration']) && $page['l10nmgr_configuration'] === Constants::L10NMGR_CONFIGURATION_DEFAULT) {
                        $this->includeIndex['pages:' . $page['uid'] ?? 0] = 1;
                    }
                    if (isset($page['l10nmgr_configuration_next_level']) &&
                        (
                            $page['l10nmgr_configuration_next_level'] === Constants::L10NMGR_CONFIGURATION_DEFAULT
                            || $page['l10nmgr_configuration_next_level'] === Constants::L10NMGR_CONFIGURATION_INCLUDE
                        )
                    ) {
                        $this->addSubPagesRecursively($page['uid'] ?? 0, $level);
                    }
                }
            }
        }
    }

    /**
     * @return EmConfiguration
     */
    public function getExtensionConfiguration(): EmConfiguration
    {
        return $this->emConfiguration;
    }

    /**
     * @return int
     */
    public function getFieldCount(): int
    {
        return $this->_fieldCount;
    }

    /**
     * @return int
     */
    public function getWordCount(): int
    {
        return $this->_wordCount;
    }
}
