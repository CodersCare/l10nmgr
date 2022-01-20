<?php

namespace Localizationteam\L10nmgr\Model\Tools;

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
 * Contains translation tools
 *
 * @author Kasper Skaarhoj <kasperYYYY@typo3.com>
 */

use Localizationteam\L10nmgr\Constants;
use Localizationteam\L10nmgr\LanguageRestriction\Collection\LanguageRestrictionCollection;
use Localizationteam\L10nmgr\Traits\BackendUserTrait;
use PDO;
use TYPO3\CMS\Backend\Configuration\TranslationConfigurationProvider;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\QueryHelper;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\DiffUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Contains translation tools
 *
 * @author Kasper Skaarhoj <kasperYYYY@typo3.com>
 */
class Tools
{
    use BackendUserTrait;

    // External:
    /**
     * @var array
     */
    public static $systemLanguages;

    /**
     * Cache the TCA configuration of tables with their types during runtime
     *
     * @var array
     * @see self::getTCAtypes()
     */
    protected static $tcaTableTypeConfigurationCache = [];

    // Array of sys_language_uids, eg. array(1,2)
    /**
     * @var array
     */
    public $filters = [
        'fieldTypes' => 'text,input',
        'noEmptyValues' => true,
        'noIntegers' => true,
        'l10n_categories' => '', // could be "text,media" for instance.
    ]; // If TRUE, when fields are not included there will be shown a detailed explanation.

    /**
     * @var array
     */
    public $previewLanguages = []; // If TRUE, do not call filter function

    /**
     * @var bool
     */
    public $onlyForcedSourceLanguage = false; //if set to true only records that exist also in the forced source language will be exported

    /**
     * @var bool
     */
    public $verbose = true; //if set to true also FCE with language setting default will be included (not only All)

    /**
     * @var bool
     */
    public $bypassFilter = false; // Object to t3lib_transl8tools, set in constructor

    /**
     * @var bool
     */
    public $includeFceWithDefaultLanguage = false; // Output for translation details

    // Internal:
    /**
     * @var TranslationConfigurationProvider|null
     */
    public $t8Tools;

    /**
     * @var array
     */
    protected $detailsOutput = []; // System languages initialized

    /**
     * @var array
     */
    protected $sysLanguages = []; // FlexForm diff data

    /**
     * @var array
     */
    protected $flexFormDiff = []; // System languages records, loaded by constructor

    /**
     * @var array|null
     */
    protected $sys_languages = [];

    /**
     * @var array
     */
    protected $indexFilterObjects = [];

    /**
     * @var array
     */
    protected $_callBackParams_translationDiffsourceXMLArray;

    /**
     * @var array
     */
    protected $_callBackParams_translationXMLArray;

    /**
     * @var array
     */
    protected $_callBackParams_previewLanguageXMLArrays;

    /**
     * @var string
     */
    protected $_callBackParams_keyForTranslationDetails;

    /**
     * @var array
     */
    protected $_callBackParams_currentRow;

    /**
     * Constructor
     * Setting up internal variable ->t8Tools
     */
    public function __construct()
    {
        $this->t8Tools = GeneralUtility::makeInstance(TranslationConfigurationProvider::class);
        // Find all system languages:
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_language');
        $this->sys_languages = $queryBuilder->select('*')->from('sys_language')->execute()->fetchAll();
    }

    /**
     * FlexForm call back function, see translationDetails
     *
     * @param array $dsArr Data Structure
     * @param string $dataValue Data value
     * @param array $PA Various stuff in an array
     * @param string $structurePath Path to location in flexform
     * @param FlexFormTools $pObj parent object
     */
    public function translationDetails_flexFormCallBack($dsArr, $dataValue, $PA, $structurePath, $pObj)
    {
        // Only take lead from default values (since this is "Inheritance" localization we parse for)
        if (substr($structurePath, -5) == '/vDEF') {
            // So, find translated value:
            $baseStructPath = substr($structurePath, 0, -3);
            $structurePath = $baseStructPath . $this->detailsOutput['ISOcode'];
            $translValue = $pObj->getArrayValueByPath($structurePath, $pObj->traverseFlexFormXMLData_Data);
            // Generate preview values:
            $previewLanguageValues = [];
            foreach ($this->previewLanguages as $prevSysUid) {
                $previewLanguageValues[$prevSysUid] = $pObj->getArrayValueByPath(
                    $baseStructPath . $this->sysLanguages[$prevSysUid]['ISOcode'],
                    $pObj->traverseFlexFormXMLData_Data
                );
            }
            $key = $ffKey = $PA['table'] . ':' . BackendUtility::wsMapId(
                $PA['table'],
                $PA['uid']
            ) . ':' . $PA['field'] . ':' . $structurePath;
            $ffKeyOrig = $PA['table'] . ':' . $PA['uid'] . ':' . $PA['field'] . ':' . $structurePath;
            // Now, in case this record has just been created in the workspace the diff-information is still found bound to the UID of the original record.
            // So we will look for that until it has been created for the workspace record:
            if (!is_array($this->flexFormDiff[$ffKey]) && is_array($this->flexFormDiff[$ffKeyOrig])) {
                $ffKey = $ffKeyOrig;
                // debug('orig...');
            }
            // Look for diff-value inside the XML (new way):
            if ($GLOBALS['TYPO3_CONF_VARS']['BE']['flexFormXMLincludeDiffBase']) {
                $diffDefaultValue = $pObj->getArrayValueByPath(
                    $structurePath . '.vDEFbase',
                    $pObj->traverseFlexFormXMLData_Data
                );
            } else {
                // Set diff-value from l10n-cfg record (deprecated)
                if (is_array($this->flexFormDiff[$ffKey]) && trim($this->flexFormDiff[$ffKey]['translated']) === trim($translValue)) {
                    $diffDefaultValue = $this->flexFormDiff[$ffKey]['default'];
                } else {
                    $diffDefaultValue = '';
                }
            }
            // Add field:
            $this->translationDetails_addField(
                $key,
                $dsArr['TCEforms'],
                $dataValue,
                $translValue,
                $diffDefaultValue,
                $previewLanguageValues
            );
        }
        unset($pObj);
    }

    /**
     * Add field to detailsOutput array. First, a lot of checks are done...
     *
     * @param string $key Key is a combination of table, uid, field and structure path, identifying the field
     * @param array $TCEformsCfg TCA configuration for field
     * @param string $dataValue Default value (current)
     * @param string $translationValue Translated value (current)
     * @param string $diffDefaultValue Default value of time of current translated value (used for diff'ing with $dataValue)
     * @param array $previewLanguageValues Array of preview language values identified by keys (which are sys_language uids)
     * @param array $contentRow Content row
     */
    protected function translationDetails_addField(
        $key,
        $TCEformsCfg,
        $dataValue,
        $translationValue,
        $diffDefaultValue = '',
        $previewLanguageValues = [],
        $contentRow = []
    ) {
        $msg = '';
        list($kTableName, , $kFieldName) = explode(':', $key);
        if ($TCEformsCfg['config']['type'] !== 'flex') {
            if ($TCEformsCfg['l10n_mode'] != 'exclude') {
                if ($TCEformsCfg['l10n_mode'] == 'mergeIfNotBlank') {
                    $msg .= 'This field is optional. If not filled in, the default language value will be used.';
                }
                if ((
                    GeneralUtility::inList('shortcut,shortcut_mode,urltype,url_scheme', $kFieldName)
                        && $kTableName === 'pages'
                )
                    || $TCEformsCfg['labelField'] === $kFieldName
                ) {
                    $this->bypassFilter = true;
                }
                $is_HIDE_L10N_SIBLINGS = false;
                if (is_array($TCEformsCfg['displayCond'])) {
                    $GLOBALS['is_HIDE_L10N_SIBLINGS'] = $is_HIDE_L10N_SIBLINGS;
                    array_walk_recursive(
                        $TCEformsCfg['displayCond'],
                        function ($i, $k) {
                            if (GeneralUtility::isFirstPartOfStr($i, 'HIDE_L10N_SIBLINGS')) {
                                $GLOBALS['is_HIDE_L10N_SIBLINGS'] = true;
                            }
                        }
                    );
                    $is_HIDE_L10N_SIBLINGS = $GLOBALS['is_HIDE_L10N_SIBLINGS'];
                } else {
                    $is_HIDE_L10N_SIBLINGS = GeneralUtility::isFirstPartOfStr(
                        $TCEformsCfg['displayCond'],
                        'HIDE_L10N_SIBLINGS'
                    );
                }
                $l10nmgrConfiguration = $TCEformsCfg['l10nmgr'];
                $exclude = false;
                $bypassFilter = [];
                if (!empty($l10nmgrConfiguration)) {
                    $exclude = $l10nmgrConfiguration['exclude'];
                    $bypassFilter = $l10nmgrConfiguration['bypassFilter'];
                }
                if (!$is_HIDE_L10N_SIBLINGS && !$exclude) {
                    if (!GeneralUtility::isFirstPartOfStr($kFieldName, 't3ver_')) {
                        if (!$this->filters['l10n_categories']
                            || GeneralUtility::inList($this->filters['l10n_categories'], $TCEformsCfg['l10n_cat'])
                            || (bool)$bypassFilter['l10n_categories']
                            || $this->bypassFilter
                        ) {
                            if (!$this->filters['fieldTypes']
                                || GeneralUtility::inList($this->filters['fieldTypes'], $TCEformsCfg['config']['type'])
                                || $bypassFilter && $bypassFilter['fieldTypes']
                                || $this->bypassFilter
                            ) {
                                if (!$this->filters['noEmptyValues'] || !(!$dataValue && !$translationValue)
                                    || !empty($previewLanguageValues[key($previewLanguageValues)])
                                    || $bypassFilter && $bypassFilter['noEmptyValues']
                                    || $this->bypassFilter
                                ) {
                                    // Checking that no translation value exists either; if a translation value is found it is considered that it should be translated
                                    // even if the default value is empty for some reason.
                                    if (!$this->filters['noIntegers']
                                        || !MathUtility::canBeInterpretedAsInteger($dataValue)
                                        || $bypassFilter && $bypassFilter['noIntegers']
                                        || $this->bypassFilter
                                    ) {
                                        $this->detailsOutput['fields'][$key] = [
                                            'defaultValue' => $dataValue,
                                            'translationValue' => $translationValue,
                                            'diffDefaultValue' => $TCEformsCfg['l10n_display'] != 'hideDiff' ? $diffDefaultValue : '',
                                            'previewLanguageValues' => $previewLanguageValues,
                                            'msg' => $msg,
                                            'readOnly' => $TCEformsCfg['l10n_display'] == 'defaultAsReadonly',
                                            'fieldType' => $TCEformsCfg['config']['type'],
                                            'isRTE' => $this->_isRTEField(
                                                $key,
                                                $TCEformsCfg,
                                                $contentRow
                                            ),
                                        ];
                                    } elseif ($this->verbose) {
                                        $this->detailsOutput['fields'][$key] = 'Bypassing; ->filters[noIntegers] was set and dataValue "' . $dataValue . '" was an integer';
                                    }
                                } elseif ($this->verbose) {
                                    $this->detailsOutput['fields'][$key] = 'Bypassing; ->filters[noEmptyValues] was set and dataValue "'
                                        . $dataValue . '" was empty an field was no label field and no translation or alternative source language value found either.';
                                }
                            } elseif ($this->verbose) {
                                $this->detailsOutput['fields'][$key] = 'Bypassing; fields of type "' . $TCEformsCfg['config']['type'] . '" was filtered out in ->filters[fieldTypes]';
                            }
                        } elseif ($this->verbose) {
                            $this->detailsOutput['fields'][$key] = 'Bypassing; ->filters[l10n_categories] was set to "'
                                . $this->filters['l10n_categories'] . '" and l10n_cat for field ("' . $TCEformsCfg['l10n_cat'] . '") did not match.';
                        }
                    } elseif ($this->verbose) {
                        $this->detailsOutput['fields'][$key] = 'Bypassing; Fieldname "' . $kFieldName . '" was prefixed "t3ver_"';
                    }
                } elseif ($this->verbose) {
                    $this->detailsOutput['fields'][$key] = 'Bypassing; displayCondition HIDE_L10N_SIBLINGS was set.';
                }
            } elseif ($this->verbose) {
                $this->detailsOutput['fields'][$key] = 'Bypassing; "l10n_mode" for the field was "exclude" and field is not translated then.';
            }
        } elseif ($this->verbose) {
            $this->detailsOutput['fields'][$key] = 'Bypassing; fields of type "flex" can only be translated in the context of an "ALL" language record';
        }
        $this->bypassFilter = false;
    }

    /**
     * Check if the field is an RTE in the Backend, for a given row of data
     *
     * @param string $key Key is a combination of table, uid, field and structure path, identifying the field
     * @param array $TCEformsCfg TCA configuration for field
     * @param array $contentRow The table row being handled
     * @return bool
     */
    protected function _isRTEField($key, $TCEformsCfg, $contentRow)
    {
        $isRTE = false;
        if (is_array($contentRow)) {
            list($table, $uid, $field) = explode(':', $key);
            $TCAtype = BackendUtility::getTCAtypeValue($table, $contentRow);
            // Check if the RTE is explicitly declared in the defaultExtras configuration
            if (isset($TCEformsCfg['config']['enableRichtext']) && $TCEformsCfg['config']['enableRichtext']) {
                $isRTE = true;
            // If not, then we must check per type configuration
            } else {
                if (
                    isset($GLOBALS['TCA'][$table]['types'][$TCAtype]['columnsOverrides'])
                    && isset($GLOBALS['TCA'][$table]['types'][$TCAtype]['columnsOverrides'][$field])
                    && isset($GLOBALS['TCA'][$table]['types'][$TCAtype]['columnsOverrides'][$field]['config']['enableRichtext'])
                    && $GLOBALS['TCA'][$table]['types'][$TCAtype]['columnsOverrides'][$field]['config']['enableRichtext']
                ) {
                    $isRTE = true;
                } else {
                    $typesDefinition = $this->getTCAtypes($table, $contentRow, true);
                    $isRTE = !empty($typesDefinition[$field]['spec']['richtext']);
                }
            }
        }
        return $isRTE;
    }

    /**
     * Returns the "types" configuration parsed into an array for the record, $rec, from table, $table
     *
     * @param string $table Table name (present in TCA)
     * @param array $rec Record from $table
     * @param bool $useFieldNameAsKey If $useFieldNameAsKey is set, then the fieldname is associative keys in the return array, otherwise just numeric keys.
     * @return array|null
     */
    public static function getTCAtypes($table, $rec, $useFieldNameAsKey = false)
    {
        if (isset($GLOBALS['TCA'][$table])) {
            // Get type value:
            $fieldValue = BackendUtility::getTCAtypeValue($table, $rec);
            $cacheIdentifier = $table . '-type-' . $fieldValue . '-fnk-' . $useFieldNameAsKey;

            // Fetch from first-level-cache if available
            if (isset(self::$tcaTableTypeConfigurationCache[$cacheIdentifier])) {
                return self::$tcaTableTypeConfigurationCache[$cacheIdentifier];
            }

            // Get typesConf
            $typesConf = $GLOBALS['TCA'][$table]['types'][$fieldValue] ?? null;
            // Get fields list and traverse it
            $fieldList = explode(',', $typesConf['showitem']);

            // Add subtype fields e.g. for a valid RTE transformation
            // The RTE runs the DB -> RTE transformation only, if the RTE field is part of the getTCAtypes array
            if (isset($typesConf['subtype_value_field'])) {
                $subType = $rec[$typesConf['subtype_value_field']];
                if (isset($typesConf['subtypes_addlist'][$subType])) {
                    $subFields = GeneralUtility::trimExplode(',', $typesConf['subtypes_addlist'][$subType], true);
                    $fieldList = array_merge($fieldList, $subFields);
                }
            }

            // Add palette fields e.g. for a valid RTE transformation
            $paletteFieldList = [];
            foreach ($fieldList as $fieldData) {
                $fieldDataArray = GeneralUtility::trimExplode(';', $fieldData);
                // first two entries would be fieldname and altTitle, they are not used here.
                $pPalette = $fieldDataArray[2] ?? null;
                if ($pPalette
                    && isset($GLOBALS['TCA'][$table]['palettes'][$pPalette])
                    && is_array($GLOBALS['TCA'][$table]['palettes'][$pPalette])
                    && isset($GLOBALS['TCA'][$table]['palettes'][$pPalette]['showitem'])
                ) {
                    $paletteFields = GeneralUtility::trimExplode(
                        ',',
                        $GLOBALS['TCA'][$table]['palettes'][$pPalette]['showitem'],
                        true
                    );
                    foreach ($paletteFields as $paletteField) {
                        if ($paletteField !== '--linebreak--') {
                            $paletteFieldList[] = $paletteField;
                        }
                    }
                }
            }
            $fieldList = array_merge($fieldList, $paletteFieldList);
            $altFieldList = [];
            // Traverse fields in types config and parse the configuration into a nice array:
            foreach ($fieldList as $k => $v) {
                $vArray = GeneralUtility::trimExplode(';', $v);
                $fieldList[$k] = [
                    'field' => $vArray[0],
                    'title' => $vArray[1] ?? null,
                    'palette' => $vArray[2] ?? null,
                    'spec' => [],
                    'origString' => $v,
                ];
                if ($useFieldNameAsKey) {
                    $altFieldList[$fieldList[$k]['field']] = $fieldList[$k];
                }
            }
            if ($useFieldNameAsKey) {
                $fieldList = $altFieldList;
            }

            // Add to first-level-cache
            self::$tcaTableTypeConfigurationCache[$cacheIdentifier] = $fieldList;

            // Return array:
            return $fieldList;
        }
        return null;
    }

    /**
     * FlexForm call back function, see translationDetails. This is used for langDatabaseOverlay FCEs!
     * Two additional paramas are used:
     * $this->_callBackParams_translationXMLArray
     * $this->_callBackParams_keyForTranslationDetails
     *
     * @param array $dsArr Data Structure
     * @param string $dataValue Data value
     * @param array $PA Various stuff in an array
     * @param string $structurePath Path to location in flexform
     * @param FlexFormTools $pObj parent object
     */
    public function translationDetails_flexFormCallBackForOverlay($dsArr, $dataValue, $PA, $structurePath, $pObj)
    {
        //echo $dataValue.'<hr>';
        $translValue = $pObj->getArrayValueByPath($structurePath, $this->_callBackParams_translationXMLArray);
        $diffDefaultValue = $pObj->getArrayValueByPath(
            $structurePath,
            $this->_callBackParams_translationDiffsourceXMLArray
        );
        $previewLanguageValues = [];
        foreach ($this->previewLanguages as $prevSysUid) {
            $previewLanguageValues[$prevSysUid] = $pObj->getArrayValueByPath(
                $structurePath,
                $this->_callBackParams_previewLanguageXMLArrays[$prevSysUid]
            );
        }
        $key = $this->_callBackParams_keyForTranslationDetails . ':' . $structurePath;
        $this->translationDetails_addField(
            $key,
            $dsArr['TCEforms'],
            $dataValue,
            $translValue,
            $diffDefaultValue,
            $previewLanguageValues,
            $this->_callBackParams_currentRow
        );
        unset($pObj);
    }

    /**
     * Update index for record
     *
     * @param string $table Table name
     * @param int $uid UID
     * @return string
     */
    public function updateIndexForRecord($table, $uid)
    {
        $output = '';
        if ($table == 'pages') {
            $items = $this->indexDetailsPage($uid);
        } else {
            $items = [];
            if ($tmp = $this->indexDetailsRecord($table, $uid)) {
                $items[$table][$uid] = $tmp;
            }
        }
        if (count($items)) {
            foreach ($items as $tt => $rr) {
                foreach ($rr as $rUid => $rDetails) {
                    $this->updateIndexTableFromDetailsArray($rDetails);
                    $output .= 'Updated <em>' . $tt . ':' . $rUid . '</em></br>';
                }
            }
        } else {
            $output .= 'No records to update (you can only update records that can actually be translated)';
        }
        return $output;
    }

    /**
     * Creating localization index for all records on a page
     *
     * @param int $pageId Page ID
     * @param int $previewLanguage
     * @return array Array of the traversed items
     */
    public function indexDetailsPage($pageId, $previewLanguage = 0)
    {
        $items = [];
        // Traverse tables:
        foreach ($GLOBALS['TCA'] as $table => $cfg) {
            // Only those tables we want to work on:
            if ($table === 'pages') {
                $items[$table][$pageId] = $this->indexDetailsRecord('pages', $pageId, $previewLanguage);
            } else {
                $allRows = $this->getRecordsToTranslateFromTable($table, $pageId, $previewLanguage);
                if (is_array($allRows)) {
                    if (count($allRows)) {
                        // Now, for each record, look for localization:
                        foreach ($allRows as $row) {
                            if (is_array($row)) {
                                $items[$table][$row['uid']] = $this->indexDetailsRecord(
                                    $table,
                                    $row['uid'],
                                    $previewLanguage
                                );
                            }
                        }
                    }
                }
            }
        }
        return $items;
    }

    /**
     * Creating localization index for a single record (which must be default/international language and an online version!)
     *
     * @param string $table Table name
     * @param int $uid Record UID
     * @param int|null $languageID Language ID of the record
     * @return mixed FALSE if the input record is not one that can be translated. Otherwise an array holding information about the status.
     */
    public function indexDetailsRecord($table, $uid, $languageID = null)
    {
        $rec = $table == 'pages'
            ? BackendUtility::getRecord($table, $uid)
            : $this->getSingleRecordToTranslate($table, $uid, $languageID);

        if (is_array($rec) && $rec['pid'] != -1 && $this->canUserEditRecord($table, $rec)) {
            $pid = $table == 'pages' ? $rec['uid'] : $rec['pid'];
            if ($this->bypassFilter || $this->filterIndex($table, $uid, $pid)) {
                BackendUtility::workspaceOL($table, $rec);
                $items = [];
                foreach ($this->sys_languages as $r) {
                    if (is_null($languageID) || $r['uid'] === $languageID) {
                        $items['fullDetails'][$r['uid']] = $this->translationDetails(
                            $table,
                            $rec,
                            $r['uid'],
                            $languageID
                        );
                        $items['indexRecord'][$r['uid']] = $this->compileIndexRecord(
                            $items['fullDetails'][$r['uid']],
                            $r['uid'],
                            $pid
                        );
                    }
                }
                return $items;
            }
        }
        return false;
    }

    /**
     * Selecting single record from a table filtering whether it is a default language / international element.
     *
     * @param string $table Table name
     * @param int $uid Record uid
     * @param int $previewLanguage
     * @return array|bool Record array if found, otherwise FALSE
     */
    protected function getSingleRecordToTranslate($table, $uid, $previewLanguage = 0)
    {
        $fields = ['*'];
        if (!$GLOBALS['BE_USER']->isAdmin()) {
            $fields = $this->getAllowedFieldsForTable($table);
            $fields = array_filter(
                array_merge(
                    $fields,
                    [
                        'uid',
                        'pid',
                        $GLOBALS['TCA'][$table]['ctrl']['languageField'],
                        $GLOBALS['TCA'][$table]['ctrl']['translationSource'],
                        $GLOBALS['TCA'][$table]['ctrl']['transOrigDiffSourceField'],
                        $GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'],
                    ]
                )
            );
        }
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class));
        $queryBuilder->select(...$fields)->from($table);
        if ($previewLanguage > 0) {
            $constraints = [];
            $constraints[] = $queryBuilder->expr()->eq(
                $GLOBALS['TCA'][$table]['ctrl']['languageField'],
                $queryBuilder->createNamedParameter($previewLanguage, PDO::PARAM_INT)
            );

            if (isset($GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'])) {
                $constraints[] = $queryBuilder->expr()->eq(
                    $GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'],
                    $queryBuilder->createNamedParameter(0, PDO::PARAM_INT)
                );
            }

            $queryBuilder->where(
                $queryBuilder->expr()->andX(
                    $queryBuilder->expr()->eq(
                        'uid',
                        $queryBuilder->createNamedParameter((int)$uid, PDO::PARAM_INT)
                    ),
                    $queryBuilder->expr()->orX(
                        $queryBuilder->expr()->lte(
                            $GLOBALS['TCA'][$table]['ctrl']['languageField'],
                            $queryBuilder->createNamedParameter(0, PDO::PARAM_INT)
                        ),
                        $queryBuilder->expr()->andX(...$constraints)
                    )
                )
            );
        } else {
            $queryBuilder->where(
                $queryBuilder->expr()->andX(
                    $queryBuilder->expr()->eq(
                        'uid',
                        $queryBuilder->createNamedParameter((int)$uid, PDO::PARAM_INT)
                    ),
                    $queryBuilder->expr()->lte(
                        $GLOBALS['TCA'][$table]['ctrl']['languageField'],
                        $queryBuilder->createNamedParameter(0, PDO::PARAM_INT)
                    )
                )
            );
        }

        // First, select all records that are default language OR international:
        $allRows = $queryBuilder->execute()->fetchAll();
        return is_array($allRows) && count($allRows) ? $allRows[0] : false;
    }

    /**
     * Fetches allowed fields for the current Backend user. This function is public to allow using it from
     * other classes and hooks.
     *
     * @param string $table
     * @return string[]
     */
    public function getAllowedFieldsForTable($table)
    {
        $cache = GeneralUtility::makeInstance(CacheManager::class)->getCache('cache_runtime');
        $key = 'l10nmgr-allowed-fields-' . $table;
        if ($cache->has($key)) {
            $allowedFields = $cache->get($key);
        } else {
            $configuredFields = array_keys($GLOBALS['TCA'][$table]['columns']);
            $tableColumns = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable($table)
                ->getSchemaManager()
                ->listTableColumns($table);
            $fieldsInDatabase = [];
            foreach ($tableColumns as $column) {
                $fieldsInDatabase[] = $column->getName();
            }
            $allowedFields = array_intersect($configuredFields, $fieldsInDatabase);
            if (!$GLOBALS['BE_USER']->isAdmin()) {
                $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
                $dataHandler->start([], []);
                $excludedFields = $dataHandler->getExcludeListArray();
                unset($dataHandler);
                // Filter elements for the current table only
                $excludedFields = array_filter(
                    $excludedFields,
                    function ($element) use ($table) {
                        return GeneralUtility::isFirstPartOfStr($element, $table);
                    }
                );
                // Remove table prefix
                array_walk(
                    $excludedFields,
                    function (&$element) use ($table) {
                        $element = substr($element, strlen($table) + 1);
                    }
                );
                $allowedFields = array_diff($allowedFields, $excludedFields);
            }
            $cache->set($key, $allowedFields);
        }

        return $allowedFields;
    }

    /**
     * Checks if the user can edit the record. This function is public to allow using it from
     * hooks or other classes.
     *
     * @param string $tableName
     * @param array $record
     * @return bool
     */
    public function canUserEditRecord($tableName, $record)
    {
        if ($GLOBALS['BE_USER']->isAdmin()) {
            return true;
        }

        $cache = GeneralUtility::makeInstance(CacheManager::class)->getCache('cache_runtime');
        $keyFormat = 'l10nmgr-allowed-state-%s-%d';
        $key = sprintf($keyFormat, $tableName, $record['uid']);

        if ($cache->has($key)) {
            $result = $cache->get($key);
        } else {
            if ($tableName === 'pages') {
                // See EXT:recordlist/Classes/RecordList::main()
                $permissions = $GLOBALS['BE_USER']->calcPerms($record);
                $result = ($permissions & Permission::PAGE_EDIT) && ($GLOBALS['BE_USER']->isAdmin() || (int)$record['editlock'] === 0);
            } else {
                $result = true;
                if ($record['pid'] > 0) {
                    $pageKey = sprintf($keyFormat, 'pages', $record['pid']);
                    if ($cache->has($pageKey)) {
                        $result = $cache->get($pageKey);
                    } else {
                        $pageRecord = BackendUtility::getRecord('pages', $record['pid']);
                        $result = $this->canUserEditRecord('pages', $pageRecord);
                    }
                }
                if ($result) {
                    // Additional record check
                    $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
                    $dataHandler->start([], []);
                    $result = $dataHandler->checkRecordUpdateAccess(
                        $tableName,
                        $record['uid']
                    ) && $GLOBALS['BE_USER']->recordEditAccessInternals($tableName, $record['uid']);
                }
            }
            $cache->set($key, $result);
        }

        return $result;
    }

    /**
     * Returns true if the record can be included in index.
     *
     * @param $table
     * @param $uid
     * @param $pageId
     * @return bool
     */
    protected function filterIndex($table, $uid, $pageId)
    {
        // Initialize (only first time)
        if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['indexFilter'])
            && !is_array($this->indexFilterObjects[$pageId])
        ) {
            $this->indexFilterObjects[$pageId] = [];
            $c = 0;
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['indexFilter'] as $objArray) {
                $instance = GeneralUtility::makeInstance($objArray[0]);
                $this->indexFilterObjects[$pageId][$c] = &$instance;
                $this->indexFilterObjects[$pageId][$c]->init($pageId);
                $c++;
            }
        }
        // Check record:
        if (is_array($this->indexFilterObjects[$pageId])) {
            foreach ($this->indexFilterObjects[$pageId] as $obj) {
                // TODO: What kind of filter is here used? Can't find an interface
                // in the exension
                if (!$obj->filter($table, $uid)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Generate details about translation
     *
     * @param string $table Table name
     * @param array $row Row (one from getRecordsToTranslateFromTable())
     * @param int $sysLang sys_language uid
     * @param array $flexFormDiff FlexForm diff data
     * @param int $previewLanguage previewLanguage
     * @return array Returns details array
     */
    public function translationDetails($table, $row, $sysLang, $flexFormDiff = [], $previewLanguage = 0)
    {
        // Initialize:
        $tInfo = $this->translationInfo($table, $row['uid'], $sysLang, null, '', $previewLanguage);
        $tvInstalled = ExtensionManagementUtility::isLoaded('templavoila');
        $this->detailsOutput = [];
        $this->flexFormDiff = $flexFormDiff;
        if (is_array($tInfo)) {
            // Initialize some more:
            $this->detailsOutput['translationInfo'] = $tInfo;
            $this->sysLanguages = $this->getSystemLanguages();
            $this->detailsOutput['ISOcode'] = $this->sysLanguages[$sysLang]['ISOcode'];
            // decide how translations are stored:
            // there are three ways: flexformInternalTranslation (for FCE with langChildren)
            // useOverlay (for elements with classic overlay record)
            // noTranslation
            $translationModes = $this->_detectTranslationModes($tInfo, $table, $row);
            foreach ($translationModes as $translationMode) {
                switch ($translationMode) {
                    case 'flexformInternalTranslation':
                        $this->detailsOutput['log'][] = 'Mode: flexFormTranslation with no translation set; looking for flexform fields';
                        $this->_lookForFlexFormFieldAndAddToInternalTranslationDetails($table, $row);
                        break;
                    case 'useOverlay':
                        if (count($tInfo['translations'])) {
                            $this->detailsOutput['log'][] = 'Mode: translate existing record';
                            $translationUID = $tInfo['translations'][$sysLang]['uid'];
                            $translationRecord = BackendUtility::getRecordWSOL(
                                $tInfo['translation_table'],
                                $tInfo['translations'][$sysLang]['uid']
                            );
                        } else {
                            // Will also suggest to translate a default language record which are in a container block with Inheritance or Separate mode.
                            // This might not be something people wish, but there is no way we can prevent it because its a deprecated localization paradigm
                            // to use container blocks with localization. The way out might be setting the language to "All" for such elements.
                            $this->detailsOutput['log'][] = 'Mode: translate to new record';
                            $translationUID = 'NEW/' . $sysLang . '/' . $row['uid'];
                            $translationRecord = [];
                        }
                        if ($GLOBALS['TCA'][$tInfo['translation_table']]['ctrl']['transOrigDiffSourceField']) {
                            $diffArray = unserialize(
                                $translationRecord[$GLOBALS['TCA'][$tInfo['translation_table']]['ctrl']['transOrigDiffSourceField']]
                            );
                        // debug($diffArray);
                        } else {
                            $diffArray = [];
                        }
                        $prevLangRec = [];
                        foreach ($this->previewLanguages as $prevSysUid) {
                            $prevLangInfo = $this->translationInfo(
                                $table,
                                $row['uid'],
                                $prevSysUid,
                                null,
                                '',
                                $previewLanguage
                            );
                            if (!empty($prevLangInfo) && $prevLangInfo['translations'][$prevSysUid]) {
                                $prevLangRec[$prevSysUid] = BackendUtility::getRecordWSOL(
                                    $prevLangInfo['translation_table'],
                                    $prevLangInfo['translations'][$prevSysUid]['uid']
                                );
                            } else {
                                if ($this->onlyForcedSourceLanguage) {
                                    continue;
                                }
                                // Use fallback to default language, if record does not exist in forced source language
                                $prevLangRec[$prevSysUid] = BackendUtility::getRecordWSOL(
                                    $prevLangInfo['translation_table'],
                                    $row['uid']
                                );
                            }
                        }
                        if (!empty($this->previewLanguages) && empty($prevLangRec)) {
                            // only forced source language was set, but no translated record was available from that language
                            break;
                        }
                        $allowedFields = $this->getAllowedFieldsForTable($tInfo['translation_table']);
                        foreach ($GLOBALS['TCA'][$tInfo['translation_table']]['columns'] as $field => $cfg) {
                            if (!in_array($field, $allowedFields)) {
                                continue;
                            }
                            $cfg['labelField'] = trim($GLOBALS['TCA'][$tInfo['translation_table']]['ctrl']['label']);
                            if ($GLOBALS['TCA'][$tInfo['translation_table']]['ctrl']['languageField'] !== $field
                                && $GLOBALS['TCA'][$tInfo['translation_table']]['ctrl']['transOrigPointerField'] !== $field
                                && $GLOBALS['TCA'][$tInfo['translation_table']]['ctrl']['transOrigDiffSourceField'] !== $field
                            ) {
                                $key = $tInfo['translation_table'] . ':' . BackendUtility::wsMapId(
                                    $tInfo['translation_table'],
                                    $translationUID
                                ) . ':' . $field;
                                if ($cfg['config']['type'] == 'flex') {
                                    $dataStructArray = $this->_getFlexFormMetaDataForContentElement(
                                        $table,
                                        $field,
                                        $row
                                    );
                                    if (!$tvInstalled
                                        ||
                                        $dataStructArray['meta']['langDisable']
                                        && $dataStructArray['meta']['langDatabaseOverlay'] == 1
                                    ) {
                                        // Create and call iterator object:
                                        /** @var FlexFormTools $flexObj */
                                        $flexObj = GeneralUtility::makeInstance(FlexFormTools::class);
                                        $this->_callBackParams_keyForTranslationDetails = $key;
                                        $this->_callBackParams_translationXMLArray = GeneralUtility::xml2array(
                                            $translationRecord[$field]
                                        );
                                        if (is_array($translationRecord)) {
                                            $diffsource = unserialize($translationRecord['l18n_diffsource']);
                                            $this->_callBackParams_translationDiffsourceXMLArray = GeneralUtility::xml2array(
                                                $diffsource[$field]
                                            );
                                        }
                                        foreach ($this->previewLanguages as $prevSysUid) {
                                            $this->_callBackParams_previewLanguageXMLArrays[$prevSysUid] = GeneralUtility::xml2array(
                                                $prevLangRec[$prevSysUid][$field]
                                            );
                                        }
                                        $this->_callBackParams_currentRow = $row;
                                        $flexObj->traverseFlexFormXMLData(
                                            $table,
                                            $field,
                                            $row,
                                            $this,
                                            'translationDetails_flexFormCallBackForOverlay'
                                        );
                                    }
                                    $this->detailsOutput['log'][] = 'Mode: useOverlay looking for flexform fields!';
                                } else {
                                    // handle normal fields:
                                    $diffDefaultValue = $diffArray[$field];
                                    $previewLanguageValues = [];
                                    foreach ($this->previewLanguages as $prevSysUid) {
                                        $previewLanguageValues[$prevSysUid] = $prevLangRec[$prevSysUid][$field];
                                    }
                                    // debug($row[$field]);
                                    $this->translationDetails_addField(
                                        $key,
                                        $cfg,
                                        $row[$field],
                                        $translationRecord[$field],
                                        $diffDefaultValue,
                                        $previewLanguageValues,
                                        $row
                                    );
                                }
                            }
                            // elseif ($cfg[
                        }
                        break;
                }
            } // foreach translationModes
        } else {
            $this->detailsOutput['log'][] = 'ERROR: ' . $tInfo;
        }
        return $this->detailsOutput;
    }

    /**
     * Information about translation for an element
     * Will overlay workspace version of record too!
     *
     * @param string $table Table name
     * @param int $uid Record uid
     * @param int $sys_language_uid Language uid. If zero, then all languages are selected.
     * @param array $row The record to be translated
     * @param array|string $selFieldList Select fields for the query which fetches the translations of the current record
     * @param int $previewLanguage
     * @return array|string Array with information. Errors will return string with message.
     * @todo Define visibility
     */
    public function translationInfo(
        $table,
        $uid,
        $sys_language_uid = 0,
        $row = null,
        $selFieldList = '',
        $previewLanguage = 0
    ) {
        if (!$GLOBALS['TCA'][$table] || !$uid) {
            return 'No table "' . $table . '" or no UID value';
        }

        if ($row === null) {
            $row = BackendUtility::getRecordWSOL($table, $uid);
        }
        if (!is_array($row)) {
            return 'Record "' . $table . '_' . $uid . '" was not found';
        }

        if ($row[$GLOBALS['TCA'][$table]['ctrl']['languageField']] > 0 && (int)$row[$GLOBALS['TCA'][$table]['ctrl']['languageField']] !== (int)$previewLanguage) {
            return 'Record "' . $table . '_' . $uid . '" seems to be a translation already (has a language value "'
                . $row[$GLOBALS['TCA'][$table]['ctrl']['languageField']] . '", relation to record "'
                . $row[$GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField']] . '")';
        }

        if ((int)$row[$GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField']] !== 0) {
            return 'Record "' . $table . '_' . $uid . '" seems to be a translation already (has a relation to record "'
                . $row[$GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField']] . '")';
        }

        if (!empty($selFieldList)) {
            $selectFields = GeneralUtility::trimExplode(',', $selFieldList);
        } else {
            $selectFields = [
                'uid',
                $GLOBALS['TCA'][$table]['ctrl']['languageField'],
            ];
        }

        $constraints = [];
        $constraintsA = [];
        $constraintsB = [];

        // Look for translations of this record, index by language field value:
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class));

        $constraintsA[] = $queryBuilder->expr()->eq(
            $GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'],
            $queryBuilder->createNamedParameter((int)$uid, PDO::PARAM_INT)
        );

        $constraintsA[] = $queryBuilder->expr()->eq(
            'pid',
            $queryBuilder->createNamedParameter((int)$row['pid'], PDO::PARAM_INT)
        );

        if ((int)$sys_language_uid === 0) {
            $constraintsA[] = $queryBuilder->expr()->gt(
                $GLOBALS['TCA'][$table]['ctrl']['languageField'],
                $queryBuilder->createNamedParameter(0, PDO::PARAM_INT)
            );
        } else {
            $constraintsA[] = $queryBuilder->expr()->eq(
                $GLOBALS['TCA'][$table]['ctrl']['languageField'],
                $queryBuilder->createNamedParameter((int)$sys_language_uid, PDO::PARAM_INT)
            );
        }

        if ($previewLanguage > 0) {
            $constraintsB[] = $queryBuilder->expr()->eq(
                'pid',
                $queryBuilder->createNamedParameter((int)$row['pid'], PDO::PARAM_INT)
            );
            $constraintsB[] = $queryBuilder->expr()->eq(
                'uid',
                $queryBuilder->createNamedParameter((int)$uid, PDO::PARAM_INT)
            );
            $constraintsB[] = $queryBuilder->expr()->eq(
                $GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'],
                $queryBuilder->createNamedParameter(0, PDO::PARAM_INT)
            );
            $constraintsB[] = $queryBuilder->expr()->eq(
                $GLOBALS['TCA'][$table]['ctrl']['languageField'],
                $queryBuilder->createNamedParameter((int)$previewLanguage, PDO::PARAM_INT)
            );

            $constraints[] = $queryBuilder->expr()->orX(
                $queryBuilder->expr()->andX(...$constraintsA),
                $queryBuilder->expr()->andX(...$constraintsB)
            );
        } else {
            $constraints = $constraintsA;
        }

        $translationsTemp = $queryBuilder->select(...$selectFields)
            ->from($table)
            ->where(...$constraints)
            ->execute()
            ->fetchAll();

        $translations = [];
        $translations_errors = [];
        foreach ($translationsTemp as $r) {
            if (!isset($translations[$r[$GLOBALS['TCA'][$table]['ctrl']['languageField']]])) {
                $translations[$r[$GLOBALS['TCA'][$table]['ctrl']['languageField']]] = $r;
            } else {
                $translations_errors[$r[$GLOBALS['TCA'][$table]['ctrl']['languageField']]][] = $r;
            }
        }
        return [
            'table' => $table,
            'uid' => $uid,
            'CType' => $row['CType'],
            'sys_language_uid' => $row[$GLOBALS['TCA'][$table]['ctrl']['languageField']],
            'translation_table' => $table,
            'translations' => $translations,
            'excessive_translations' => $translations_errors,
        ];
    }

    /**
     * @return array
     */
    protected function getSystemLanguages()
    {
        if (is_null(self::$systemLanguages)) {
            self::$systemLanguages = $this->t8Tools->getSystemLanguages();
        }
        return self::$systemLanguages;
    }

    /**
     * Function checks which translationMode is used. Mainly it checks the FlexForm (FCE) logic and language returns a array with useOverlay | flexformInternalTranslation
     *
     * @param array $tInfo Translation info
     * @param string $table Table name
     * @param array $row Table row
     * @return array
     */
    protected function _detectTranslationModes($tInfo, $table, $row)
    {
        $translationModes = [];
        if ($table === 'pages') {
            $translationModes[] = 'flexformInternalTranslation';
            $this->detailsOutput['log'][] = 'Mode: "flexformInternalTranslation" detected because we have page Record';
        }
        $useOverlay = false;
        if (count($tInfo['translations']) && $tInfo['sys_language_uid'] != -1) {
            $translationModes[] = 'useOverlay';
            $useOverlay = true;
            $this->detailsOutput['log'][] = 'Mode: "useOverlay" detected because we have existing overlayrecord and language is not "ALL"';
        }
        if ($row['CType'] == 'templavoila_pi1' && !$useOverlay) {
            if (($this->includeFceWithDefaultLanguage && $tInfo['sys_language_uid'] == 0) || $tInfo['sys_language_uid'] == -1) {
                $dataStructArray = $this->_getFlexFormMetaDataForContentElement($table, 'tx_templavoila_flex', $row);
                if (is_array($dataStructArray) && $dataStructArray !== false) {
                    if ($dataStructArray['meta']['langDisable']) {
                        if ($dataStructArray['meta']['langDatabaseOverlay'] == 1) {
                            $translationModes[] = 'useOverlay';
                            $this->detailsOutput['log'][] = 'Mode: "useOverlay" detected because we have FCE with langDatabaseOverlay configured';
                        } else {
                            $this->detailsOutput['log'][] = 'Mode: "noTranslation" detected because we have FCE with langDisable';
                        }
                    } elseif ($dataStructArray['meta']['langChildren']) {
                        $translationModes[] = 'flexformInternalTranslation';
                        $this->detailsOutput['log'][] = 'Mode: "flexformInternalTranslation" detected because we have FCE with langChildren';
                    } elseif ($table === 'tt_content' && $row['CType'] === 'fluidcontent_content') {
                        $translationModes[] = 'useOverlay';
                        $this->detailsOutput['log'][] = 'Mode: "useOverlay" detected because we have Fluidcontent content';
                    }
                } else {
                    $this->detailsOutput['log'][] = 'Mode: "noTranslation" detected because we have corrupt Datastructure!';
                }
            } else {
                $this->detailsOutput['log'][] = 'Mode: "noTranslation" detected because we FCE in Default Language and its not cofigured to include FCE in Default language';
            }
        } elseif ($tInfo['sys_language_uid'] == 0 && $tInfo['translation_table']) {
            //no FCE
            $translationModes[] = 'useOverlay';
            $this->detailsOutput['log'][] = 'Mode: "useOverlay" detected because we have a normal record (no FCE) in default language';
        }
        return array_unique($translationModes);
    }

    /**
     * Return meta data of flexform field, or false if no flexform is found
     *
     * @param string $table Name of the table
     * @param string $field Name of the field
     * @param array $row Current row of data
     * @return array|bool Flexform structure (or false, if not found)
     */
    protected function _getFlexFormMetaDataForContentElement($table, $field, $row)
    {
        $conf = $GLOBALS['TCA'][$table]['columns'][$field];
        $dataStructArray = [];
        $dataStructIdentifier = GeneralUtility::makeInstance(FlexFormTools::class)->getDataStructureIdentifier(
            $conf,
            $table,
            $field,
            $row
        );
        if (!empty($dataStructIdentifier)) {
            $dataStructArray = GeneralUtility::makeInstance(FlexFormTools::class)->parseDataStructureByIdentifier(
                $dataStructIdentifier
            );
        }
        if (!empty($dataStructArray)) {
            return $dataStructArray;
        }
        return false;
    }

    /**
     * Look for flexform field and add to internal translation details
     *
     * @param string $table Table name
     * @param array $row Table row
     */
    protected function _lookForFlexFormFieldAndAddToInternalTranslationDetails($table, $row)
    {
        foreach ($GLOBALS['TCA'][$table]['columns'] as $field => $conf) {
            // For "flex" fieldtypes we need to traverse the structure looking for file and db references of course!
            if ($conf['config']['type'] == 'flex') {
                // We might like to add the filter that detects if record is tt_content/CType is "tx_flex...:"
                // since otherwise we would translate flexform content that might be hidden if say the record had a DS
                // set but was later changed back to "Text w/Image" or so... But probably this is a rare case.
                // Get current data structure to see if translation is needed:
                $dataStructArray = [];
                $dataStructIdentifier = GeneralUtility::makeInstance(FlexFormTools::class)->getDataStructureIdentifier(
                    $conf,
                    $table,
                    $field,
                    $row
                );
                if (!empty($dataStructIdentifier)) {
                    $dataStructArray = GeneralUtility::makeInstance(
                        FlexFormTools::class
                    )->parseDataStructureByIdentifier($dataStructIdentifier);
                }
                $this->detailsOutput['log'][] = 'FlexForm field "' . $field . '": DataStructure status: ' . (!empty($dataStructArray) ? 'OK' : 'Error: ' . $dataStructArray);
                if (!empty($dataStructArray) && !$dataStructArray['meta']['langDisable']) {
                    $this->detailsOutput['log'][] = 'FlexForm Localization enabled, type: ' . ($dataStructArray['meta']['langChildren'] ? 'Inheritance: Continue' : 'Separate: Stop');
                    if ($dataStructArray['meta']['langChildren']) {
                        $currentValueArray = GeneralUtility::xml2array($row[$field]);
                        // Traversing the XML structure, processing files:
                        if (is_array($currentValueArray)) {
                            // Create and call iterator object:
                            /** @var FlexFormTools $flexObj */
                            $flexObj = GeneralUtility::makeInstance(FlexFormTools::class);
                            $flexObj->traverseFlexFormXMLData(
                                $table,
                                $field,
                                $row,
                                $this,
                                'translationDetails_flexFormCallBack'
                            );
                        }
                    }
                } else {
                    $this->detailsOutput['log'][] = 'FlexForm Localization disabled. Nothing to do.';
                }
            }
        }
    }

    /**
     * Creates the record to insert in the index table.
     *
     * @param array $fullDetails Details as fetched (as gotten by ->translationDetails())
     * @param int $sys_lang The language UID for which this record is made
     * @param int $pid PID of record
     * @return array Record.
     */
    protected function compileIndexRecord($fullDetails, $sys_lang, $pid)
    {
        $record = [
            'hash' => '',
            'tablename' => $fullDetails['translationInfo']['table'],
            'recuid' => (int)$fullDetails['translationInfo']['uid'],
            'recpid' => $pid,
            'sys_language_uid' => (int)$fullDetails['translationInfo']['sys_language_uid'],
            // can be zero (default) or -1 (international)
            'translation_lang' => $sys_lang,
            'translation_recuid' => (int)$fullDetails['translationInfo']['translations'][$sys_lang]['uid'],
            'workspace' => $this->getBackendUser()->workspace,
            'serializedDiff' => [],
            'flag_new' => 0,
            // Something awaits to get translated => Put to TODO list as a new element
            'flag_unknown' => 0,
            // Status of this is unknown, probably because it has been "localized" but not yet translated from the default language => Put to TODO LIST as a priority
            'flag_noChange' => 0,
            // If only "noChange" is set for the record, all is well!
            'flag_update' => 0,
            // This indicates something to update
        ];
        if (is_array($fullDetails['fields'])) {
            foreach ($fullDetails['fields'] as $key => $tData) {
                if (is_array($tData)) {
                    list(, $uidString, $fieldName, $extension) = explode(':', $key);
                    list($uidValue) = explode('/', $uidString);
                    $noChangeFlag = !strcmp(trim($tData['diffDefaultValue']), trim($tData['defaultValue']));
                    if ($uidValue === 'NEW') {
                        $record['serializedDiff'][$fieldName . ':' . $extension] .= '';
                        $record['flag_new']++;
                    } elseif (!isset($tData['diffDefaultValue'])) {
                        $record['serializedDiff'][$fieldName . ':' . $extension] .= '<em>No diff available</em>';
                        $record['flag_unknown']++;
                    } elseif ($noChangeFlag) {
                        $record['serializedDiff'][$fieldName . ':' . $extension] .= '';
                        $record['flag_noChange']++;
                    } else {
                        $record['serializedDiff'][$fieldName . ':' . $extension] .= $this->diffCMP(
                            $tData['diffDefaultValue'],
                            $tData['defaultValue']
                        );
                        $record['flag_update']++;
                    }
                }
            }
        }
        $record['serializedDiff'] = serialize($record['serializedDiff']);
        $record['hash'] = md5(
            $record['tablename'] . ':' . $record['recuid'] . ':' . $record['translation_lang'] . ':' . $record['workspace']
        );
        return $record;
    }

    /**
     * Diff-compare markup
     *
     * @param string $old Old content
     * @param string $new New content
     * @return string Marked up string.
     */
    protected function diffCMP($old, $new)
    {
        // Create diff-result:
        /** @var DiffUtility $t3lib_diff_Obj */
        $t3lib_diff_Obj = GeneralUtility::makeInstance(DiffUtility::class);
        return $t3lib_diff_Obj->makeDiffDisplay($old, $new);
    }

    /**
     * Selecting records from a table from a page which are candidates to be translated.
     *
     * @param string $table Table name
     * @param int $pageId Page id
     * @param int $previewLanguage
     * @param bool $sortexports
     * @param bool $noHidden
     * @return array Array of records from table (with all fields selected)
     */
    public function getRecordsToTranslateFromTable(
        $table,
        $pageId,
        $previewLanguage = 0,
        $sortexports = false,
        $noHidden = false
    ) {
        if (!$this->canUserEditRecord('pages', BackendUtility::getRecord('pages', $pageId))) {
            return [];
        }

        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class));

        // Check for disabled field settings
        // print "###".$this->getBackendUser()->uc['moduleData']['xMOD_tx_l10nmgr_cm1']['noHidden']."---";
        if (!empty($this->getBackendUser()->uc['moduleData']['LocalizationManager']['noHidden'])) {
            $noHidden = true;
        }
        if ($noHidden) {
            $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(HiddenRestriction::class));
        }

        $queryBuilder->select('*')
            ->from($table);

        if ($previewLanguage > 0) {
            $constraints = [];
            $constraints[] = $queryBuilder->expr()->eq(
                $GLOBALS['TCA'][$table]['ctrl']['languageField'],
                $queryBuilder->createNamedParameter($previewLanguage, PDO::PARAM_INT)
            );

            if (isset($GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'])) {
                $constraints[] = $queryBuilder->expr()->eq(
                    $GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'],
                    $queryBuilder->createNamedParameter(0, PDO::PARAM_INT)
                );
            }

            $queryBuilder->where(
                $queryBuilder->expr()->andX(
                    $queryBuilder->expr()->eq(
                        'pid',
                        $queryBuilder->createNamedParameter((int)$pageId, PDO::PARAM_INT)
                    ),
                    $queryBuilder->expr()->orX(
                        $queryBuilder->expr()->lte(
                            $GLOBALS['TCA'][$table]['ctrl']['languageField'],
                            $queryBuilder->createNamedParameter(0, PDO::PARAM_INT)
                        ),
                        $queryBuilder->expr()->andX(...$constraints)
                    )
                )
            );
        } else {
            $queryBuilder->where(
                $queryBuilder->expr()->andX(
                    $queryBuilder->expr()->eq(
                        'pid',
                        $queryBuilder->createNamedParameter((int)$pageId, PDO::PARAM_INT)
                    ),
                    $queryBuilder->expr()->lte(
                        $GLOBALS['TCA'][$table]['ctrl']['languageField'],
                        $queryBuilder->createNamedParameter(0, PDO::PARAM_INT)
                    )
                )
            );
        }

        if ($sortexports) {
            $sortBy = '';
            if (isset($GLOBALS['TCA'][$table]['ctrl']['sortby'])) {
                $sortBy = $GLOBALS['TCA'][$table]['ctrl']['sortby'];
            } else {
                if (isset($GLOBALS['TCA'][$table]['ctrl']['default_sortby'])) {
                    $sortBy = $GLOBALS['TCA'][$table]['ctrl']['default_sortby'];
                }
            }
            $TSconfig = BackendUtility::getPagesTSconfig($pageId);
            if (isset($TSconfig['tx_l10nmgr']) && isset($TSconfig['tx_l10nmgr']['sortexports']) && isset($TSconfig['tx_l10nmgr']['sortexports'][$table])) {
                $sortBy = $TSconfig['tx_l10nmgr']['sortexports'][$table];
            }
            if ($sortBy) {
                foreach (QueryHelper::parseOrderBy((string)$sortBy) as $orderPair) {
                    [$fieldName, $order] = $orderPair;
                    $queryBuilder->addOrderBy($fieldName, $order);
                }
            }
        }

        $resource = $queryBuilder->execute();
        $results = [];
        while (($data = $resource->fetch(PDO::FETCH_ASSOC))) {
            if ($this->canUserEditRecord($table, $data)) {
                $results[] = $data;
            }
        }
        $resource->closeCursor();

        return $results;
    }

    /**
     * Update translation index table based on a "details" record (made by indexDetailsRecord())
     *
     * @param array $rDetails See output of indexDetailsRecord()
     * @param bool $echo If true, will output log information for each insert
     */
    public function updateIndexTableFromDetailsArray($rDetails, $echo = false)
    {
        if ($rDetails && is_array($rDetails['indexRecord']) && count($rDetails['indexRecord'])) {
            foreach ($rDetails['indexRecord'] as $rIndexRecord) {
                if (
                    empty($rIndexRecord['hash'])
                    || empty($rIndexRecord['tablename'])
                ) {
                    continue;
                }
                if ($echo) {
                    echo 'Inserting ' . $rIndexRecord['tablename'] . ':' . $rIndexRecord['recuid']
                        . ':' . $rIndexRecord['translation_lang'] . ':' . $rIndexRecord['workspace'] . chr(10);
                }
                $this->updateIndexTable($rIndexRecord);
            }
        }
    }

    /**
     * Updates translation index table with input record
     *
     * @param array $record Array (generated with ->compileIndexRecord())
     */
    protected function updateIndexTable($record)
    {
        /** @var Connection $databaseConnection */
        $databaseConnection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_l10nmgr_index');

        $databaseConnection->delete(
            'tx_l10nmgr_index',
            ['hash' => $record['hash']]
        );

        $databaseConnection->insert('tx_l10nmgr_index', $record);
    }

    /**
     * Flush Index Of Workspace - removes all index records for workspace - useful to nightly build-up of the index.
     *
     * @param int $ws Workspace ID
     */
    public function flushIndexOfWorkspace($ws)
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(
            'tx_l10nmgr_index'
        );
        $queryBuilder->delete('tx_l10nmgr_index')
            ->where(
                $queryBuilder->expr()->eq(
                    'workspace',
                    $queryBuilder->createNamedParameter((int)$ws, PDO::PARAM_INT)
                )
            )
            ->execute();
    }

    /**
     * @param string $table Table name
     * @param int $uid UID
     * @param bool $exec Execution flag
     * @return array
     */
    public function flushTranslations($table, $uid, $exec = false)
    {
        /** @var FlexFormTools $flexToolObj */
        $flexToolObj = GeneralUtility::makeInstance(FlexFormTools::class);
        $TCEmain_data = [];
        $TCEmain_cmd = [];
        // Simply collecting information about indexing on a page to assess what has to be flushed. Maybe this should move to be an API in
        if ($table == 'pages') {
            $items = $this->indexDetailsPage($uid);
        } else {
            $items = [];
            if ($tmp = $this->indexDetailsRecord($table, $uid)) {
                $items[$table][$uid] = $tmp;
            }
        }
        $remove = [];
        if (count($items)) {
            foreach ($items as $tt => $rr) {
                foreach ($rr as $rUid => $rDetails) {
                    if (is_array($rDetails['fullDetails'])) {
                        foreach ($rDetails['fullDetails'] as $infoRec) {
                            $tInfo = $infoRec['translationInfo'];
                            if (is_array($tInfo)) {
                                $flexFormTranslation = $tInfo['sys_language_uid'] == -1 && !count(
                                    $tInfo['translations']
                                );
                                // Flexforms:
                                if ($flexFormTranslation || $table === 'pages') {
                                    if (is_array($infoRec['fields'])) {
                                        foreach ($infoRec['fields'] as $theKey => $theVal) {
                                            $pp = explode(':', $theKey);
                                            if ($pp[3] && $pp[0] === $tt && (int)$pp[1] === (int)$rUid) {
                                                $remove['resetFlexFormFields'][$tt][$rUid][$pp[2]][] = $pp[3];

                                                if (!is_array($TCEmain_data[$tt][$rUid][$pp[2]])) {
                                                    $TCEmain_data[$tt][$rUid][$pp[2]] = [];
                                                }
                                                $flexToolObj->setArrayValueByPath(
                                                    $pp[3],
                                                    $TCEmain_data[$tt][$rUid][$pp[2]],
                                                    ''
                                                );
                                            }
                                        }
                                    }
                                }
                                // Looking for translations of element in terms of records. Those should be deleted then.
                                if (!$flexFormTranslation && is_array($tInfo['translations'])) {
                                    foreach ($tInfo['translations'] as $translationChildToRemove) {
                                        $remove['deleteRecords'][$tInfo['translation_table']][$translationChildToRemove['uid']] = $translationChildToRemove;
                                        $TCEmain_cmd[$tInfo['translation_table']][$translationChildToRemove['uid']]['delete'] = 1;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        $errorLog = '';
        if ($exec) {
            // Now, submitting translation data:
            /** @var DataHandler $tce */
            $tce = GeneralUtility::makeInstance(DataHandler::class);
            $tce->dontProcessTransformations = true;
            $tce->isImporting = true;
            $tce->start(
                $TCEmain_data,
                $TCEmain_cmd
            ); // check has been done previously that there is a backend user which is Admin and also in live workspace
            $tce->process_datamap();
            $tce->process_cmdmap();
            $errorLog = $tce->errorLog;
        }
        return [$remove, $TCEmain_cmd, $TCEmain_data, $errorLog];
    }

    private function getParentTables(string $table, array $row): array
    {
        $isInlineTable = (is_array($inlineTablesConfig = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['inlineTablesConfig']) && array_key_exists(
            $table,
            $inlineTablesConfig
        ));

        if ($isInlineTable) {
            // Parent fields:
            return ['tt_content', $inlineTablesConfig[$table]['parentField']];
        }

        if ($table === 'sys_file_reference') {
            return [$row['tablenames'], 'uid_foreign'];
        }

        return [null, null];
    }

    public function isParentItemHidden(string $table, array $row, int $sysLang): bool
    {
        [$parentTable, $parentField] = $this->getParentTables($table, $row);

        if (!empty($parentTable) && !empty($parentField)) {
            if ($parentTable === 'pages') {
                return false;
            }

            $parent = BackendUtility::getRecordWSOL($parentTable, (int)$row[$parentField]);

            if ($parent['hidden']) {
                return true;
            }

            // Recursive call for nested inline elements and sys_file_references
            return $this->isParentItemHidden($parentTable, $parent, $sysLang);
        }

        return false;
    }

    public function isParentItemExcluded(string $table, array $row, int $sysLang): bool
    {
        [$parentTable, $parentField] = $this->getParentTables($table, $row);

        if (!empty($parentTable) && !empty($parentField)) {
            $parent = BackendUtility::getRecordWSOL($parentTable, (int)$row[$parentField]);
            if (!empty($parent[Constants::L10NMGR_LANGUAGE_RESTRICTION_FIELDNAME])) {
                /** @var LanguageRestrictionCollection $languageIsRestricted */
                $languageIsRestricted = LanguageRestrictionCollection::load(
                    $sysLang,
                    true,
                    $parentTable,
                    Constants::L10NMGR_LANGUAGE_RESTRICTION_FIELDNAME
                );
                if ($languageIsRestricted->hasItem((int)$parent['uid'])) {
                    return true;
                }
            }

            // Recursive call for nested inline elements and sys_file_references
            return $this->isParentItemExcluded($parentTable, $parent, $sysLang);
        }

        return false;
    }
}
