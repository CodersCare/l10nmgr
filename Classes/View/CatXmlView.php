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

use Localizationteam\L10nmgr\Model\Tools\Utf8Tools;
use Localizationteam\L10nmgr\Model\Tools\XmlTools;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * CATXMLView: Renders the XML for the use for translation agencies
 *
 * @author Kasper Skaarhoj <kasperYYYY@typo3.com>
 * @author Daniel Poetzinger <development@aoemedia.de>
 * @author Daniel Zielinski <d.zielinski@L10Ntech.de>
 * @author Fabian Seltmann <fs@marketing-factory.de>
 * @author Andreas Otto <andreas.otto@dkd.de>
 */
class CatXmlView extends AbstractExportView
{
    protected int $exportType = 1;

    protected string $baseUrl = '';

    protected array $params = [];

    protected array $overrideParams = [];

    public function render(): string
    {
        $targetLanguage = $this->targetLanguage;
        $accumObj = $this->l10ncfgObj->getL10nAccumulatedInformationsObjectForLanguage($targetLanguage);
        if ($this->forcedSourceLanguage) {
            $accumObj->setForcedPreviewLanguage($this->forcedSourceLanguage);
        }
        if ($this->onlyForcedSourceLanguage) {
            $accumObj->setOnlyForcedPreviewLanguage();
        }
        $accum = $accumObj->getInfoArray($this->modeNoHidden);
        $output = [];

        if (empty($this->baseUrl)) {
            $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
            $this->baseUrl = (string)$siteFinder->getSiteByPageId($this->l10ncfgObj->getPid())->getRouter()->generateUri(0);
        }
        // Traverse the structure and generate XML output:
        foreach ($accum as $pId => $page) {
            if (empty($page['items'])) {
                continue;
            }
            $url = $page['header']['url'] ?? '';
            $output[] = "\t" . '<pageGrp id="' . $pId . '" sourceUrl="' . $url . '">' . "\n";
            foreach ($page['items'] as $table => $elements) {
                foreach ($elements as $elementUid => $data) {
                    if ($this->modeOnlyNew && !empty($data['translationInfo']['translations'])) {
                        continue;
                    }
                    if (empty($data['fields']) || !is_array($data['fields'])) {
                        continue;
                    }
                    $targetIso = $data['ISOcode'] ?? '';
                    foreach ($data['fields'] as $key => $tData) {
                        if (!is_array($tData)) {
                            continue;
                        }
                        $noChangeFlag = !strcmp(trim($tData['diffDefaultValue']), trim($tData['defaultValue']));
                        if ($this->modeOnlyChanged && $noChangeFlag) {
                            continue;
                        }
                        // @DP: Why this check?
                        if ($this->forcedSourceLanguage !== 0 && (!$this->forcedSourceLanguage || !isset($tData['previewLanguageValues'][$this->forcedSourceLanguage]))) {
                            $this->setInternalMessage(
                                $this->getLanguageService()->getLL('export.process.error.empty.message'),
                                $elementUid . '/' . $table . '/' . $key
                            );
                            continue;
                        }

                        $valueForXml = $this->getValueForXml($tData, $key);
                        if ($valueForXml === null) {
                            $this->setInternalMessage(
                                $this->getLanguageService()->getLL('export.process.error.invalid.message'),
                                $elementUid . '/' . $table . '/' . $key
                            );
                            continue;
                        }
                        $output[] = sprintf(
                            '%s<data table="%s" elementUid="%s" key="%s">%s</data>%s',
                            "\t\t",
                            $table,
                            $elementUid,
                            $key,
                            $valueForXml,
                            "\n"
                        );
                    }
                }
            }
            $output[] = "\t" . '</pageGrp>' . "\r";
        }
        // Provide a hook for specific manipulations before building the actual XML
        if (!empty($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['exportCatXmlPreProcess'])) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['exportCatXmlPreProcess'] as $classReference) {
                $processingObject = GeneralUtility::makeInstance($classReference);
                $output = $processingObject->processBeforeExportingCatXml($output, $this);
            }
        }
        $XML = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $XML .= '<!DOCTYPE TYPO3L10N [ <!ENTITY nbsp " "> ]>' . "\n" . '<TYPO3L10N>' . "\n";
        $XML .= "\t" . '<head>' . "\n";
        $XML .= "\t\t" . '<t3_l10ncfg translate="no">' . $this->l10ncfgObj->getUid() . '</t3_l10ncfg>' . "\n";

        $sourceLang = '';
        $sourceLanguageConfiguration = $this->site->getAvailableLanguages($this->getBackendUser())[$this->forcedSourceLanguage] ?? null;

        if ($sourceLanguageConfiguration instanceof SiteLanguage) {
            if ($this->typo3Version->getMajorVersion() < 12) {
                $sourceLang = $sourceLanguageConfiguration->getLocale() ?: $sourceLanguageConfiguration->getTwoLetterIsoCode();
            } else {
                $sourceLang = $sourceLanguageConfiguration->getLocale()->getName() ?: $sourceLanguageConfiguration->getLocale()->getLanguageCode();
            }
        }
        $targetLang = '';
        $targetLanguageConfiguration = $this->site->getAvailableLanguages($this->getBackendUser())[$this->targetLanguage] ?? null;
        if ($targetLanguageConfiguration instanceof SiteLanguage) {
            if ($this->typo3Version->getMajorVersion() < 12) {
                $targetLang = $targetLanguageConfiguration->getLocale() ?: $targetLanguageConfiguration->getTwoLetterIsoCode();
            } else {
                $targetLang = $targetLanguageConfiguration->getLocale()->getName() ?: $targetLanguageConfiguration->getLocale()->getLanguageCode();
            }
        }

        $XML .= "\t\t" . '<t3_sysLang translate="no">' . $this->targetLanguage . '</t3_sysLang>' . "\n";
        $XML .= "\t\t" . '<t3_sourceLang translate="no">' . $sourceLang . '</t3_sourceLang>' . "\n";
        $XML .= "\t\t" . '<t3_targetLang translate="no">' . $targetLang . '</t3_targetLang>' . "\n";
        $XML .= "\t\t" . '<t3_baseURL translate="no">' . $this->baseUrl . '</t3_baseURL>' . "\n";

        if ($accumObj->getExtensionConfiguration()->isEnableCustomername()) {
            // Customer set by CLI parameter will override CLI backend user name for CLI based exports
            $customer = $this->customer ?: $this->getBackendUser()->user['realName'];
            if ($customer) {
                $XML .= "\t\t" . '<t3_customer translate="no">' . $customer . '</t3_customer>' . "\n";
            }
        }
        $XML .= "\t\t" . '<t3_workspaceId translate="no">' . $this->getBackendUser()->workspace . '</t3_workspaceId>' . "\n";
        $XML .= "\t\t" . '<t3_count translate="no">' . $accumObj->getFieldCount() . '</t3_count>' . "\n";
        $XML .= "\t\t" . '<t3_wordCount translate="no">' . $accumObj->getWordCount() . '</t3_wordCount>' . "\n";
        $internalMessages = trim($this->renderInternalMessage());
        if ($internalMessages) {
            $XML .= "\t\t" . '<t3_internal translate="no">' . "\r\t" . $internalMessages . "\t\t" . '</t3_internal>' . "\n";
        }
        $XML .= "\t\t" . '<t3_formatVersion translate="no">' . L10NMGR_FILEVERSION . '</t3_formatVersion>' . "\n";
        $XML .= "\t\t" . '<t3_l10nmgrVersion translate="no">' . L10NMGR_VERSION . '</t3_l10nmgrVersion>' . "\n";
        $XML .= $this->additionalHeaderData();
        $XML .= "\t" . '</head>' . "\n";
        $XML .= implode('', $output) . "\n";
        $XML .= '</TYPO3L10N>';
        return $this->saveExportFile($XML);
    }

    protected function getValueForXml(array $tData, string $key): ?string
    {
        if ($this->forcedSourceLanguage) {
            $dataForTranslation = $tData['previewLanguageValues'][$this->forcedSourceLanguage] ?? '';
        } else {
            $dataForTranslation = $tData['defaultValue'] ?? '';
        }
        $xmlTool = GeneralUtility::makeInstance(XmlTools::class);
        // Following checks are not enough! Fields that could be transformed to be XML conform are not transformed! textpic fields are not isRTE=1!!! No idea why...
        //DZ 2010-09-08
        // > if > else loop instead of ||
        // Test re-import of XML! RTE-Back transformation
        //echo $tData['fieldType'];
        //if (preg_match('/templavoila_flex/',$key)) { echo "1 -"; }
        //echo $key."\n";
        if (!empty($tData['fieldType']) && $tData['fieldType'] === 'text' && !empty($tData['isRTE'])
            || (preg_match('/templavoila_flex/', $key))) {
            $dataForTranslationTransformed = $xmlTool->RTE2XML($dataForTranslation);
            if ($dataForTranslationTransformed !== false) {
                return $dataForTranslationTransformed;
            }
        }

        // Substitute HTML entities with actual characters (we use UTF-8 anyway:-) but leave quotes untouched
        $dataForTranslation = html_entity_decode(
            (string)$dataForTranslation,
            ENT_NOQUOTES,
            'UTF-8'
        );
        //Substitute & with &amp; in non-RTE fields
        $dataForTranslation = preg_replace(
            '/&(?!(amp|nbsp|quot|apos|lt|gt);)/',
            '&amp;',
            $dataForTranslation
        );
        //Substitute > and < in non-RTE fields
        $dataForTranslation = str_replace(' < ', ' &lt; ', $dataForTranslation);
        $dataForTranslation = str_replace(' > ', ' &gt; ', $dataForTranslation);
        $dataForTranslation = str_replace(
            '<br>',
            '<br />',
            $dataForTranslation
        );
        $dataForTranslation = str_replace(
            '<hr>',
            '<hr />',
            $dataForTranslation
        );
        if (empty($this->params)) {
            if (!Environment::isCli()) {
                $this->params = $this->getBackendUser()->getModuleData(
                    'l10nmgr/cm1/prefs',
                    'prefs'
                ) ?? [];
            }
            ArrayUtility::mergeRecursiveWithOverrule(
                $this->params,
                $this->overrideParams
            );
        }
        if (!empty($this->params['utf8'])) {
            $dataForTranslation = Utf8Tools::utf8_bad_strip($dataForTranslation);
        }
        if ($xmlTool->isValidXMLString($dataForTranslation)) {
            return $dataForTranslation;
        }
        if (!empty($this->params['noxmlcheck'])) {
            return '<![CDATA[' . $dataForTranslation . ']]>';
        }
        return null;
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
            if (!empty($messages)) {
                $messages .= "\n\t";
            }
            $messages .= "\t\t" . '<t3_skippedItem>' . "\n\t\t\t\t"
                . '<t3_description>' . ($messageInformation['message'] ?? '') . '</t3_description>' . "\n\t\t\t\t"
                . '<t3_key>' . ($messageInformation['key'] ?? '') . '</t3_key>' . "\n\t\t\t"
                . '</t3_skippedItem>' . "\r";
        }
        return $messages;
    }

    /**
     * Adds keys and values of the JSON encoded meta data field to the XML head section
     *
     * @return string The XML to add to the head section
     */
    protected function additionalHeaderData(): string
    {
        $additionalHeaderData = '';
        if (!empty($this->l10ncfgObj->getMetaData())) {
            $additionalHeaderDataArray = json_decode($this->l10ncfgObj->getMetaData());
            if (is_array($additionalHeaderDataArray) && !empty($additionalHeaderDataArray)) {
                foreach ($additionalHeaderDataArray as $key => $value) {
                    $additionalHeaderData .= "\t\t" . '<' . $key . '>' . $value . '</' . $key . '>' . "\n";
                }
            }
        }
        return $additionalHeaderData;
    }

    public function setBaseUrl(string $baseUrl): void
    {
        $this->baseUrl = $baseUrl;
    }

    public function setOverrideParams(array $overrideParams): void
    {
        $this->overrideParams = $overrideParams;
    }
}
