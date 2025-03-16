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

use Localizationteam\L10nmgr\Model\Tools\XmlTools;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Returns initialised TranslationData Objects
 * This is used to get TranslationData out of the import files for example
 *
 * @author Kasper Skaarhoj <kasperYYYY@typo3.com>
 * @author Daniel Pötzinger <ext@aoemedia.de>
 */
class TranslationDataFactory implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var string List of error messages
     */
    protected string $errorMsg;

    /**
     * public Factory method to get initialised tranlationData Object from the passed XMLNodes Array
     * see tx_l10nmgr_CATXMLImportManager
     *
     * @param array $xmlNodes Array with XMLNodes from the CATXML
     *
     * @return TranslationData Object with data
     **/
    public function getTranslationDataFromCATXMLNodes(array $xmlNodes): TranslationData
    {
        $data = $this->getParsedCATXMLFromXMLNodes($xmlNodes);
        /** @var TranslationData $translationData */
        $translationData = GeneralUtility::makeInstance(TranslationData::class);
        $translationData->setTranslationData($data);
        return $translationData;
    }

    /**
     * Parses XML String and returns translationData
     *
     * @param array $xmlNodes Array with XMLNodes
     *
     * @return array with translated information
     **/
    protected function getParsedCATXMLFromXMLNodes(array $xmlNodes): array
    {
        /** @var XmlTools $xmlTool */
        $xmlTool = GeneralUtility::makeInstance(XmlTools::class);
        $translation = [];
        if (!empty($xmlNodes['TYPO3L10N'][0]['ch']['pageGrp'])) {
            foreach ($xmlNodes['TYPO3L10N'][0]['ch']['pageGrp'] as $pageGrp) {
                if (!empty($pageGrp['ch']['data'])) {
                    foreach ($pageGrp['ch']['data'] as $row) {
                        $attrs = $row['attrs'] ?? [];
                        if (($attrs['transformations'] ?? null) == '1') {
                            $translationValue = $xmlTool->XML2RTE($row['XMLvalue'] ?? '');
                            $translation[$attrs['table']][$attrs['elementUid']][$attrs['key']] = $translationValue;
                        } else {
                            $row['XMLvalue'] = $row['XMLvalue'] ?? '';
                            $row['values'][0] = $row['values'][0] ?? '';
                            $row['values'][0] = preg_replace(
                                '/&(?!(amp|nbsp|quot|apos|lt|gt);)/',
                                '&amp;',
                                $row['values'][0]
                            );
                            $row['values'][0] = preg_replace('/\xc2\xa0/', '&nbsp;', $row['values'][0]);
                            $this->logger->debug(__FILE__ . ': ' . __LINE__ . ': V0: ' . $row['values'][0]);
                            $this->logger->debug(__FILE__ . ': ' . __LINE__ . ': XML: ' . $row['XMLvalue']);
                            $pattern = $row['values'][0];
                            $this->logger->debug(__FILE__ . ': ' . __LINE__ . ': Pattern: ' . $pattern);
                            $pattern2 = '/' . preg_replace('/\//i', '\/', preg_quote($pattern)) . '/';
                            $pattern = '/^' . preg_replace('/\//i', '\/', preg_quote($pattern)) . '/';
                            $this->logger->debug(__FILE__ . ': ' . __LINE__ . ': Pattern: ' . $pattern);
                            $this->logger->debug(__FILE__ . ': ' . __LINE__ . ': Pattern 2: ' . $pattern2);
                            if (preg_match($pattern, $row['XMLvalue'], $match)) {
                                $this->logger->debug(__FILE__ . ': ' . __LINE__ . ': Start row[values][0] eq start row[XMLvalue]!!!' . LF . 'XMLvalue: ' . $row['XMLvalue']);
                            } elseif ((preg_match('/<[^>]+>/i', $row['XMLvalue']))
                                && (!preg_match($pattern2, $row['XMLvalue'], $match))
                            ) {
                                $this->logger->debug(__FILE__ . ': ' . __LINE__ . ': TAG found in: ' . $row['XMLvalue']);
                                $this->logger->debug(__FILE__ . ': ' . __LINE__ . ': TAG found: ' . $row['values'][0]);
                            } else {
                                $this->logger->debug(__FILE__ . ': ' . __LINE__ . ': No TAG found in: ' . $row['XMLvalue']);
                            }
                            if (!empty($attrs['table']) && !empty($attrs['elementUid']) && !empty($attrs['key'])) {
                                $translation[$attrs['table']][$attrs['elementUid']][$attrs['key']] = $row['XMLvalue'];
                                $this->logger->debug(__FILE__ . ': ' . __LINE__ . ': IMPORT: ' . $translation[$attrs['table']][$attrs['elementUid']][$attrs['key']]);
                            }
                        }
                        if (!empty($translation[$attrs['table']][$attrs['elementUid']][$attrs['key']])) {
                            $translation[$attrs['table']][$attrs['elementUid']][$attrs['key']] = htmlspecialchars_decode($translation[$attrs['table']][$attrs['elementUid']][$attrs['key']]);
                        }
                    }
                }
            }
        }
        return $translation;
    }

    /**
     * public Factory method to get initialized translationData Object from the passed XML
     *
     * @param string $xmlFile Path to the XML file
     *
     * @return TranslationData Object with data
     **/
    public function getTranslationDataFromExcelXMLFile(string $xmlFile): TranslationData
    {
        $fileContent = GeneralUtility::getUrl($xmlFile);
        $data = $this->getParsedExcelXML($fileContent);
        if ($data === false) {
            die($this->errorMsg);
        }
        /** @var TranslationData $translationData */
        $translationData = GeneralUtility::makeInstance(TranslationData::class);
        $translationData->setTranslationData($data);
        return $translationData;
    }

    /**
     * Private internal function to parse the excel import XML format.
     * TODO: possibly make separate class for this.
     *
     * @param string $fileContent String with XML
     *
     * @return array|false with translated information
     **/
    protected function getParsedExcelXML(string $fileContent): mixed
    {
        // Parse XML in a rude fashion:
        // Check if &nbsp; has to be substituted -> DOCTYPE -> entity?
        $xmlNodes = XmlTools::xml2tree(
            str_replace('&nbsp;', '&#160;', $fileContent)
        ); // For some reason PHP chokes on incoming &nbsp; in XML!
        $translation = [];
        if (!is_array($xmlNodes)) {
            $this->errorMsg .= $xmlNodes;
            return false;
        }
        $worksheetIdentifier = '';
        // At least OpenOfficeOrg Calc changes the worksheet identifier. For now we better check for this, otherwise we cannot import translations edited with OpenOfficeOrg Calc.
        if (isset($xmlNodes['Workbook'][0]['ch']['Worksheet'])) {
            $worksheetIdentifier = 'Worksheet';
        }
        if (isset($xmlNodes['Workbook'][0]['ch']['ss:Worksheet'])) {
            $worksheetIdentifier = 'ss:Worksheet';
        }
        // OK, this method of parsing the XML really sucks, but it was 4:04 in the night and ... I have no clue to make it better on PHP4. Anyway, this will work for now. But is probably unstable in case a user puts formatting in the content of the translation! (since only the first CData chunk will be found!)
        if (!empty($xmlNodes['Workbook'][0]['ch'][$worksheetIdentifier][0]['ch']['Table'][0]['ch']['Row'])) {
            foreach ($xmlNodes['Workbook'][0]['ch'][$worksheetIdentifier][0]['ch']['Table'][0]['ch']['Row'] as $row) {
                if (!isset($row['ch']['Cell'][0]['attrs']['ss:Index'])) {
                    [$Ttable, $Tuid, $Tkey] = explode(
                        '][',
                        substr(trim($row['ch']['Cell'][0]['ch']['Data'][0]['values'][0]), 12, -1)
                    );
                    // Ensure that data (in ss:Data cells) like formatted cells is taken properly from that cell
                    $translatedData = null;
                    if (isset($row['ch']['Cell'][4]['ch']['ss:Data']) && $row['ch']['Cell'][4]['ch']['ss:Data'][0]['values'] && $row['ch']['Cell'][4]['ch']['ss:Data'][0]['values'][0]) {
                        $translatedData = $row['ch']['Cell'][4]['ch']['ss:Data'][0]['values'][0];
                    } else {
                        $translatedData = $row['ch']['Cell'][4]['ch']['Data'][0]['values'][0];
                    }
                    $translation[$Ttable][$Tuid][$Tkey] = (string)$translatedData;
                }
            }
        }
        return $translation;
    }
}
