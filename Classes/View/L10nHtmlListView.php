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
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Configuration\Richtext;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * l10nHTMLListView:
 * renders accumulated informations for the browser:
 * - Table with inline editing / links etc...
 *
 * @author Kasper Skaarhoj <kasperYYYY@typo3.com>
 * @author Daniel Pötzinger <development@aoemedia.de>
 * @author Stefano Kowalke <info@arroba-it.de>
 */
class L10nHtmlListView extends AbstractExportView
{
    public const DISPLAY_MODE_RENDER_ALL_ITEMS = 0;

    public const DISPLAY_MODE_RENDER_OVERVIEW_WITH_DETAILS = 1;

    public const DISPLAY_MODE_RENDER_OVERVIEW_WITH_NO_DETAILS = 2;

    protected L10nConfiguration $l10ncfgObj;

    protected array $l10ncfg = [];

    protected int $sysLang;

    //internal flags:
    protected bool $modeWithInlineEdit = false;

    protected bool $modeShowEditLinks = false;

    protected ModuleTemplate $moduleTemplate;

    public function __construct(L10nConfiguration $l10ncfgObj, int $sysLang, ModuleTemplate $moduleTemplate)
    {
        $this->moduleTemplate = $moduleTemplate;
        parent::__construct($l10ncfgObj, $sysLang);
    }

    public function setModeWithInlineEdit(): void
    {
        $this->modeWithInlineEdit = true;
    }

    public function setModeShowEditLinks(): void
    {
        $this->modeShowEditLinks = true;
    }

    /**
     * Render the module content in HTML
     */
    public function renderOverview(): array
    {
        $targetLanguage = $this->targetLanguage;
        $accumObj = $this->l10ncfgObj->getL10nAccumulatedInformationsObjectForLanguage($targetLanguage);
        $accum = $accumObj->getInfoArray();
        $l10ncfg = $this->l10ncfg;
        $sections = [];
        $showSingle = GeneralUtility::_GET('showSingle');
        $noAnalysis = false;
        if ($l10ncfg !== null && !empty($l10ncfg['displaymode']) && $l10ncfg['displaymode'] > self::DISPLAY_MODE_RENDER_ALL_ITEMS) {
            $showSingle = $showSingle ?: 'NONE';
            if ($l10ncfg['displaymode'] === self::DISPLAY_MODE_RENDER_OVERVIEW_WITH_NO_DETAILS) {
                $noAnalysis = true;
            }
        }

        // Traverse the structure and generate HTML output:
        foreach ($accum as $pId => $page) {
            $sections[$pId]['head']['icon'] = $page['header']['icon'] ?? '';
            $sections[$pId]['head']['title'] = htmlspecialchars((string)($page['header']['title'] ?? '')) . ' [' . $pId . ']';
            $tableRows = [];
            if (empty($page['items'])) {
                continue;
            }
            foreach ($page['items'] as $table => $elements) {
                foreach ($elements as $elementUid => $data) {
                    if (!empty($data['fields']) && is_array($data['fields'])) {
                        $FtableRows = [];
                        $FtableRowsNew = [];
                        $flags = [];
                        if (!$noAnalysis || $showSingle === $table . ':' . $elementUid) {
                            foreach ($data['fields'] as $key => $tData) {
                                if (is_array($tData)) {
                                    [, $uidString, $fieldName] = explode(':', $key);
                                    [$uidValue] = explode('/', $uidString);
                                    $noChangeFlag = !strcmp(
                                        trim($tData['diffDefaultValue'] ?? ''),
                                        trim($tData['defaultValue'] ?? '')
                                    );
                                    $flags['new'] = 0;
                                    $flags['unknown'] = 0;
                                    $flags['noChange'] = 0;
                                    $flags['update'] = 0;
                                    if ($uidValue === 'NEW') {
                                        $diff = '<em>' . $this->getLanguageService()->getLL('render_overview.new.message') . '</em>';
                                        $flags['new']++;
                                    } elseif (!isset($tData['diffDefaultValue'])) {
                                        $diff = '<em>' . $this->getLanguageService()->getLL('render_overview.nodiff.message') . '</em>';
                                        $flags['unknown']++;
                                    } elseif ($noChangeFlag) {
                                        $diff = $this->getLanguageService()->getLL('render_overview.nochange.message');
                                        $flags['noChange']++;
                                    } else {
                                        $diff = $this->diffCMP($tData['diffDefaultValue'] ?? '', $tData['defaultValue'] ?? '');
                                        $flags['update']++;
                                    }
                                    if (!$this->modeOnlyChanged || !$noChangeFlag) {
                                        $fieldCells = [];
                                        $fieldCells[] = '<b>' . htmlspecialchars($fieldName) . '</b>' . (!empty($tData['msg']) ? '<br /><em>' . htmlspecialchars((string)$tData['msg']) . '</em>' : '');
                                        $fieldCells[] = nl2br(htmlspecialchars((string)($tData['defaultValue'] ?? '')));
                                        if ($this->modeWithInlineEdit) {
                                            $name = htmlspecialchars('translation[' . $table . '][' . $elementUid . '][' . $key . ']');
                                            $value = htmlspecialchars((string)($tData['translationValue'] ?? ''));
                                            if ($tData['fieldType'] === 'text') {
                                                $id = md5($table . '_' . $elementUid . '_' . $key);
                                                $value = LF . $value;
                                                $cellContent = sprintf('<textarea class="w-100" id="%s" name="%s">%s</textarea>', $id, $name, $value);
                                                if (ExtensionManagementUtility::isLoaded('rte_ckeditor') && !empty($tData['isRTE'])) {
                                                    /** @var Richtext $richtextConfigurationProvider */
                                                    $richtextConfigurationProvider = GeneralUtility::makeInstance(Richtext::class);
                                                    $richtextConfiguration = $richtextConfigurationProvider->getConfiguration($table, $fieldName, $pId, 'text', $tData['TCEformsCfg'] ?? []);

                                                    $configuration = $this->prepareConfigurationForEditor($richtextConfiguration['editor']['config'] ?? [], (string)($data['ISOcode'] ?? ''));

                                                    $externalPlugins = '';
                                                    $urlParameters = [
                                                        'P' => [
                                                            'table' => $table,
                                                            'uid' => $elementUid,
                                                            'fieldName' => $fieldName,
                                                            'recordType' => 'text',
                                                            'pid' => $pId,
                                                            'richtextConfigurationName' => $richtextConfiguration['preset'] ?? '',
                                                        ],
                                                    ];

                                                    if (isset($richtextConfiguration['editor']['externalPlugins'])) {
                                                        $configuration['extraPlugins'] = GeneralUtility::trimExplode(',', $configuration['extraPlugins'] ?? '');

                                                        foreach ($this->getExtraPlugins($richtextConfiguration['editor']['externalPlugins'], $urlParameters) as $extraPluginName => $extraPluginConfig) {
                                                            $configName = $extraPluginConfig['configName'] ?? $extraPluginName;
                                                            if (!empty($extraPluginConfig['config']) && is_array($extraPluginConfig['config'])) {
                                                                if (empty($configuration[$configName])) {
                                                                    $configuration[$configName] = $extraPluginConfig['config'];
                                                                } elseif (is_array($configuration[$configName])) {
                                                                    $configuration[$configName] = array_replace_recursive(
                                                                        $extraPluginConfig['config'],
                                                                        $configuration[$configName]
                                                                    );
                                                                }
                                                            }
                                                            $configuration['extraPlugins'][] = $extraPluginName;

                                                            $externalPlugins .= 'CKEDITOR.plugins.addExternal(';
                                                            $externalPlugins .= GeneralUtility::quoteJSvalue($extraPluginName) . ',';
                                                            $externalPlugins .= GeneralUtility::quoteJSvalue($extraPluginConfig['resource'] ?? '') . ',';
                                                            $externalPlugins .= '\'\');';
                                                        }
                                                    }

                                                    $configuration['extraPlugins'] = implode(',', array_flip(array_flip($configuration['extraPlugins'])));

                                                    $RTE_Configuration = json_encode($configuration);
                                                    if (GeneralUtility::makeInstance(Typo3Version::class)->getMajorVersion() === 11) {
                                                        $cellContent .= '<script type="text/javascript">' . $externalPlugins . 'CKEDITOR.replace(\'' . $id . '\', ' . $RTE_Configuration . ');</script>';
                                                    } else {
                                                        $ckeditorAttributes = GeneralUtility::implodeAttributes([
                                                            'id' => $id . 'ckeditor5',
                                                            'options' => GeneralUtility::jsonEncodeForHtmlAttribute($configuration, false),
                                                            'form-engine' => GeneralUtility::jsonEncodeForHtmlAttribute([
                                                                'id' => $id,
                                                                'name' => $name,
                                                                'value' => htmlspecialchars_decode($value),
                                                                'validationRules' => $this->getValidationDataAsJsonString($tData['TCEformsCfg']),
                                                            ], false),
                                                        ], true);
                                                        $cellContent = '<typo3-rte-ckeditor-ckeditor5 ' . $ckeditorAttributes . '></typo3-rte-ckeditor-ckeditor5>';
                                                    }
                                                }
                                                $fieldCells[] = $cellContent;
                                            } else {
                                                $fieldCells[] = sprintf(
                                                    '<input class="w-100" name="%s" value="%s" />',
                                                    $name,
                                                    $value
                                                );
                                            }
                                        } else {
                                            $fieldCells[] = nl2br(htmlspecialchars((string)($tData['translationValue'] ?? '')));
                                        }
                                        $fieldCells[] = $diff;
                                        if (!empty($page['header']['prevLang']) && !empty($tData['previewLanguageValues']) && is_array($tData['previewLanguageValues'])) {
                                            reset($tData['previewLanguageValues']);
                                            $fieldCells[] = nl2br(htmlspecialchars((string)current($tData['previewLanguageValues'])));
                                        }
                                        $FtableRows[] = '<tr><td>' . implode('</td><td>', $fieldCells) . '</td></tr>';
                                        $FtableRowsNew[] = [
                                            'class' => '',
                                            'html' => '<td>' . implode('</td><td>', $fieldCells) . '</td>',
                                        ];
                                    }
                                }
                            }
                        }
                        if (count($FtableRows) || $noAnalysis) {
                            $editLink = $this->getEditLink($data, $targetLanguage, $table);
                            $tableAndElementUid = htmlspecialchars($table . ':' . $elementUid);
                            $translationStatus = htmlspecialchars(self::arrayToLogString($flags));
                            $tableRows[] = [
                                'class' => 'info',
                                'html' => '<th colspan="2">' . $tableAndElementUid . ' ' . $editLink . '</th>
                                           <th colspan="3">' . $translationStatus . '</th>',
                            ];

                            if (!$showSingle || $showSingle === $table . ':' . $elementUid) {
                                $tableRows[] = [
                                    'class' => '',
                                    'html' => '<th>Fieldname</th>
                                                <th style="width: 25%">Default</th>
                                                <th style="min-width: 25%">Translation</th>
                                                <th style="min-width: 25%">Diff</th>
                                                ' . (!empty($page['header']['prevLang']) ? '<th style="min-width: 25%">PrevLang</th>' : ''),
                                ];

                                $tableRows = array_merge($tableRows, $FtableRowsNew);
                            }
                        }
                    }
                }
            }
            if (count($tableRows)) {
                $sections[$pId]['rows'] = $tableRows;
            }
        }
        return $sections;
    }

    protected function getValidationDataAsJsonString(array $config): string
    {
        $validationRules = [];
        if (!empty($config['eval'])) {
            $evalList = GeneralUtility::trimExplode(',', $config['eval'] ?? '', true);
            foreach ($evalList as $evalType) {
                $validationRules[] = [
                    'type' => $evalType,
                ];
            }
        }
        if (!empty($config['range'])) {
            $newValidationRule = [
                'type' => 'range',
            ];
            if (!empty($config['range']['lower'])) {
                $newValidationRule['lower'] = $config['range']['lower'];
            }
            if (!empty($config['range']['upper'])) {
                $newValidationRule['upper'] = $config['range']['upper'];
            }
            $validationRules[] = $newValidationRule;
        }
        if (!empty($config['maxitems']) || !empty($config['minitems'])) {
            $minItems = isset($config['minitems']) ? (int)$config['minitems'] : 0;
            $maxItems = isset($config['maxitems']) ? (int)$config['maxitems'] : 99999;
            $type = $config['type'] ?: 'range';
            $validationRules[] = [
                'type' => $type,
                'minItems' => $minItems,
                'maxItems' => $maxItems,
            ];
        }
        if (!empty($config['required'])) {
            $validationRules[] = ['type' => 'required'];
        }
        if (!empty($config['min'])) {
            $validationRules[] = ['type' => 'min'];
        }
        return json_encode($validationRules);
    }

    /**
     * Converts a one dimensional array to a one line string which can be used for logging or debugging output
     * Example: "loginType: FE; refInfo: Array; HTTP_HOST: www.example.org; REMOTE_ADDR: 192.168.1.5; REMOTE_HOST:; security_level:; showHiddenRecords: 0;"
     *
     * @param array $arr Data array which should be outputted
     * @param array $valueList List of keys which should be listed in the output string. Pass a comma list or an array. An empty list outputs the whole array.
     * @param int $valueLength Long string values are shortened to this length. Default: 20
     * @return string Output string with key names and their value as string
     */
    public static function arrayToLogString(array $arr, array $valueList = [], int $valueLength = 20): string
    {
        $str = '';
        if (!is_array($valueList)) {
            $valueList = GeneralUtility::trimExplode(',', $valueList, true);
        }
        $valListCnt = count($valueList);
        foreach ($arr as $key => $value) {
            if (!$valListCnt || in_array($key, $valueList)) {
                $str .= $key . trim(': ' . GeneralUtility::fixed_lgd_cs(str_replace(LF, '|', (string)$value), $valueLength)) . '; ';
            }
        }
        return $str;
    }

    /**
     * @throws \TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException
     */
    /**
     * @throws RouteNotFoundException
     */
    protected function getEditLink(array $data, int $targetLanguage, string $table): string
    {
        if ($this->modeShowEditLinks === false) {
            return '';
        }

        $uidString = '';
        if (!empty($data['fields']) && is_array($data['fields'])) {
            reset($data['fields']);
            [, $uidString] = explode(':', key($data['fields']));
        }
        if (!str_starts_with($uidString, 'NEW')) {
            $editId = !empty($data['translationInfo']['translations'][$targetLanguage]) && is_array($data['translationInfo']['translations'][$targetLanguage])
                ? $data['translationInfo']['translations'][$targetLanguage]['uid']
                : ($data['translationInfo']['uid'] ?? 0);

            $linkText = '[' . $this->getLanguageService()->getLL('render_overview.clickedit.message') . ']';
            $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
            $translationTable = $data['translationInfo']['translation_table'] ?? '';
            $params = [
                'edit' => [
                    $translationTable => [
                        $editId => 'edit',
                    ],
                ],
                'returnUrl' => GeneralUtility::getIndpEnv('REQUEST_URI'),
            ];
            $href = (string)$uriBuilder->buildUriFromRoute('record_edit', $params);
        } else {
            $linkText = '[' . $this->getLanguageService()->getLL('render_overview.clicklocalize.message') . ']';
            $href = htmlspecialchars(
                BackendUtility::getLinkToDataHandlerAction('&cmd[' . $table . '][' . ($data['translationInfo']['uid'] ?? 0) . '][localize]=' . $targetLanguage)
            );
        }

        return ' - <a href="' . $href . '"><em>' . $linkText . '</em></a>';
    }

    /**
     * Compiles the configuration set from the outside
     * to have it easily injected into the CKEditor.
     *
     * @return array the configuration
     */
    protected function prepareConfigurationForEditor(array $richtextConfiguration, string $languageIsoCode): array
    {
        // Ensure custom config is empty so nothing additional is loaded
        // Of course this can be overridden by the editor configuration below
        $configuration = [
            'customConfig' => '',
        ];

        $configuration = array_replace_recursive($configuration, $richtextConfiguration);

        // Set the UI language of the editor if not hard-coded by the existing configuration
        if (empty($configuration['language'])) {
            $configuration['language'] = $this->getBackendUser()->uc['lang'] ?? ($this->getBackendUser()->user['lang'] ?? 'en');
        }
        $configuration['contentsLanguage'] = $languageIsoCode;

        // Replace all label references
        $configuration = $this->replaceLanguageFileReferences($configuration);
        // Replace all paths
        $configuration = $this->replaceAbsolutePathsToRelativeResourcesPath($configuration);

        // there are some places where we define an array, but it needs to be a list in order to work
        if (!empty($configuration['extraPlugins']) && is_array($configuration['extraPlugins'])) {
            $configuration['extraPlugins'] = implode(',', $configuration['extraPlugins']);
        }
        if (!empty($configuration['removePlugins']) && is_array($configuration['removePlugins'])) {
            $configuration['removePlugins'] = implode(',', $configuration['removePlugins']);
        }
        if (!empty($configuration['removeButtons']) && is_array($configuration['removeButtons'])) {
            $configuration['removeButtons'] = implode(',', $configuration['removeButtons']);
        }

        return $configuration;
    }

    /**
     * Get configuration of external/additional plugins
     *
     * @throws RouteNotFoundException
     */
    protected function getExtraPlugins(array $externalPlugins, array $urlParameters): array
    {
        $pluginConfiguration = [];
        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
        foreach ($externalPlugins as $pluginName => $configuration) {
            $pluginConfiguration[$pluginName] = [
                'configName' => $configuration['configName'] ?? $pluginName,
                'resource' => $this->resolveUrlPath($configuration['resource'] ?? ''),
            ];
            unset($configuration['configName']);
            unset($configuration['resource']);

            if (!empty($configuration['route'])) {
                $configuration['routeUrl'] = (string)$uriBuilder->buildUriFromRoute($configuration['route'] ?? '', $urlParameters);
            }

            $pluginConfiguration[$pluginName]['config'] = $configuration;
        }

        return $pluginConfiguration;
    }

    /**
     * Add configuration to replace LLL: references with the translated value
     *
     */
    protected function replaceLanguageFileReferences(array $configuration): array
    {
        foreach ($configuration as $key => $value) {
            if (is_array($value)) {
                $configuration[$key] = $this->replaceLanguageFileReferences($value);
            } elseif (is_string($value) && stripos($value, 'LLL:') === 0) {
                $configuration[$key] = $this->getLanguageService()->sL($value);
            }
        }
        return $configuration;
    }

    /**
     * Add configuration to replace absolute EXT: paths with relative ones
     *
     */
    protected function replaceAbsolutePathsToRelativeResourcesPath(array $configuration): array
    {
        foreach ($configuration as $key => $value) {
            if (is_array($value)) {
                $configuration[$key] = $this->replaceAbsolutePathsToRelativeResourcesPath($value);
            } elseif (is_string($value) && stripos($value, 'EXT:') === 0) {
                $configuration[$key] = $this->resolveUrlPath($value);
            }
        }
        return $configuration;
    }

    /**
     * Resolves an EXT: syntax file to an absolute web URL
     */
    protected function resolveUrlPath(string $value): string
    {
        $value = GeneralUtility::getFileAbsFileName($value);
        return PathUtility::getAbsoluteWebPath($value);
    }

    public function render(): string
    {
        // TODO: Implement render() method.
        return '';
    }
}
