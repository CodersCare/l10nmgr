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

use Doctrine\DBAL\Exception as DBALException;
use Localizationteam\L10nmgr\Traits\BackendUserTrait;
use TYPO3\CMS\Backend\Tree\View\PageTreeView;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * L10nConfiguration
 * Capsulate a l10ncfg record.
 * Has factory method to get a relevant AccumulatedInformationsObject
 *
 * @author Kasper Skaarhoj <kasperYYYY@typo3.com>
 * @author Daniel Pötzinger <ext@aoemedia.de>
 * @author Stefano Kowalke <info@arroba-it.de>
 */
class L10nConfiguration
{
    use BackendUserTrait;

    public array $l10ncfg = [];

    protected int $sourcePid = 0;

    /**
     * loads internal array with l10nmgrcfg record
     *
     * @param int $id Id of the cfg record
     **/
    public function load(int $id = 0): void
    {
        if ($this->l10ncfg === []) {
            $this->l10ncfg = BackendUtility::getRecord('tx_l10nmgr_cfg', $id) ?? [];
        }
    }

    /**
     * Checks if configuration is valid
     **/
    public function isLoaded(): bool
    {
        // array must have values also!
        return is_array($this->l10ncfg) && ($this->l10ncfg !== []);
    }

    public function getUid(): int
    {
        return (int)$this->getData('uid');
    }

    public function getPid(): int
    {
        return (int)$this->getData('pid');
    }

    public function getTargetLanguages(): string
    {
        return $this->getData('targetLanguages');
    }

    public function getForcedSourceLanguage(): int
    {
        return (int)$this->getData('forcedSourceLanguage');
    }

    public function getOnlyForcedSourceLanguage(): bool
    {
        return (bool)$this->getData('onlyForcedSourceLanguage');
    }

    public function overrideExistingTranslations(): bool
    {
        return (bool)$this->getData('overrideexistingtranslations');
    }

    public function getTableList(): string
    {
        return $this->getData('tablelist');
    }

    public function getTitle(): string
    {
        return $this->getData('title');
    }

    public function getCrUserId(): int
    {
        return (int)$this->getData('cruser_id');
    }

    public function getFileNamePrefix(): string
    {
        return $this->getData('filenameprefix');
    }

    public function getMetaData(): string
    {
        return $this->getData('metadata');
    }

    public function getDepth(): int
    {
        return (int)$this->getData('depth');
    }

    public function getExclude(): string
    {
        return $this->getData('exclude');
    }

    public function getInclude(): string
    {
        return $this->getData('include');
    }

    /**
     * get a field of the current cfgr record
     *
     * @param string $key Key of the field. E.g. title,uid...
     *
     * @return string Value of the field
     **/
    public function getData(string $key): string
    {
        return $key === 'pid' && !empty($this->l10ncfg['depth']) && (int)$this->l10ncfg['depth'] === -1 && $this->sourcePid
            ? (string)$this->sourcePid
            : (string)($this->l10ncfg[$key] ?? '');
    }

    /**
     * Factory method to create AccumulatedInformation Object (e.g. build tree etc...)
     * (Factories should have all dependencies passed as parameter)
     */
    public function getL10nAccumulatedInformationsObjectForLanguage(int $sysLang): L10nAccumulatedInformation
    {
        $l10ncfg = $this->l10ncfg;
        $depth = (int)($l10ncfg['depth'] ?? 0);
        $treeStartingRecords = [];
        // Showing the tree:
        // Initialize starting point of page tree:
        if ($depth === -1) {
            $sourcePid = $this->sourcePid ?: (int)GeneralUtility::_GET('srcPID');
            $treeStartingPoints = [$sourcePid];
        } else {
            if ($depth === -2 && !empty($l10ncfg['pages'])) {
                $treeStartingPoints = GeneralUtility::intExplode(',', $l10ncfg['pages']);
            } else {
                $treeStartingPoints = [(int)($l10ncfg['pid'] ?? 0)];
            }
        }
        /** @var PageTreeView $tree */
        $tree = GeneralUtility::makeInstance(PageTreeView::class);
        if (!empty($treeStartingPoints)) {
            foreach ($treeStartingPoints as $treeStartingPoint) {
                $treeStartingRecords[] = BackendUtility::getRecordWSOL('pages', $treeStartingPoint);
            }
            // @extensionScannerIgnoreLine
            $tree->init('AND ' . $this->getBackendUser()->getPagePermsClause(1));
            $tree->addField('l18n_cfg');
            $tree->addField('l10nmgr_configuration');
            $tree->addField('l10nmgr_configuration_next_level');
            $tree->addField('l10nmgr_language_restriction');
            /** @var IconFactory $iconFactory */
            $iconFactory = GeneralUtility::makeInstance(IconFactory::class);
            $page = array_shift($treeStartingRecords);
            if (!empty($page)) {
                $HTML = $iconFactory->getIconForRecord('pages', $page, Icon::SIZE_SMALL)->render();
                $tree->tree[] = [
                    'row' => $page,
                    'HTML' => $HTML,
                ];
                // Create the tree from starting point or page list:
                if ($depth > 0) {
                    $tree->getTree($page['uid'] ?? 0, $depth);
                } else {
                    foreach ($treeStartingRecords as $page) {
                        $HTML = $iconFactory->getIconForRecord('pages', $page, Icon::SIZE_SMALL)->render();
                        $tree->tree[] = [
                            'row' => $page,
                            'HTML' => $HTML,
                        ];
                    }
                }
            }
        }
        //now create and init accum Info object:
        /** @var L10nAccumulatedInformation $accumObj */
        $accumObj = GeneralUtility::makeInstance(L10nAccumulatedInformation::class, $tree, $l10ncfg, $sysLang);
        return $accumObj;
    }

    /**
     * @throws \Doctrine\DBAL\DBALException
     */
    /**
     * @param int $sysLang
     * @param array $flexFormDiffArray
     */
    public function updateFlexFormDiff(int $sysLang, array $flexFormDiffArray): void
    {
        $l10ncfg = $this->l10ncfg;
        if (empty($l10ncfg['uid'])) {
            return;
        }
        // Updating diff-data:
        // First, unserialize/initialize:
        $flexFormDiffForAllLanguages = unserialize($l10ncfg['flexformdiff'] ?? '');
        if (!is_array($flexFormDiffForAllLanguages)) {
            $flexFormDiffForAllLanguages = [];
        }
        // Set the data
        $flexFormDiffForAllLanguages[$sysLang] = array_merge(
            (array)($flexFormDiffForAllLanguages[$sysLang] ?? []),
            $flexFormDiffArray
        );
        // Serialize back and save it to record:
        $l10ncfg['flexformdiff'] = serialize($flexFormDiffForAllLanguages);

        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_l10nmgr_cfg');
        $queryBuilder->update('tx_l10nmgr_cfg')
            ->set('flexformdiff', $l10ncfg['flexformdiff'])
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter((int)$l10ncfg['uid'], Connection::PARAM_INT)
                )
            )
            ->executeStatement();
    }

    public function setSourcePid(int $id): void
    {
        $this->sourcePid = $id;
    }
}
