<?php

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

use Localizationteam\L10nmgr\Traits\BackendUserTrait;
use PDO;
use TYPO3\CMS\Backend\Tree\View\PageTreeView;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * l10nConfiguration
 * Capsulate a 10ncfg record.
 * Has factory method to get a relevant AccumulatedInformationsObject
 *
 * @author Kasper Skaarhoj <kasperYYYY@typo3.com>
 * @author Daniel Pötzinger <ext@aoemedia.de>
 */
class L10nConfiguration
{
    use BackendUserTrait;

    /**
     * @var array
     */
    public $l10ncfg;

    /**
     * @var int
     */
    protected $sourcePid;

    /**
     * loads internal array with l10nmgrcfg record
     *
     * @param int $id Id of the cfg record
     *
     **/
    public function load($id)
    {
        $this->l10ncfg = BackendUtility::getRecord('tx_l10nmgr_cfg', $id);
    }

    /**
     * checks if configuration is valid
     *
     * @return bool
     **/
    public function isLoaded()
    {
        // array must have values also!
        if (is_array($this->l10ncfg) && (!empty($this->l10ncfg))) {
            return true;
        }
        return false;
    }

    /**
     * get uid field
     *
     * @return int
     **/
    public function getId()
    {
        return $this->getData('uid');
    }

    /**
     * get a field of the current cfgr record
     *
     * @param string $key Key of the field. E.g. title,uid...
     *
     * @return string Value of the field
     **/
    public function getData($key)
    {
        return $key === 'pid' && (int)$this->l10ncfg['depth'] === -1 && (int)$this->sourcePid
            ? (int)$this->sourcePid
            : $this->l10ncfg[$key];
    }

    /**
     * Factory method to create AccumulatedInformation Object (e.g. build tree etc...)
     * (Factorys should have all dependencies passed as parameter)
     *
     * @param int $sysLang sys_language_uid
     *
     * @return L10nAccumulatedInformation
     */
    public function getL10nAccumulatedInformationsObjectForLanguage($sysLang)
    {
        $l10ncfg = $this->l10ncfg;
        $depth = (int)$l10ncfg['depth'];
        $treeStartingRecords = [];
        // Showing the tree:
        // Initialize starting point of page tree:
        if ($depth === -1) {
            $sourcePid = (int)$this->sourcePid ? (int)$this->sourcePid : (int)GeneralUtility::_GET('srcPID');
            $treeStartingPoints = [$sourcePid];
        } else {
            if ($depth === -2 && !empty($l10ncfg['pages'])) {
                $treeStartingPoints = GeneralUtility::intExplode(',', $l10ncfg['pages']);
            } else {
                $treeStartingPoints = [(int)$l10ncfg['pid']];
            }
        }
        /** @var PageTreeView $tree */
        $tree = GeneralUtility::makeInstance(PageTreeView::class);
        if (!empty($treeStartingPoints)) {
            foreach ($treeStartingPoints as $treeStartingPoint) {
                $treeStartingRecords[] = BackendUtility::getRecordWSOL('pages', $treeStartingPoint);
            }
            $tree->init('AND ' . $this->getBackendUser()->getPagePermsClause(1));
            $tree->addField('l18n_cfg');
            $tree->addField('l10nmgr_configuration');
            $tree->addField('l10nmgr_configuration_next_level');
            $tree->addField('l10nmgr_language_restriction');
            /** @var IconFactory $iconFactory */
            $iconFactory = GeneralUtility::makeInstance(IconFactory::class);
            $page = array_shift($treeStartingRecords);
            $HTML = $iconFactory->getIconForRecord('pages', $page, Icon::SIZE_SMALL)->render();
            $tree->tree[] = [
                'row' => $page,
                'HTML' => $HTML,
            ];
            // Create the tree from starting point or page list:
            if ($depth > 0) {
                $tree->getTree($page['uid'], $depth, '');
            } else {
                if (!empty($treeStartingRecords)) {
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
     * @param int $sysLang
     * @param array $flexFormDiffArray
     */
    public function updateFlexFormDiff($sysLang, $flexFormDiffArray)
    {
        $l10ncfg = $this->l10ncfg;
        // Updating diff-data:
        // First, unserialize/initialize:
        $flexFormDiffForAllLanguages = unserialize($l10ncfg['flexformdiff']);
        if (!is_array($flexFormDiffForAllLanguages)) {
            $flexFormDiffForAllLanguages = [];
        }
        // Set the data (
        $flexFormDiffForAllLanguages[$sysLang] = array_merge(
            (array)$flexFormDiffForAllLanguages[$sysLang],
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
                    $queryBuilder->createNamedParameter((int)$l10ncfg['uid'], PDO::PARAM_INT)
                )
            )
            ->execute();
    }

    /**
     * @param int $id
     */
    public function setSourcePid($id)
    {
        $this->sourcePid = (int)$id;
    }
}
