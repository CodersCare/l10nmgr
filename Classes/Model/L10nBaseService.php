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

use Localizationteam\L10nmgr\Model\Dto\EmConfiguration;
use PDO;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Database\RelationHandler;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * baseService class for offering common services like saving translation etc...
 *
 * @author Kasper Skaarhoj <kasperYYYY@typo3.com>
 * @author Daniel Pötzinger <development@aoemedia.de>
 */
class L10nBaseService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $targetLanguageID;

    public $lastTCEMAINCommandsCount;

    /**
     * @var bool Translate even if empty.
     */
    protected $createTranslationAlsoIfEmpty = false;

    /**
     * @var bool Import as default language.
     */
    protected $importAsDefaultLanguage = false;

    /**
     * @var array
     */
    protected $TCEmain_cmd = [];

    /**
     * @var array
     */
    protected $TCEmain_data = [];

    /**
     * @var array
     */
    protected $checkedParentRecords = [];

    /**
     * @var array
     */
    protected $childMappingArray = [];

    /**
     * @var int
     */
    protected $depthCounter = 0;

    /**
     * @var array
     */
    protected $flexFormDiffArray;

    /**
     * @var EmConfiguration
     */
    protected $emConfiguration;

    /**
     * Check for deprecated configuration throws false positive in extension scanner.
     */
    public function __construct()
    {
        $this->emConfiguration = GeneralUtility::makeInstance(EmConfiguration::class);
    }

    /**
     * @return int|null
     */
    public static function getTargetLanguageID()
    {
        return self::$targetLanguageID;
    }

    /**
     * Save the translation
     *
     * @param L10nConfiguration $l10ncfgObj
     * @param TranslationData $translationObj
     * @param bool $preTranslate
     */
    public function saveTranslation(
        L10nConfiguration $l10ncfgObj,
        TranslationData $translationObj,
        $preTranslate = true
    ) {
        // Provide a hook for specific manipulations before saving
        if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['savePreProcess'])) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['savePreProcess'] as $classReference) {
                $processingObject = GeneralUtility::makeInstance($classReference);
                $processingObject->processBeforeSaving($l10ncfgObj, $translationObj, $this);
            }
        }
        if (!$l10ncfgObj->getData('pretranslatecontent')) {
            $preTranslate = false;
        }
        if ($preTranslate) {
            // make sure to translate all pages and content elements that are available on these pages
            $this->preTranslateAllContent($translationObj);
        }
        $this->remapInputDataForExistingTranslations($l10ncfgObj, $translationObj);
        $sysLang = $translationObj->getLanguage();
        $previewLanguage = $translationObj->getPreviewLanguage();
        $accumObj = $l10ncfgObj->getL10nAccumulatedInformationsObjectForLanguage($sysLang);
        $accumObj->setForcedPreviewLanguage($previewLanguage);
        $flexFormDiffArray = $this->_submitContentAndGetFlexFormDiff(
            $accumObj->getInfoArray(),
            $translationObj->getTranslationData()
        );
        if ($flexFormDiffArray !== false) {
            $l10ncfgObj->updateFlexFormDiff($sysLang, $flexFormDiffArray);
        }
        // Provide a hook for specific manipulations after saving
        if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['savePostProcess'])) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['savePostProcess'] as $classReference) {
                $processingObject = GeneralUtility::makeInstance($classReference);
                $processingObject->processAfterSaving($l10ncfgObj, $translationObj, $flexFormDiffArray, $this);
            }
        }
    }

    /**
     * Function that iterates over all page records that are given within the import data
     * and translate all pages and content elements
     * beforehand so ordering and container elements work just as expected.
     *
     * Goes hand in hand with the remapInputDataForExistingTranslations() functionality, which then replaces the elements
     * which would be expected to be new)
     *
     * @param TranslationData $translationData
     */
    protected function preTranslateAllContent(TranslationData $translationData)
    {
        $inputArray = $translationData->getTranslationData();
        $pageUids = array_keys((array)$inputArray['pages']);
        foreach ($pageUids as $pageUid) {
            $this->translateContentOnPage($pageUid, (int)$translationData->getLanguage());
        }
    }

    /**
     * Translates all non-translated content elements on a certain page (and the page itself)
     *
     * @param int $pageUid
     * @param int $targetLanguageUid
     */
    protected function translateContentOnPage($pageUid, $targetLanguageUid)
    {
        // Check if the page itself was translated already, if not, translate it
        $translatedPageRecords = BackendUtility::getRecordLocalization('pages', $pageUid, $targetLanguageUid);
        if ($translatedPageRecords === false) {
            // translate the page first
            $commands = [
                'pages' => [
                    $pageUid => [
                        'localize' => $targetLanguageUid,
                    ],
                ],
            ];
            $dataHandler = $this->getDataHandlerInstance();
            $dataHandler->start([], $commands);
            $dataHandler->process_cmdmap();
        }
        $commands = [];
        $gridElementsInstalled = ExtensionManagementUtility::isLoaded('gridelements');
        if ($gridElementsInstalled) {
            // find all tt_content elements in the default language of this page that are NOT inside a grid element
            $recordsInOriginalLanguage = $this->getRecordsByField(
                'tt_content',
                'pid',
                $pageUid,
                'AND sys_language_uid=0 AND tx_gridelements_container=0',
                'colPos, sorting'
            );
            foreach ($recordsInOriginalLanguage as $recordInOriginalLanguage) {
                $translatedContentElements = BackendUtility::getRecordLocalization(
                    'tt_content',
                    $recordInOriginalLanguage['uid'],
                    $targetLanguageUid
                );
                if (empty($translatedContentElements)) {
                    $commands['tt_content'][$recordInOriginalLanguage['uid']]['localize'] = $targetLanguageUid;
                }
            }
            // find all tt_content elements in the default language of this page that ARE inside a grid element
            $recordsInOriginalLanguage = $this->getRecordsByField(
                'tt_content',
                'pid',
                $pageUid,
                'AND sys_language_uid=0 AND tx_gridelements_container!=0',
                'colPos, sorting'
            );
            foreach ($recordsInOriginalLanguage as $recordInOriginalLanguage) {
                $translatedContentElements = BackendUtility::getRecordLocalization(
                    'tt_content',
                    $recordInOriginalLanguage['uid'],
                    $targetLanguageUid
                );
                if (empty($translatedContentElements)) {
                    $commands['tt_content'][$recordInOriginalLanguage['uid']]['localize'] = $targetLanguageUid;
                }
            }
        } else {
            // find all tt_content elements in the default language of this page
            $recordsInOriginalLanguage = $this->getRecordsByField(
                'tt_content',
                'pid',
                $pageUid,
                'AND sys_language_uid=0',
                'colPos, sorting'
            );
            foreach ($recordsInOriginalLanguage as $recordInOriginalLanguage) {
                $translatedContentElements = BackendUtility::getRecordLocalization(
                    'tt_content',
                    $recordInOriginalLanguage['uid'],
                    $targetLanguageUid
                );
                if (empty($translatedContentElements)) {
                    $commands['tt_content'][$recordInOriginalLanguage['uid']]['localize'] = $targetLanguageUid;
                }
            }
        }
        if (count($commands)) {
            // don't do the "prependAtCopy"
            $GLOBALS['TCA']['tt_content']['ctrl']['prependAtCopy'] = false;
            $dataHandler = $this->getDataHandlerInstance();
            $dataHandler->start([], $commands);
            $dataHandler->process_cmdmap();
        }
    }

    /**
     * @return DataHandler
     */
    protected function getDataHandlerInstance()
    {
        /** @var DataHandler $dataHandler */
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->neverHideAtCopy = $this->emConfiguration->isEnableNeverHideAtCopy();
        $dataHandler->dontProcessTransformations = true;
        $dataHandler->isImporting = true;
        return $dataHandler;
    }

    /**
     * @param string $theTable
     * @param string $theField
     * @param string $theValue
     * @param string $whereClause
     * @param string $orderBy
     * @return array
     */
    protected function getRecordsByField(
        string $theTable,
        string $theField,
        string $theValue,
        string $whereClause = '',
        string $orderBy = ''
    ): array {
        if (is_array($GLOBALS['TCA'][$theTable])) {
            /** @var QueryBuilder $queryBuilder */
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($theTable);

            $queryBuilder->getRestrictions()
                ->removeAll()
                ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class))
                ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

            $queryBuilder
                ->select('*')
                ->from($theTable)
                ->where($queryBuilder->expr()->eq($theField, $queryBuilder->createNamedParameter($theValue)));

            // additional where
            if ($whereClause) {
                $queryBuilder->andWhere(preg_replace('/^(?:(AND|OR)[[:space:]]*)+/i', '', trim($whereClause)) ?: '');
            }

            // order by
            if ($orderBy !== '') {
                $orderExpressions = GeneralUtility::trimExplode(',', $orderBy, true);

                $orderByNames = array_map(
                    function ($expression) {
                        $fieldNameOrderArray = GeneralUtility::trimExplode(' ', $expression, true);
                        $fieldName = $fieldNameOrderArray[0] ?? null;
                        $order = $fieldNameOrderArray[1] ?? null;

                        return [$fieldName, $order];
                    },
                    $orderExpressions
                );

                foreach ($orderByNames as $orderPair) {
                    list($fieldName, $order) = $orderPair;
                    $queryBuilder->addOrderBy($fieldName, $order);
                }
            }

            return $queryBuilder->execute()->fetchAll();
        }
        return [];
    }

    /**
     * If you want to reimport the same file over and over again, by default this can only be done once because the input array
     * contains "NEW" all over the place in th XML file.
     * This feature (enabled per configuration record) maps the data of the existing record in the target language
     * to re-import the data again and again.
     *
     * This also allows to import data of records that have been added in TYPO3 in the meantime.
     *
     * @param L10nConfiguration $configurationObject
     * @param TranslationData $translationData
     */
    protected function remapInputDataForExistingTranslations(
        L10nConfiguration $configurationObject,
        TranslationData $translationData
    ) {
        // feature is not enabled
        if (!$configurationObject->getData('overrideexistingtranslations')) {
            return;
        }
        $inputArray = $translationData->getTranslationData();
        // clean up input array and replace the "NEW" fields with actual values if they have been translated already
        $cleanedInputArray = [];
        foreach ($inputArray as $table => $elementsInTable) {
            foreach ($elementsInTable as $elementUid => $fields) {
                foreach ($fields as $fieldKey => $translatedValue) {
                    // check if the record was marked as "new" but was translated already
                    list($Ttable, $TuidString, $Tfield, $Tpath) = explode(':', $fieldKey);
                    list($Tuid, $Tlang, $TdefRecord) = explode('/', $TuidString);
                    if ($Tuid === 'NEW') {
                        $translatedRecord = BackendUtility::getRecordLocalization($Ttable, $TdefRecord, $Tlang);
                        if (!empty($translatedRecord)) {
                            $translatedRecord = reset($translatedRecord);
                            if ($translatedRecord['uid'] > 0) {
                                $fieldKey = $Ttable . ':' . $translatedRecord['uid'] . ':' . $Tfield;
                                if ($Tpath) {
                                    $fieldKey .= ':' . $Tpath;
                                }
                            }
                        }
                    }
                    $cleanedInputArray[$table][$elementUid][$fieldKey] = $translatedValue;
                }
            }
        }
        $translationData->setTranslationData($cleanedInputArray);
    }

    /**
     * Submit incoming content to database. Must match what is available in $accum.
     *
     * @param array $accum Translation configuration
     * @param array $inputArray Array with incoming translation. Must match what is found in $accum
     *
     * @return mixed False if error - else flexFormDiffArray (if $inputArray was an array and processing was performed.)
     */
    protected function _submitContentAndGetFlexFormDiff($accum, $inputArray)
    {
        if ($this->getImportAsDefaultLanguage()) {
            return $this->_submitContentAsDefaultLanguageAndGetFlexFormDiff($accum, $inputArray);
        }
        return $this->_submitContentAsTranslatedLanguageAndGetFlexFormDiff($accum, $inputArray);
    }

    /**
     * Getter for $importAsDefaultLanguage
     *
     * @return bool
     */
    public function getImportAsDefaultLanguage()
    {
        return $this->importAsDefaultLanguage;
    }

    /**
     * Setter for $importAsDefaultLanguage
     *
     * @param bool $importAsDefaultLanguage
     */
    public function setImportAsDefaultLanguage($importAsDefaultLanguage)
    {
        $this->importAsDefaultLanguage = $importAsDefaultLanguage;
    }

    /**
     * Submit incoming content as default language to database. Must match what is available in $accum.
     *
     * @param array $accum Translation configuration
     * @param array $inputArray Array with incoming translation. Must match what is found in $accum
     *
     * @return mixed False if error - else flexFormDiffArray (if $inputArray was an array and processing was performed.)
     */
    protected function _submitContentAsDefaultLanguageAndGetFlexFormDiff($accum, $inputArray)
    {
        if (is_array($inputArray)) {
            // Initialize:
            /** @var FlexFormTools $flexToolObj */
            $flexToolObj = GeneralUtility::makeInstance(FlexFormTools::class);
            $TCEmain_data = [];
            $_flexFormDiffArray = [];
            // Traverse:
            foreach ($accum as $pId => $page) {
                foreach ($accum[$pId]['items'] as $table => $elements) {
                    foreach ($elements as $elementUid => $data) {
                        $hooks = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['beforeDataFieldsDefault'];
                        if (is_array($hooks)) {
                            foreach ($hooks as $hookObj) {
                                $parameters = [
                                    'data' => $data,
                                ];
                                $data = GeneralUtility::callUserFunction($hookObj, $parameters, $this);
                            }
                        }
                        if (is_array($data['fields'])) {
                            foreach ($data['fields'] as $key => $tData) {
                                if (is_array($tData)
                                    && is_array($inputArray[$table][$elementUid])
                                    && array_key_exists($key, $inputArray[$table][$elementUid])
                                ) {
                                    list($Ttable, $TuidString, $Tfield, $Tpath) = explode(':', $key);
                                    list($Tuid, $Tlang, $TdefRecord) = explode('/', $TuidString);
                                    if (!$this->createTranslationAlsoIfEmpty
                                        && $inputArray[$table][$elementUid][$key] == ''
                                        && $Tuid == 'NEW'
                                        && $Tfield !== trim($GLOBALS['TCA'][$Ttable]['ctrl']['label'])
                                    ) {
                                        //if data is empty and the field is not the label field of that particular table, do not save it
                                        unset($inputArray[$table][$elementUid][$key]);
                                        continue;
                                    }
                                    // If FlexForm, we set value in special way:
                                    if ($Tpath) {
                                        if (!is_array($TCEmain_data[$Ttable][$elementUid][$Tfield])) {
                                            $TCEmain_data[$Ttable][$elementUid][$Tfield] = [];
                                        }
                                        //TCEMAINDATA is passed as reference here:
                                        $flexToolObj->setArrayValueByPath(
                                            $Tpath,
                                            $TCEmain_data[$Ttable][$elementUid][$Tfield],
                                            $inputArray[$table][$elementUid][$key]
                                        );
                                        $_flexFormDiffArray[$key] = [
                                            'translated' => $inputArray[$table][$elementUid][$key],
                                            'default' => $tData['defaultValue'],
                                        ];
                                    } else {
                                        $TCEmain_data[$Ttable][$elementUid][$Tfield] = $inputArray[$table][$elementUid][$key];
                                    }
                                    unset($inputArray[$table][$elementUid][$key]); // Unsetting so in the end we can see if $inputArray was fully processed.
                                }
                                //debug($tData,'fields not set for: '.$elementUid.'-'.$key);
                                //debug($inputArray[$table],'inputarray');
                            }
                            if (is_array($inputArray[$table][$elementUid]) && !count($inputArray[$table][$elementUid])) {
                                unset($inputArray[$table][$elementUid]); // Unsetting so in the end we can see if $inputArray was fully processed.
                            }
                        }
                        $hooks = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['afterDataFieldsDefault'];
                        if (is_array($hooks)) {
                            foreach ($hooks as $hookObj) {
                                $parameters = [
                                    'TCEmain_data' => $TCEmain_data,
                                ];
                                $TCEmain_data = GeneralUtility::callUserFunction($hookObj, $parameters, $this);
                            }
                        }
                    }
                    if (is_array($inputArray[$table]) && !count($inputArray[$table])) {
                        unset($inputArray[$table]); // Unsetting so in the end we can see if $inputArray was fully processed.
                    }
                }
            }
            $this->lastTCEMAINCommandsCount = 0;
            // Now, submitting translation data:
            /** @var DataHandler $tce */
            $tce = GeneralUtility::makeInstance(DataHandler::class);
            $tce->dontProcessTransformations = $this->emConfiguration->isImportDontProcessTransformations();
            $tce->isImporting = true;
            foreach (array_chunk($TCEmain_data, 100, true) as $dataPart) {
                $tce->start(
                    $dataPart,
                    []
                ); // check has been done previously that there is a backend user which is Admin and also in live workspace
                $tce->process_datamap();
            }
            if (count($tce->errorLog)) {
                $this->logger->debug(__FILE__ . ': ' . __LINE__ . ': TCEmain update errors: ' . implode(
                    ', ',
                    $tce->errorLog
                ));
            }
            if (count($tce->autoVersionIdMap) && count($_flexFormDiffArray)) {
                foreach ($_flexFormDiffArray as $key => $value) {
                    list($Ttable, $Tuid, $Trest) = explode(':', $key, 3);
                    if ($tce->autoVersionIdMap[$Ttable][$Tuid]) {
                        $_flexFormDiffArray[$Ttable . ':' . $tce->autoVersionIdMap[$Ttable][$Tuid] . ':' . $Trest] = $_flexFormDiffArray[$key];
                        unset($_flexFormDiffArray[$key]);
                    }
                }
            }
            // Should be empty now - or there were more information in the incoming array than there should be!
            if (count($inputArray)) {
                debug($inputArray, 'These fields were ignored since they were not in the configuration 1:');
            }
            return $_flexFormDiffArray;
        }
        return false;
    }

    /**
     * Submit incoming content as translated language to database. Must match what is available in $accum.
     *
     * @param array $accum Translation configuration
     * @param array $inputArray Array with incoming translation. Must match what is found in $accum
     *
     * @return mixed False if error - else flexFormDiffArray (if $inputArray was an array and processing was performed.)
     */
    protected function _submitContentAsTranslatedLanguageAndGetFlexFormDiff($accum, $inputArray)
    {
        if (is_array($inputArray)) {
            // Initialize:
            /** @var FlexFormTools $flexToolObj */
            $flexToolObj = GeneralUtility::makeInstance(FlexFormTools::class);
            $gridElementsInstalled = ExtensionManagementUtility::isLoaded('gridelements');
            $fluxInstalled = ExtensionManagementUtility::isLoaded('flux');
            $TCEmain_data = [];
            $this->TCEmain_cmd = [];
            $Tlang = '';
            $_flexFormDiffArray = [];
            // Traverse:
            foreach ($accum as $pId => $page) {
                foreach ($accum[$pId]['items'] as $table => $elements) {
                    foreach ($elements as $elementUid => $data) {
                        $element = $this->getRawRecord((string)$table, (int)$elementUid);
                        $hooks = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['beforeDataFieldsTranslated'];
                        if (is_array($hooks)) {
                            foreach ($hooks as $hookObj) {
                                $parameters = [
                                    'data' => $data,
                                ];
                                $data = GeneralUtility::callUserFunction($hookObj, $parameters, $this);
                            }
                        }
                        if (is_array($data['fields'])) {
                            foreach ($data['fields'] as $key => $tData) {
                                if (is_array($tData)
                                    && is_array($inputArray[$table][$elementUid])
                                    && array_key_exists($key, $inputArray[$table][$elementUid])
                                ) {
                                    list($Ttable, $TuidString, $Tfield, $Tpath) = explode(':', $key);
                                    list($Tuid, $Tlang, $TdefRecord) = explode('/', $TuidString);
                                    if (!$this->createTranslationAlsoIfEmpty
                                        && $inputArray[$table][$elementUid][$key] == ''
                                        && $Tuid == 'NEW'
                                        && $Tfield !== trim($GLOBALS['TCA'][$Ttable]['ctrl']['label'])
                                    ) {
                                        //if data is empty and the field is not the label field of that particular table, do not save it
                                        unset($inputArray[$table][$elementUid][$key]);
                                        continue;
                                    }
                                    // If new element is required, we prepare for localization
                                    if ($Tuid === 'NEW') {
                                        if ($table === 'tt_content' && ($gridElementsInstalled === true || $fluxInstalled === true)) {
                                            if (isset($this->TCEmain_cmd['tt_content'][$elementUid])) {
                                                unset($this->TCEmain_cmd['tt_content'][$elementUid]);
                                            }
                                            if ((int)$element['colPos'] !== -2 && (int)$element['colPos'] !== -1 && (int)$element['colPos'] !== 18181) {
                                                $this->TCEmain_cmd['tt_content'][$elementUid]['localize'] = $Tlang;
                                            } else {
                                                if ($element['tx_gridelements_container'] > 0) {
                                                    $this->depthCounter = 0;
                                                    $this->recursivelyCheckForRelationParents(
                                                        $element,
                                                        $Tlang,
                                                        'tx_gridelements_container',
                                                        'tx_gridelements_children'
                                                    );
                                                }
                                                if ($element['tx_flux_parent'] > 0) {
                                                    $this->depthCounter = 0;
                                                    $this->recursivelyCheckForRelationParents(
                                                        $element,
                                                        $Tlang,
                                                        'tx_flux_parent',
                                                        'tx_flux_children'
                                                    );
                                                }
                                            }
                                        } elseif (is_array($inlineTablesConfig = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['inlineTablesConfig']) && array_key_exists(
                                            $table,
                                            $inlineTablesConfig
                                        )) {
                                            /*
                                             * Special handling for 1:n relations
                                             *
                                             * Example: Inline elements (1:n) with tt_content as parent
                                             *
                                             * Config example:
                                             * $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['inlineTablesConfig'] = [
                                             *    'tx_myext_myelement' => [
                                             *       'parentField' => 'content',
                                             *       'childrenField' => 'myelements',
                                             *   ]];
                                             */
                                            if (isset($this->TCEmain_cmd[$table][$elementUid])) {
                                                unset($this->TCEmain_cmd[$table][$elementUid]);
                                            }
                                            if ($element[$inlineTablesConfig[$table]['parentField']] > 0) {
                                                $this->depthCounter = 0;
                                                $this->recursivelyCheckForRelationParents(
                                                    $element,
                                                    $Tlang,
                                                    $inlineTablesConfig[$table]['parentField'],
                                                    $inlineTablesConfig[$table]['childrenField']
                                                );
                                            }
                                        } elseif ($table === 'sys_file_reference') {
                                            $element = $this->getRawRecord($table, $elementUid);
                                            if ($element['uid_foreign'] && $element['tablenames'] && $element['fieldname']) {
                                                if ($GLOBALS['TCA'][$element['tablenames']]['columns'][$element['fieldname']]['config']['behaviour']['allowLanguageSynchronization']) {
                                                    if (isset($this->TCEmain_cmd[$table][$elementUid])) {
                                                        unset($this->TCEmain_cmd[$table][$elementUid]);
                                                    }
                                                    $this->TCEmain_cmd[$table][$elementUid]['localize'] = $Tlang;
                                                    $TCEmain_data[$table][$TuidString]['tablenames'] = $element['tablenames'];
                                                } else {
                                                    $parent = [];
                                                    if ($GLOBALS['TCA'][$element['tablenames']]['ctrl']['transOrigPointerField']) {
                                                        /** @var QueryBuilder $queryBuilder */
                                                        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($element['tablenames']);
                                                        $parent = $queryBuilder->select('*')
                                                            ->from($element['tablenames'])
                                                            ->where(
                                                                $queryBuilder->expr()->eq(
                                                                    $GLOBALS['TCA'][$element['tablenames']]['ctrl']['transOrigPointerField'],
                                                                    $queryBuilder->createNamedParameter(
                                                                        (int)$element['uid_foreign'],
                                                                        PDO::PARAM_INT
                                                                    )
                                                                ),
                                                                $queryBuilder->expr()->eq(
                                                                    'sys_language_uid',
                                                                    $queryBuilder->createNamedParameter(
                                                                        (int)$Tlang,
                                                                        PDO::PARAM_INT
                                                                    )
                                                                )
                                                            )
                                                            ->execute()
                                                            ->fetch();
                                                    }
                                                    if ($parent['uid'] > 0) {
                                                        if (isset($this->TCEmain_cmd[$element['tablenames']][$element['uid_foreign']])) {
                                                            unset($this->TCEmain_cmd[$element['tablenames']][$element['uid_foreign']]);
                                                        }
                                                        // Save for localization
                                                        if (empty($this->TCEmain_cmd[$element['tablenames']][$element['uid_foreign']]['inlineLocalizeSynchronize']['ids'])) {
                                                            $this->TCEmain_cmd[$element['tablenames']][$element['uid_foreign']]['inlineLocalizeSynchronize'] = [
                                                                'field' => $element['fieldname'],
                                                                'language' => $Tlang,
                                                                'action' => 'localize',
                                                                'ids' => [$elementUid],
                                                            ];
                                                        } else {
                                                            // Add element to existing localization array
                                                            $this->TCEmain_cmd[$element['tablenames']][$element['uid_foreign']]['inlineLocalizeSynchronize']['ids'][] = $elementUid;
                                                        }
                                                    }
                                                }
                                            }
                                        } else {
                                            //print "\nNEW\n";
                                            if (isset($this->TCEmain_cmd[$table][$elementUid])) {
                                                unset($this->TCEmain_cmd[$table][$elementUid]);
                                            }
                                            $this->TCEmain_cmd[$table][$elementUid]['localize'] = $Tlang;
                                            if (!empty($GLOBALS['TCA'][$table]['columns'][$Tfield])) {
                                                $configuration = $GLOBALS['TCA'][$table]['columns'][$Tfield]['config'];

                                                // Clear original slug field values to avoid weird autogenerated values for translated slugs
                                                if ($configuration['type'] === 'slug') {
                                                    unset($inputArray[$table][$elementUid][$key]);
                                                    continue;
                                                }
                                                if ($configuration['foreign_table']) {
                                                    /** @var RelationHandler $relationHandler */
                                                    // integrators have to make sure to configure fields of parent elements properly
                                                    // so they will do translations of their children automatically when translated
                                                    $relationHandler = GeneralUtility::makeInstance(RelationHandler::class);
                                                    $relationHandler->start(
                                                        $element[$Tfield],
                                                        $configuration['foreign_table'],
                                                        $configuration['MM'],
                                                        $elementUid,
                                                        $table,
                                                        $configuration
                                                    );
                                                    $relationHandler->processDeletePlaceholder();
                                                    $referenceUids = $relationHandler->tableArray[$configuration['foreign_table']];
                                                    if (!empty($referenceUids)) {
                                                        foreach ($referenceUids as $referenceUid) {
                                                            $this->childMappingArray[$configuration['foreign_table']][$referenceUid] = true;
                                                            if ($table !== 'pages') {
                                                                unset($this->TCEmain_cmd[$configuration['foreign_table']][$referenceUid]);
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                        $hooks = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['importNewTceMainCmd'];
                                        if (is_array($hooks)) {
                                            foreach ($hooks as $hookObj) {
                                                $parameters = [
                                                    'data' => $data,
                                                    'TCEmain_cmd' => $this->TCEmain_cmd,
                                                ];
                                                $this->TCEmain_cmd = GeneralUtility::callUserFunction(
                                                    $hookObj,
                                                    $parameters,
                                                    $this
                                                );
                                            }
                                        }
                                    }
                                    // If FlexForm, we set value in special way:
                                    if ($Tpath) {
                                        if (!is_array($TCEmain_data[$Ttable][$TuidString][$Tfield])) {
                                            $TCEmain_data[$Ttable][$TuidString][$Tfield] = [];
                                        }
                                        //TCEMAINDATA is passed as reference here:
                                        $flexToolObj->setArrayValueByPath(
                                            $Tpath,
                                            $TCEmain_data[$Ttable][$TuidString][$Tfield],
                                            $inputArray[$table][$elementUid][$key]
                                        );
                                        $_flexFormDiffArray[$key] = [
                                            'translated' => $inputArray[$table][$elementUid][$key],
                                            'default' => $tData['defaultValue'],
                                        ];
                                    } else {
                                        $TCEmain_data[$Ttable][$TuidString][$Tfield] = $inputArray[$table][$elementUid][$key];
                                    }
                                    unset($inputArray[$table][$elementUid][$key]); // Unsetting so in the end we can see if $inputArray was fully processed.
                                }
                                //debug($tData,'fields not set for: '.$elementUid.'-'.$key);
                                //debug($inputArray[$table],'inputarray');
                            }
                            if (is_array($inputArray[$table][$elementUid]) && !count($inputArray[$table][$elementUid])) {
                                unset($inputArray[$table][$elementUid]); // Unsetting so in the end we can see if $inputArray was fully processed.
                            }
                        }

                        $hooks = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['afterDataFieldsTranslated'];
                        if (is_array($hooks)) {
                            foreach ($hooks as $hookObj) {
                                $parameters = [
                                    'TCEmain_data' => $TCEmain_data,
                                    'TCEmain_cmd' => $this->TCEmain_cmd,
                                ];
                                $this->TCEmain_data = GeneralUtility::callUserFunction($hookObj, $parameters, $this);
                            }
                        }
                    }
                    if (is_array($inputArray[$table]) && !count($inputArray[$table])) {
                        unset($inputArray[$table]); // Unsetting so in the end we can see if $inputArray was fully processed.
                    }
                }
            }
            self::$targetLanguageID = $Tlang;
            // Execute CMD array: Localizing records:
            /** @var DataHandler $tce */
            $tce = GeneralUtility::makeInstance(DataHandler::class);
            $tce->neverHideAtCopy = $this->emConfiguration->isEnableNeverHideAtCopy();
            $tce->dontProcessTransformations = $this->emConfiguration->isImportDontProcessTransformations();
            $tce->isImporting = true;
            if (count($this->TCEmain_cmd)) {
                $tce->start([], $this->TCEmain_cmd);
                $tce->process_cmdmap();
                if (count($tce->errorLog)) {
                    debug($tce->errorLog, 'TCEmain localization errors:');
                }
            }
            // Before remapping
            $this->logger->debug(__FILE__ . ': ' . __LINE__ . ': TCEmain_data before remapping: ' . implode(
                ', ',
                $TCEmain_data
            ));
            // Remapping those elements which are new:
            $this->lastTCEMAINCommandsCount = 0;
            $slugFields = [];
            // Find slug fields for each table
            foreach (array_keys($TCEmain_data) as $table) {
                if (!empty($GLOBALS['TCA'][$table]) && !empty($GLOBALS['TCA'][$table]['columns'])) {
                    foreach ($GLOBALS['TCA'][$table]['columns'] as $columnName => $column) {
                        if (!empty($column['config']) && !empty($column['config']['type']) && $column['config']['type'] === 'slug') {
                            $slugFields[$table][$columnName] = '';
                        }
                    }
                }
            }
            foreach ($TCEmain_data as $table => $items) {
                foreach ($items as $TuidString => $fields) {
                    list($Tuid, $Tlang, $TdefRecord) = explode('/', $TuidString);
                    $this->lastTCEMAINCommandsCount++;
                    if ($Tuid === 'NEW') {
                        // if there are slug fields and there is no translation value for them
                        // make sure they will be empty to trigger automatic slug generation with translated values
                        if (!empty($slugFields[$table])) {
                            $fields = array_merge($slugFields[$table], $fields);
                        }
                        if ($tce->copyMappingArray_merged[$table][$TdefRecord]) {
                            $TCEmain_data[$table][BackendUtility::wsMapId(
                                $table,
                                $tce->copyMappingArray_merged[$table][$TdefRecord]
                            )] = $fields;
                        } else {
                            if ($this->childMappingArray[$table][$TdefRecord]) {
                                if ($this->childMappingArray[$table][$TdefRecord] === true) {
                                    /** @var QueryBuilder $queryBuilder */
                                    $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
                                    $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

                                    $translatedRecordRaw = $queryBuilder
                                        ->select('*')
                                        ->from($table)
                                        ->where(
                                            $queryBuilder->expr()->eq(
                                                $GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'],
                                                $queryBuilder->createNamedParameter((int)$TdefRecord, PDO::PARAM_INT)
                                            ),
                                            $queryBuilder->expr()->eq(
                                                'sys_language_uid',
                                                $queryBuilder->createNamedParameter((int)$Tlang, PDO::PARAM_INT)
                                            )
                                        )
                                        ->execute()
                                        ->fetch();

                                    if ($translatedRecordRaw['uid']) {
                                        $this->childMappingArray[$table][$TdefRecord] = $translatedRecordRaw['uid'];
                                    }
                                }
                                if ($this->childMappingArray[$table][$TdefRecord]) {
                                    if ($this->emConfiguration->isEnableNeverHideAtCopy() &&
                                        $GLOBALS['TCA'][$table]['ctrl']['enablecolumns'] &&
                                        $GLOBALS['TCA'][$table]['ctrl']['enablecolumns']['disabled']) {
                                        $fields[$GLOBALS['TCA'][$table]['ctrl']['enablecolumns']['disabled']] = 0;
                                    }
                                    $TCEmain_data[$table][BackendUtility::wsMapId(
                                        $table,
                                        $this->childMappingArray[$table][$TdefRecord]
                                    )] = $fields;
                                }
                            } else {
                                $this->logger->debug(__FILE__ . ': ' . __LINE__ . ': Record "' . $table . ':' . $TdefRecord . '" was NOT localized as it should have been!');
                            }
                        }
                        unset($TCEmain_data[$table][$TuidString]);
                    }
                }
            }
            // After remapping
            $this->logger->debug(__FILE__ . ': ' . __LINE__ . ': TCEmain_data after remapping: ' . implode(
                ', ',
                $TCEmain_data
            ));
            // Now, submitting translation data:
            /** @var DataHandler $tce */
            $tce = GeneralUtility::makeInstance(DataHandler::class);
            $tce->neverHideAtCopy = $this->emConfiguration->isEnableNeverHideAtCopy();
            $tce->dontProcessTransformations = true;
            $tce->isImporting = true;
            foreach (array_chunk($TCEmain_data, 100, true) as $dataPart) {
                $tce->start(
                    $dataPart,
                    []
                ); // check has been done previously that there is a backend user which is Admin and also in live workspace
                $tce->process_datamap();
            }
            self::$targetLanguageID = null;
            if (count($tce->errorLog)) {
                $this->logger->debug(__FILE__ . ': ' . __LINE__ . ': TCEmain update errors: ' . implode(
                    ', ',
                    $tce->errorLog
                ));
            }
            if (count($tce->autoVersionIdMap) && count($_flexFormDiffArray)) {
                $this->logger->debug(__FILE__ . ': ' . __LINE__ . ': flexFormDiffArry: ' . implode(
                    ', ',
                    $this->flexFormDiffArray
                ));
                foreach ($_flexFormDiffArray as $key => $value) {
                    list($Ttable, $Tuid, $Trest) = explode(':', $key, 3);
                    if ($tce->autoVersionIdMap[$Ttable][$Tuid]) {
                        $_flexFormDiffArray[$Ttable . ':' . $tce->autoVersionIdMap[$Ttable][$Tuid] . ':' . $Trest] = $_flexFormDiffArray[$key];
                        unset($_flexFormDiffArray[$key]);
                    }
                }
                /** @phpstan-ignore-next-line */
                $this->logger->debug(__FILE__ . ': ' . __LINE__ . ': autoVersionIdMap: ' . $tce->autoVersionIdMap);
                $this->logger->debug(__FILE__ . ': ' . __LINE__ . ': _flexFormDiffArray: ' . implode(
                    ', ',
                    $_flexFormDiffArray
                ));
            }
            // Should be empty now - or there were more information in the incoming array than there should be!
            if (count($inputArray)) {
                debug($inputArray, 'These fields were ignored since they were not in the configuration 2:');
            }
            return $_flexFormDiffArray;
        }
        return false;
    }

    /**
     * @param string $table
     * @param int $elementUid
     * @return array
     */
    protected function getRawRecord(string $table, int $elementUid): array
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $row = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter((int)$elementUid, PDO::PARAM_INT)
                )
            )
            ->execute()
            ->fetch();

        return $row ?: [];
    }

    /**
     * @param array $element
     * @param int $Tlang
     * @param string $parentField
     * @param string $childrenField
     */
    protected function recursivelyCheckForRelationParents($element, $Tlang, $parentField, $childrenField)
    {
        $this->depthCounter++;
        if ($this->depthCounter < 100 && !isset($this->checkedParentRecords[$parentField][$element['uid']])) {
            $this->checkedParentRecords[$parentField][$element['uid']] = true;
            $translatedParent = [];
            if ($element[$parentField] > 0) {
                /** @var QueryBuilder $queryBuilder */
                $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
                $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

                $translatedParent = $queryBuilder
                    ->select('*')
                    ->from('tt_content')
                    ->where(
                        $queryBuilder->expr()->eq(
                            $GLOBALS['TCA']['tt_content']['ctrl']['transOrigPointerField'],
                            $queryBuilder->createNamedParameter((int)$element[$parentField], PDO::PARAM_INT)
                        ),
                        $queryBuilder->expr()->eq(
                            'sys_language_uid',
                            $queryBuilder->createNamedParameter((int)$Tlang, PDO::PARAM_INT)
                        )
                    )
                    ->execute()
                    ->fetch();
            }
            if ($translatedParent['uid'] > 0) {
                // Save for localization
                if (empty($this->TCEmain_cmd['tt_content'][$translatedParent['uid']]['inlineLocalizeSynchronize']['ids'])) {
                    $this->TCEmain_cmd['tt_content'][$translatedParent['uid']]['inlineLocalizeSynchronize'] = [
                        'field' => $childrenField,
                        'language' => $Tlang,
                        'action' => 'localize',
                        'ids' => [$element['uid']],
                    ];
                } else {
                    // Add element to existing localization array
                    $this->TCEmain_cmd['tt_content'][$translatedParent['uid']]['inlineLocalizeSynchronize']['ids'][] = $element['uid'];
                }
            } else {
                if ($element[$parentField] > 0) {
                    $parent = $this->getRawRecord('tt_content', (int)$element[$parentField]);
                    $this->recursivelyCheckForRelationParents($parent, $Tlang, $parentField, $childrenField);
                } else {
                    $this->TCEmain_cmd['tt_content'][$element['uid']]['localize'] = $Tlang;
                }
            }
        }
    }
}
