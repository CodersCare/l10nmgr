<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\View;

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

use Localizationteam\L10nmgr\Model\L10nConfiguration;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * excelXML: Renders the excel XML
 *
 * @author Kasper Skaarhoj <kasperYYYY@typo3.com>
 */
class ExcelXmlView extends AbstractExportView implements ExportViewInterface
{
    //internal flags:
    /**
     * @var bool
     */
    protected bool $modeOnlyChanged = false;

    /**
     * @var int
     */
    protected int $exportType = 0;

    /**
     * @var int $forcedSourceLanguage Overwrite the default language uid with the desired language to export
     */
    protected int $forcedSourceLanguage = 0;

    /**
     * ExcelXmlView constructor.
     * @param L10nConfiguration $l10ncfgObj
     * @param int $sysLang
     */
    public function __construct(L10nConfiguration $l10ncfgObj, int $sysLang)
    {
        parent::__construct($l10ncfgObj, $sysLang);
    }

    /**
     * Render the excel XML export
     *
     * @return string HTML content
     */
    public function render(): string
    {
        $sysLang = $this->sysLang;
        $accumObj = $this->l10ncfgObj->getL10nAccumulatedInformationsObjectForLanguage($sysLang);
        if ($this->forcedSourceLanguage) {
            $accumObj->setForcedPreviewLanguage($this->forcedSourceLanguage);
        }
        $accum = $accumObj->getInfoArray($this->modeNoHidden);
        $output = [];
        $sourceColState = '';
        $altSourceColState = '';
        // Traverse the structure and generate HTML output:
        foreach ($accum as $pId => $page) {
            if (empty($page['items'])) {
                continue;
            }
            $output[] = '
	<!-- Page header -->
	<Row>
	<Cell ss:Index="2" ss:StyleID="s35"><Data ss:Type="String">' . htmlspecialchars(($page['header']['title'] ?? '') . ' [' . $pId . ']') . '</Data></Cell>
	<Cell ss:StyleID="s35"></Cell>
	<Cell ss:StyleID="s35"></Cell>
	<Cell ss:StyleID="s35"></Cell>
	' . (!empty($page['header']['prevLang']) ? '<Cell ss:StyleID="s35"></Cell>' : '') . '
	</Row>';
            $output[] = '
	<!-- Field list header -->
	<Row>
	<Cell ss:Index="2" ss:StyleID="s38"><Data ss:Type="String">Fieldname:</Data></Cell>
	<Cell ss:StyleID="s38"><Data ss:Type="String">Source language:</Data></Cell>
	<Cell ss:StyleID="s38"><Data ss:Type="String">Alternative source language:</Data></Cell>
	<Cell ss:StyleID="s38"><Data ss:Type="String">Translation:</Data></Cell>
	<Cell ss:StyleID="s38"><Data ss:Type="String">Difference since last tr.:</Data></Cell>
	</Row>';
            foreach ($page['items'] as $table => $elements) {
                foreach ($elements as $elementUid => $data) {
                    if (!empty($data['fields']) && is_array($data['fields'])) {
                        $fieldsForRecord = [];
                        foreach ($data['fields'] as $key => $tData) {
                            $sourceColState = '';
                            $altSourceColState = '';
                            if (is_array($tData)) {
                                list(, $uidString, $fieldName) = explode(':', $key);
                                list($uidValue) = explode('/', $uidString);
                                //DZ
                                if (($this->forcedSourceLanguage && isset($tData['previewLanguageValues'][$this->forcedSourceLanguage])) || !$this->forcedSourceLanguage) {
                                    //DZ
                                    if ($this->forcedSourceLanguage) {
                                        $sourceColState = 'ss:Hidden="1" ss:AutoFitWidth="0"';
                                        $altSourceColState = 'ss:AutoFitWidth="0" ss:Width="233.0"';
                                    } else {
                                        $sourceColState = 'ss:AutoFitWidth="0" ss:Width="233.0"';
                                        $altSourceColState = 'ss:Hidden="1" ss:AutoFitWidth="0"';
                                    }
                                    $noChangeFlag = !strcmp(
                                        trim($tData['diffDefaultValue'] ?? ''),
                                        trim($tData['defaultValue'] ?? '')
                                    );
                                    if ($uidValue === 'NEW') {
                                        $diff = htmlspecialchars('[New value]');
                                    } elseif (empty($tData['diffDefaultValue'])) {
                                        $diff = htmlspecialchars('[No diff available]');
                                    } elseif ($noChangeFlag) {
                                        $diff = htmlspecialchars('[No change]');
                                    } else {
                                        $diff = html_entity_decode($this->diffCMP($tData['diffDefaultValue'], $tData['defaultValue'] ?? ''));
                                        $diff = str_replace(
                                            '<del>',
                                            '<Font ss:Color="#FF0000" xmlns="http://www.w3.org/TR/REC-html40">',
                                            $diff
                                        );
                                        $diff = str_replace(
                                            '<ins>',
                                            '<Font ss:Color="#00FF00" xmlns="http://www.w3.org/TR/REC-html40">',
                                            $diff
                                        );
                                        $diff = str_replace(['</del>', '</ins>'], ['</Font>', '</Font>'], $diff);
                                    }
                                    $diff .= (!empty($tData['msg']) ? '[NOTE: ' . htmlspecialchars((string)$tData['msg']) . ']' : '');
                                    if (!$this->modeOnlyChanged || !$noChangeFlag) {
                                        if (!empty($tData['previewLanguageValues']) && is_array($tData['previewLanguageValues'])) {
                                            reset($tData['previewLanguageValues']);
                                        }
                                        $fieldsForRecord[] = '
	<!-- Translation row: -->
	<Row ss:StyleID="s25">
	<Cell><Data ss:Type="String">' . htmlspecialchars('translation[' . $table . '][' . $elementUid . '][' . $key . ']') . '</Data></Cell>
	<Cell ss:StyleID="s26"><Data ss:Type="String">' . htmlspecialchars((string)$fieldName) . '</Data></Cell>
	<Cell ss:StyleID="s27"><Data ss:Type="String">' . str_replace(
                                            chr(10),
                                            '&#10;',
                                            htmlspecialchars((string)($tData['defaultValue'] ?? ''))
                                        ) . '</Data></Cell>
	<Cell ss:StyleID="s27"><Data ss:Type="String">' . str_replace(
                                            chr(10),
                                            '&#10;',
                                            !empty($tData['previewLanguageValues']) && is_array($tData['previewLanguageValues']) ? htmlspecialchars((string)current($tData['previewLanguageValues'])) : ''
                                        ) . '</Data></Cell>
	<Cell ss:StyleID="s39"><Data ss:Type="String">' . str_replace(
                                            chr(10),
                                            '&#10;',
                                            htmlspecialchars((string)($tData['translationValue'] ?? ''))
                                        ) . '</Data></Cell>
	<Cell ss:StyleID="s27"><Data ss:Type="String">' . $diff . '</Data></Cell>
	</Row>
	';
                                    }
                                } else {
                                    $fieldsForRecord[] = '
<!-- Translation row: -->
<Row ss:StyleID="s25">
<Cell><Data ss:Type="String">' . htmlspecialchars('translation[' . $table . '][' . $elementUid . '][' . $key . ']') . '</Data></Cell>
<Cell ss:StyleID="s26"><Data ss:Type="String">' . htmlspecialchars((string)$fieldName) . '</Data></Cell>
<Cell ss:StyleID="s40"><Data ss:Type="String">' . $this->getLanguageService()->getLL('export.process.error.empty.message') . '!</Data></Cell>
<Cell ss:StyleID="s39"><Data ss:Type="String"></Data></Cell>
<Cell ss:StyleID="s27"><Data ss:Type="String"></Data></Cell>
' . ($page['header']['prevLang'] ? '<Cell ss:StyleID="s27"><Data ss:Type="String">' . str_replace(
                                        chr(10),
                                        '&#10;',
                                        !empty($tData['previewLanguageValues']) && is_array($tData['previewLanguageValues']) ? htmlspecialchars((string)current($tData['previewLanguageValues'])) : ''
                                    ) . '</Data></Cell>' : '') . '
</Row>
';
                                }
                            }
                        }
                        if (count($fieldsForRecord)) {
                            $output[] = '
	<!-- Element header -->
	<Row>
	<Cell ss:Index="2" ss:StyleID="s37"><Data ss:Type="String">Element: ' . htmlspecialchars($table . ':' . $elementUid) . '</Data></Cell>
	<Cell ss:StyleID="s37"></Cell>
	<Cell ss:StyleID="s37"></Cell>
	<Cell ss:StyleID="s37"></Cell>
	' . (isset($page['header']) && $page['header']['prevLang']
                                    ? '<Cell ss:StyleID="s37"></Cell>' : '') . '
	</Row>
	';
                            $output = array_merge($output, $fieldsForRecord);
                        }
                    }
                }
            }
            $output[] = '
	<!-- Spacer row -->
	<Row>
	<Cell ss:Index="2"><Data ss:Type="String"></Data></Cell>
	</Row>
	';
        }
        // Provide a hook for specific manipulations before building the actual XML
        if (!empty($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['exportExcelXmlPreProcess'])) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['exportExcelXmlPreProcess'] as $classReference) {
                $processingObject = GeneralUtility::makeInstance($classReference);
                $output = $processingObject->processBeforeExportingExcelXml($output, $this);
            }
        }
        $excelXML = GeneralUtility::getUrl(ExtensionManagementUtility::extPath('l10nmgr') . 'Resources/Private/Templates/ExcelTemplate.xml');
        $excelXML = str_replace('###INSERT_ROWS###', implode('', $output), $excelXML);
        $excelXML = str_replace('###INSERT_ROW_COUNT###', (string)count($output), $excelXML);
        $excelXML = str_replace('###SOURCE_COL_STATE###', $sourceColState, $excelXML);
        $excelXML = str_replace('###ALT_SOURCE_COL_STATE###', $altSourceColState, $excelXML);
        $excelXML = str_replace('###INSERT_INFORMATION###', $this->renderInternalMessage(), $excelXML);
        return $this->saveExportFile($excelXML);
    }

    /**
     * Renders the list of internal message as XML tags
     *
     * @return string The XML structure to output
     */
    protected function renderInternalMessage(): string
    {
        $messages = '';
        foreach ($this->internalMessages as $messageInformation) {
            $messages .= "\n\t\t\t" . '<Row>' . "\n\t\t\t\t" . '<Cell ss:Index="1" ss:StyleID="s37"><Data ss:Type="String">Skipped item	</Data></Cell>' . "\n\t\t\t" . '</Row>';
            $messages .= "\n\t\t\t" . '<Row>' . "\n\t\t\t\t" . '<Cell ss:Index="2" ss:StyleID="s26"><Data ss:Type="String">Description</Data></Cell>' . "\n\t\t\t\t" . '<Cell ss:StyleID="s27"><Data ss:Type="String">' . ($messageInformation['message'] ?? '') . '</Data></Cell>' . "\n\t\t\t" . '</Row>';
            $messages .= "\n\t\t\t" . '<Row>' . "\n\t\t\t\t" . '<Cell ss:Index="2" ss:StyleID="s26"><Data ss:Type="String">Key</Data></Cell>' . "\n\t\t\t\t" . '<Cell ss:StyleID="s27"><Data ss:Type="String">' . ($messageInformation['key'] ?? '') . '</Data></Cell>' . "\n\t\t\t" . '</Row>';
        }
        return $messages;
    }

    /**
     * Force a new source language to export the content to translate
     *
     * @param int $id
     */
    public function setForcedSourceLanguage(int $id)
    {
        $this->forcedSourceLanguage = $id;
    }
}
