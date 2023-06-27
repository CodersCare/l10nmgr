<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace Localizationteam\L10nmgr\Model\Tools;

use TYPO3\CMS\Core\Configuration\FlexForm\Exception\InvalidIdentifierException;
use TYPO3\CMS\Core\Configuration\FlexForm\Exception\InvalidParentRowException;
use TYPO3\CMS\Core\Configuration\FlexForm\Exception\InvalidParentRowLoopException;
use TYPO3\CMS\Core\Configuration\FlexForm\Exception\InvalidParentRowRootException;
use TYPO3\CMS\Core\Configuration\FlexForm\Exception\InvalidPointerFieldValueException;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Contains functions for manipulating flex form data
 */
class FlexFormTools extends \TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools
{
    /**
     * Contains data structure when traversing flexform
     *
     * @var array
     */
    public $traverseFlexFormXMLData_DS = [];

    /**
     * Contains data array when traversing flexform
     *
     * @var array
     */
    public $traverseFlexFormXMLData_Data = [];

    /**
     * Handler for Flex Forms
     *
     * @param string $table The table name of the record
     * @param string $field The field name of the flexform field to work on
     * @param array $row The record data array
     * @param object $callBackObj Object in which the call back function is located
     * @param string $callBackMethod_value Method name of call back function in object for values
     * @return bool|string true on success, string if error happened (error string returned)
     */
    public function traverseFlexFormXMLData($table, $field, $row, $callBackObj, $callBackMethod_value)
    {
        $PA = [];
        if (!is_array($GLOBALS['TCA'][$table]) || !is_array($GLOBALS['TCA'][$table]['columns'][$field])) {
            return 'TCA table/field was not defined.';
        }
        $this->callBackObj = $callBackObj;

        // Get data structure. The methods may throw various exceptions, with some of them being
        // ok in certain scenarios, for instance on new record rows. Those are ok to "eat" here
        // and substitute with a dummy DS.
        $dataStructureArray = ['sheets' => ['sDEF' => []]];
        try {
            $dataStructureIdentifier = $this->getDataStructureIdentifier($GLOBALS['TCA'][$table]['columns'][$field], $table, $field, $row);
            $dataStructureArray = $this->parseDataStructureByIdentifier($dataStructureIdentifier);
        } catch (InvalidParentRowException|InvalidParentRowLoopException|InvalidParentRowRootException|InvalidPointerFieldValueException|InvalidIdentifierException $e) {
        }

        // Get flexform XML data
        $editData = GeneralUtility::xml2array($row[$field]);

        if (!is_array($editData)) {
            return 'Parsing error: ' . $editData;
        }
        // Check if $dataStructureArray['sheets'] is indeed an array before loop or it will crash with runtime error
        if (!is_array($dataStructureArray['sheets'])) {
            return 'Data Structure ERROR: sheets is defined but not an array for table ' . $table . (isset($row['uid']) ? ' and uid ' . $row['uid'] : '');
        }
        // Traverse languages:
        foreach ($dataStructureArray['sheets'] as $sheetKey => $sheetData) {
            // Render sheet:
            if (is_array($sheetData['ROOT']) && is_array($sheetData['ROOT']['el'])) {
                $PA['vKeys'] = ['DEF'];
                $PA['lKey'] = 'lDEF';
                $PA['callBackMethod_value'] = $callBackMethod_value;
                $PA['table'] = $table;
                $PA['field'] = $field;
                $PA['uid'] = $row['uid'];
                $this->traverseFlexFormXMLData_DS = &$sheetData;
                $this->traverseFlexFormXMLData_Data = &$editData;
                // Render flexform:
                $this->traverseFlexFormXMLData_recurse($sheetData['ROOT']['el'], $editData['data'][$sheetKey]['lDEF'] ?? [], $PA, 'data/' . $sheetKey . '/lDEF');
            } else {
                return 'Data Structure ERROR: No ROOT element found for sheet "' . $sheetKey . '".';
            }
        }
        return true;
    }

    /**
     * Recursively traversing flexform data according to data structure and element data
     *
     * @param array $dataStruct (Part of) data structure array that applies to the sub section of the flexform data we are processing
     * @param array $editData (Part of) edit data array, reflecting current part of data structure
     * @param array $PA Additional parameters passed.
     * @param string $path Telling the "path" to the element in the flexform XML
     */
    public function traverseFlexFormXMLData_recurse($dataStruct, $editData, &$PA, $path = ''): void
    {
        if (is_array($dataStruct)) {
            foreach ($dataStruct as $key => $value) {
                if (isset($value['type']) && $value['type'] === 'array') {
                    // Array (Section) traversal
                    if ($value['section'] ?? false) {
                        if (isset($editData[$key]['el']) && is_array($editData[$key]['el'])) {
                            if ($this->reNumberIndexesOfSectionData) {
                                $temp = [];
                                $c3 = 0;
                                foreach ($editData[$key]['el'] as $v3) {
                                    $temp[++$c3] = $v3;
                                }
                                $editData[$key]['el'] = $temp;
                            }
                            foreach ($editData[$key]['el'] as $k3 => $v3) {
                                if (is_array($v3)) {
                                    $cc = $k3;
                                    $theType = key($v3);
                                    $theDat = $v3[$theType];
                                    $newSectionEl = $value['el'][$theType];
                                    if (is_array($newSectionEl)) {
                                        $this->traverseFlexFormXMLData_recurse([$theType => $newSectionEl], [$theType => $theDat], $PA, $path . '/' . $key . '/el/' . $cc);
                                    }
                                }
                            }
                        }
                    } else {
                        // Array traversal
                        if (isset($editData[$key]['el'])) {
                            $this->traverseFlexFormXMLData_recurse($value['el'], $editData[$key]['el'], $PA, $path . '/' . $key . '/el');
                        }
                    }
                } elseif (
                    (isset($value['TCEforms']['config']) && is_array($value['TCEforms']['config'])) ||
                    (isset($value['config']) && is_array($value['config']))
                ) {
                    // Processing a field value:
                    foreach ($PA['vKeys'] as $vKey) {
                        $vKey = 'v' . $vKey;
                        // Call back
                        if (!empty($PA['callBackMethod_value']) && isset($editData[$key][$vKey])) {
                            $this->executeCallBackMethod($PA['callBackMethod_value'], [
                                    $value,
                                    $editData[$key][$vKey],
                                    $PA,
                                    $path . '/' . $key . '/' . $vKey,
                                    $this,
                            ]);
                        }
                    }
                }
            }
        }
    }
}
