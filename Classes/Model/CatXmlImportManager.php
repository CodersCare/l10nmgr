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
use Localizationteam\L10nmgr\Event\XmlImportFileIsParsed;
use Localizationteam\L10nmgr\Model\Tools\XmlTools;
use Localizationteam\L10nmgr\Traits\BackendUserTrait;
use Localizationteam\L10nmgr\Traits\LanguageServiceTrait;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\EventDispatcher\EventDispatcher;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Function for managing the Import of CAT XML
 *
 * @author Daniel Poetzinger <ext@aoemedia.de>
 */
class CatXmlImportManager
{
    use BackendUserTrait;
    use LanguageServiceTrait;

    /**
     * @var array $headerData headerData of the XML
     */
    public array $headerData = [];

    /**
     * @var string $file filepath with XML
     */
    protected string $file = '';

    /**
     * @var string $xml CATXML
     */
    protected string $xmlString = '';

    /**
     * @var array $xmlNodes parsed XML
     */
    protected array $xmlNodes;

    /**
     * @var int $sysLang selected import language (for check purposes - sys_language_uid)
     */
    protected int $sysLang;

    /**
     * @var array $_errorMsg accumulated errormessages
     */
    protected array $_errorMsg = [];

    public function __construct(string $file, int $sysLang, string $xmlString)
    {
        $this->sysLang = $sysLang;
        if (!empty($file)) {
            $this->file = $file;
        }
        if (!empty($xmlString)) {
            $this->xmlString = $xmlString;
        }
    }

    public function parseAndCheckXMLFile(): bool
    {
        $fileContent = GeneralUtility::getUrl($this->file);
        $xmlTree = XmlTools::xml2tree(
            str_replace(
                '&nbsp;',
                '&#160;',
                $fileContent
            ),
            3
        ); // For some reason PHP chokes on incoming &nbsp; in XML!

        if (is_array($xmlTree)) {
            $this->xmlNodes = $xmlTree;
        } else {
            $this->xmlNodes = [$xmlTree];
        }
        $event = new XmlImportFileIsParsed($this->xmlNodes, $this->_errorMsg);
        /** @var EventDispatcher $eventDispatcher */
        $eventDispatcher = GeneralUtility::makeInstance(EventDispatcher::class);
        $event = $eventDispatcher->dispatch($event);
        $this->xmlNodes = $event->getXmlNodes();
        $this->_errorMsg = $event->getErrorMessages();

        if (!is_array($xmlTree)) {
            $this->_errorMsg[] = $this->getLanguageService()->getLL('import.manager.error.parsing.xml2tree.message') . $this->xmlNodes[0] . ' Content: ' . $fileContent;
            return false;
        }
        $headerInformationNodes = $this->xmlNodes['TYPO3L10N'][0]['ch']['head'][0]['ch'] ?? [];
        if (empty($headerInformationNodes)) {
            $this->_errorMsg[] = $this->getLanguageService()->getLL('import.manager.error.missing.head.message');
            return false;
        }
        $this->_setHeaderData($headerInformationNodes);
        if ($this->_isIncorrectXMLFile()) {
            return false;
        }
        return true;
    }

    protected function _setHeaderData(array $headerInformationNodes): void
    {
        if (empty($headerInformationNodes)) {
            return;
        }
        foreach ($headerInformationNodes as $k => $v) {
            $this->headerData[$k] = '';
            if (is_array($v) && is_array($v[0]) && is_array($v[0]['values'] ?? null)) {
                $this->headerData[$k] = $v[0]['values'][0] ?? '';
            }
        }
    }

    protected function _isIncorrectXMLFile(): bool
    {
        $error = [];
        if (!isset($this->headerData['t3_formatVersion']) || $this->headerData['t3_formatVersion'] != L10NMGR_FILEVERSION) {
            $error[] = sprintf(
                $this->getLanguageService()->getLL('import.manager.error.version.message'),
                $this->headerData['t3_formatVersion'] ?? '',
                L10NMGR_FILEVERSION
            );
        }
        if (!isset($this->headerData['t3_workspaceId']) || $this->headerData['t3_workspaceId'] != $this->getBackendUser()->workspace) {
            $this->getBackendUser()->workspace = $this->headerData['t3_workspaceId'] ?? 0;
            $error[] = sprintf(
                $this->getLanguageService()->getLL('import.manager.error.workspace.message'),
                $this->getBackendUser()->workspace,
                $this->headerData['t3_workspaceId'] ?? 0
            );
        }
        if (!isset($this->headerData['t3_sysLang']) || $this->headerData['t3_sysLang'] != $this->sysLang) {
            $error[] = sprintf(
                $this->getLanguageService()->getLL('import.manager.error.language.message'),
                $this->sysLang,
                $this->headerData['t3_sysLang'] ?? 0
            );
        }
        if (count($error) > 0) {
            $this->_errorMsg = array_merge($this->_errorMsg, $error);
            return true;
        }
        return false;
    }

    public function parseAndCheckXMLString(): bool
    {
        $catXmlString = $this->xmlString;
        $this->xmlNodes = XmlTools::xml2tree(
            str_replace('&nbsp;', '&#160;', $catXmlString),
            3
        ); // For some reason PHP chokes on incoming &nbsp; in XML!
        if (!is_array($this->xmlNodes)) {
            $this->_errorMsg[] = $this->getLanguageService()->getLL('import.manager.error.parsing.xml2tree.message') . $this->xmlNodes;
            return false;
        }
        $headerInformationNodes = $this->xmlNodes['TYPO3L10N'][0]['ch']['head'][0]['ch'] ?? [];
        if (empty($headerInformationNodes)) {
            $this->_errorMsg[] = $this->getLanguageService()->getLL('import.manager.error.missing.head.message');
            return false;
        }
        $this->_setHeaderData($headerInformationNodes);
        if ($this->_isIncorrectXMLString()) {
            return false;
        }
        return true;
    }

    protected function _isIncorrectXMLString(): bool
    {
        $error = [];
        if (!isset($this->headerData['t3_formatVersion']) || $this->headerData['t3_formatVersion'] != L10NMGR_FILEVERSION) {
            $error[] = sprintf(
                $this->getLanguageService()->getLL('import.manager.error.version.message'),
                $this->headerData['t3_formatVersion'] ?? '',
                L10NMGR_FILEVERSION
            );
        }
        if (!isset($this->headerData['t3_workspaceId']) || $this->headerData['t3_workspaceId'] != $this->getBackendUser()->workspace) {
            $error[] = sprintf(
                $this->getLanguageService()->getLL('import.manager.error.workspace.message'),
                $this->getBackendUser()->workspace,
                $this->headerData['t3_workspaceId'] ?? 0
            );
        }
        if (!isset($this->headerData['t3_sysLang'])) {
            $error[] = sprintf(
                $this->getLanguageService()->getLL('import.manager.error.language.message'),
                $this->sysLang,
                $this->headerData['t3_sysLang'] ?? 0
            );
        }
        if (count($error) > 0) {
            $this->_errorMsg = array_merge($this->_errorMsg, $error);
            return true;
        }
        return false;
    }

    public function getErrorMessages(): string
    {
        return implode('<br />', $this->_errorMsg);
    }

    public function &getXMLNodes(): array
    {
        return $this->xmlNodes;
    }

    /**
     * Get pageGrp IDs for preview link generation
     *
     * @param array $xmlNodes XML nodes from CATXML
     *
     * @return array Page IDs for preview
     */
    public function getPidsFromCATXMLNodes(array $xmlNodes): array
    {
        $pids = [];
        if (!empty($xmlNodes['TYPO3L10N'][0]['ch']['pageGrp'])) {
            foreach ($xmlNodes['TYPO3L10N'][0]['ch']['pageGrp'] as $pageGrp) {
                $pids[] = $pageGrp['attrs']['id'] ?? 0;
            }
        }
        return $pids;
    }

    /**
     * Get uids for which localizations shall be removed on 2nd import if option checked
     *
     * @param array $xmlNodes XML nodes from CATXML
     *
     * @return array Uids for which localizations shall be removed
     */
    public function getDelL10NDataFromCATXMLNodes(array $xmlNodes): array
    {
        //get L10Ns to be deleted before import
        $delL10NUids = [];
        if (!empty($xmlNodes['TYPO3L10N'][0]['ch']['pageGrp'])) {
            foreach ($xmlNodes['TYPO3L10N'][0]['ch']['pageGrp'] as $pageGrp) {
                if (!empty($pageGrp['ch']['data'])) {
                    foreach ($pageGrp['ch']['data'] as $row) {
                        if (preg_match('/NEW/', $row['attrs']['key'] ?? '')) {
                            $delL10NUids[] = ($row['attrs']['table'] ?? '') . ':' . ($row['attrs']['elementUid'] ?? '');
                        }
                    }
                }
            }
        }
        return array_unique($delL10NUids);
    }

    /**
     * Delete previous localisations
     *
     * @param array $delL10NData table:id combinations to be deleted
     *
     * @return int Number of deleted elements
     * @throws DBALException
     */
    public function delL10N(array $delL10NData): int
    {
        //delete previous L10Ns
        $cmdCount = 0;
        /** @var DataHandler $dataHandler */
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], []);
        foreach ($delL10NData as $element) {
            [$table, $elementUid] = explode(':', $element);
            /** @var QueryBuilder $queryBuilder */
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);

            /** @var DeletedRestriction $deletedRestriction */
            $deletedRestriction = GeneralUtility::makeInstance(DeletedRestriction::class);

            $queryBuilder
                ->getRestrictions()
                ->removeAll()
                ->add($deletedRestriction);
            $queryBuilder->select('uid')
                ->from($table)
                ->where(
                    $queryBuilder->expr()->eq(
                        $GLOBALS['TCA'][$table]['ctrl']['languageField'] ?? 'sys_language_uid',
                        $queryBuilder->createNamedParameter($this->headerData['t3_sysLang'] ?? 0, Connection::PARAM_INT)
                    )
                );

            if (!empty($GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'])) {
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->eq(
                        $GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'],
                        $queryBuilder->createNamedParameter($elementUid, Connection::PARAM_INT)
                    )
                );
            }

            if (!empty($GLOBALS['TCA'][$table]['ctrl']['versioningWS'])) {
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->eq(
                        't3ver_wsid',
                        $queryBuilder->createNamedParameter($this->headerData['t3_workspaceId'], Connection::PARAM_INT)
                    )
                );
            }

            $delDataQuery = $queryBuilder->executeQuery()->fetchAllAssociative();

            if (!empty($delDataQuery)) {
                foreach ($delDataQuery as $row) {
                    $dataHandler->deleteAction($table, $row['uid'] ?? 0);
                }
            }
            $cmdCount++;
        }
        return $cmdCount;
    }
}
