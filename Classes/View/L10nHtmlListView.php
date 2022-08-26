<?php

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
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Configuration\Richtext;
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

    /**
     * @var L10nConfiguration
     */
    protected $l10ncfgObj;

    /**
     * @var array
     */
    protected $l10ncfg;

    /**
     * @var int
     */
    protected $sysLang;

    //internal flags:
    /**
     * @var bool
     */
    protected $modeWithInlineEdit = false;

    /**
     * @var bool
     */
    protected $modeShowEditLinks = false;

    /**
     * ModuleTemplate Container
     *
     * @var ModuleTemplate
     */
    protected $moduleTemplate;

    /**
     * L10nHtmlListView constructor.
     *
     * @param L10nConfiguration $l10ncfgObj
     * @param int $sysLang
     */
    public function __construct($l10ncfgObj, $sysLang)
    {
        $this->moduleTemplate = GeneralUtility::makeInstance(ModuleTemplate::class);
        parent::__construct($l10ncfgObj, $sysLang);
    }

    public function setModeWithInlineEdit()
    {
        $this->modeWithInlineEdit = true;
    }

    public function setModeShowEditLinks()
    {
        $this->modeShowEditLinks = true;
    }

    /**
     * Render the module content in HTML
     */
    public function renderOverview(): array
    {
        $sysLang = $this->sysLang;
        $accumObj = $this->l10ncfgObj->getL10nAccumulatedInformationsObjectForLanguage($sysLang);
        $accum = $accumObj->getInfoArray();
        $l10ncfg = $this->l10ncfg;
        $sections = [];
        $showSingle = GeneralUtility::_GET('showSingle');
        $noAnalysis = false;
        if ($l10ncfg !== null && $l10ncfg['displaymode'] > self::DISPLAY_MODE_RENDER_ALL_ITEMS) {
            $showSingle = $showSingle ?: 'NONE';
            if ($l10ncfg['displaymode'] === self::DISPLAY_MODE_RENDER_OVERVIEW_WITH_NO_DETAILS) {
                $noAnalysis = true;
            }
        }

        // Traverse the structure and generate HTML output:
        foreach ($accum as $pId => $page) {
            $sections[$pId]['head']['icon'] = $page['header']['icon'];
            $sections[$pId]['head']['title'] = htmlspecialchars($page['header']['title']) . ' [' . $pId . ']';
            $tableRows = [];
            foreach ($accum[$pId]['items'] as $table => $elements) {
                foreach ($elements as $elementUid => $data) {
                    if (is_array($data['fields'])) {
                        $FtableRows = [];
                        $FtableRowsNew = [];
                        $flags = [];
                        if (!$noAnalysis || $showSingle === $table . ':' . $elementUid) {
                            foreach ($data['fields'] as $key => $tData) {
                                if (is_array($tData)) {
                                    [, $uidString, $fieldName] = explode(':', $key);
                                    [$uidValue] = explode('/', $uidString);
                                    $edit = true;
                                    $noChangeFlag = !strcmp(
                                        trim($tData['diffDefaultValue']),
                                        trim($tData['defaultValue'])
                                    );
                                    if ($uidValue === 'NEW') {
                                        $diff = '<em>' . $this->getLanguageService()->getLL('render_overview.new.message') . '</em>';
                                        if (!isset($flags['new'])) {
                                            $flags['new'] = 0;
                                        }
                                        $flags['new']++;
                                    } elseif (!isset($tData['diffDefaultValue'])) {
                                        $diff = '<em>' . $this->getLanguageService()->getLL('render_overview.nodiff.message') . '</em>';
                                        if (!isset($flags['unknown'])) {
                                            $flags['unknown'] = 0;
                                        }
                                        $flags['unknown']++;
                                    } elseif ($noChangeFlag) {
                                        $diff = $this->getLanguageService()->getLL('render_overview.nochange.message');
                                        $edit = true;
                                        if (!isset($flags['noChange'])) {
                                            $flags['noChange'] = 0;
                                        }
                                        $flags['noChange']++;
                                    } else {
                                        $diff = $this->diffCMP($tData['diffDefaultValue'], $tData['defaultValue']);
                                        if (!isset($flags['update'])) {
                                            $flags['update'] = 0;
                                        }
                                        $flags['update']++;
                                    }
                                    if (!$this->modeOnlyChanged || !$noChangeFlag) {
                                        $fieldCells = [];
                                        $fieldCells[] = '<b>' . htmlspecialchars($fieldName) . '</b>' . ($tData['msg'] ? '<br /><em>' . htmlspecialchars($tData['msg']) . '</em>' : '');
                                        $fieldCells[] = nl2br(htmlspecialchars($tData['defaultValue']));
                                        if ($edit && $this->modeWithInlineEdit) {
                                            $name = htmlspecialchars('translation[' . $table . '][' . $elementUid . '][' . $key . ']');
                                            $value = htmlspecialchars($tData['translationValue']);
                                            if ($tData['fieldType'] === 'text') {
                                                $id = md5($table . '_' . $elementUid . '_' . $key);
                                                $value = LF . $value;
                                                $cellContent = sprintf('<textarea id="%s" name="%s">%s</textarea>', $id, $name, $value);
                                                if (ExtensionManagementUtility::isLoaded('rte_ckeditor') && $tData['isRTE']) {
                                                    /** @var Richtext $richtextConfigurationProvider */
                                                    $richtextConfigurationProvider = GeneralUtility::makeInstance(Richtext::class);
                                                    $richtextConfiguration = $richtextConfigurationProvider->getConfiguration($table, $fieldName, $pId, 'text', $tData['TCEformsCfg']);

                                                    $configuration = $this->prepareConfigurationForEditor($richtextConfiguration['editor']['config'], $data['ISOcode']);

                                                    $externalPlugins = '';
                                                    $urlParameters = [
                                                        'P' => [
                                                            'table' => $table,
                                                            'uid' => $elementUid,
                                                            'fieldName' => $fieldName,
                                                            'recordType' => 'text',
                                                            'pid' => $pId,
                                                            'richtextConfigurationName' => $richtextConfiguration['preset'],
                                                        ],
                                                    ];

                                                    if (isset($richtextConfiguration['editor']['externalPlugins'])) {
                                                        $configuration['extraPlugins'] = GeneralUtility::trimExplode(',', $configuration['extraPlugins']);

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
                                                            $externalPlugins .= GeneralUtility::quoteJSvalue($extraPluginConfig['resource']) . ',';
                                                            $externalPlugins .= '\'\');';
                                                        }
                                                    }

                                                    $configuration['extraPlugins'] = implode(',', array_flip(array_flip($configuration['extraPlugins'])));

                                                    $RTE_Configuration = json_encode($configuration);
                                                    $cellContent .= '<script type="text/javascript">' . $externalPlugins . 'CKEDITOR.replace(\'' . $id . '\', ' . (string)$RTE_Configuration . ');</script>';
                                                }
                                                $fieldCells[] = $cellContent;
                                            } else {
                                                $fieldCells[] = sprintf(
                                                    '<input name="%s" value="%s" />',
                                                    $name,
                                                    $value
                                                );
                                            }
                                        } else {
                                            $fieldCells[] = nl2br(htmlspecialchars($tData['translationValue']));
                                        }
                                        $fieldCells[] = $diff;
                                        if ($page['header']['prevLang'] && is_array($tData['previewLanguageValues'])) {
                                            reset($tData['previewLanguageValues']);
                                            $fieldCells[] = nl2br(htmlspecialchars(current($tData['previewLanguageValues'])));
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
                            $editLink = $this->getEditLink($data, $sysLang, $table);
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
                                                <th style="width: 25%">Translation</th>
                                                <th style="width: 25%">Diff</th>
                                                ' . ($page['header']['prevLang'] ? '<th style="width: 25%">PrevLang</th>' : '') . '',
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

    /**
     * Converts a one dimensional array to a one line string which can be used for logging or debugging output
     * Example: "loginType: FE; refInfo: Array; HTTP_HOST: www.example.org; REMOTE_ADDR: 192.168.1.5; REMOTE_HOST:; security_level:; showHiddenRecords: 0;"
     *
     * @param array $arr Data array which should be outputted
     * @param mixed $valueList List of keys which should be listed in the output string. Pass a comma list or an array. An empty list outputs the whole array.
     * @param int $valueLength Long string values are shortened to this length. Default: 20
     * @return string Output string with key names and their value as string
     */
    public static function arrayToLogString(array $arr, $valueList = [], int $valueLength = 20): string
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

    protected function getEditLink(array $data, int $sysLang, string $table): string
    {
        if ($this->modeShowEditLinks === false) {
            return '';
        }

        $uidString = '';
        if (is_array($data['fields'])) {
            reset($data['fields']);
            [, $uidString] = explode(':', key($data['fields']));
        }
        if (substr($uidString, 0, 3) !== 'NEW') {
            $editId = is_array($data['translationInfo']['translations'][$sysLang])
                ? $data['translationInfo']['translations'][$sysLang]['uid']
                : $data['translationInfo']['uid'];

            $linkText = '[' . $this->getLanguageService()->getLL('render_overview.clickedit.message') . ']';
            $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
            $params = [
                'edit' => [
                    $data['translationInfo']['translation_table'] => [
                        $editId => 'edit',
                    ],
                ],
                'returnUrl' => GeneralUtility::getIndpEnv('REQUEST_URI'),
            ];
            $href = (string)$uriBuilder->buildUriFromRoute('record_edit', $params);
            $editLink = ' - <a href="' . $href . '"><em>' . $linkText . '</em></a>';
        } else {
            $linkText = '[' . $this->getLanguageService()->getLL('render_overview.clicklocalize.message') . ']';
            $href = htmlspecialchars(
                BackendUtility::getLinkToDataHandlerAction('&cmd[' . $table . '][' . $data['translationInfo']['uid'] . '][localize]=' . $sysLang)
            );
            $editLink = ' - <a href="' . $href . '"><em>' . $linkText . '</em></a>';
        }

        return $editLink;
    }

    /**
     * Compiles the configuration set from the outside
     * to have it easily injected into the CKEditor.
     *
     * @param array $richtextConfiguration
     * @param string $languageIsoCode
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
            $configuration['language'] = $this->getBackendUser()->uc['lang'] ?: ($this->getBackendUser()->user['lang'] ?: 'en');
        }
        $configuration['contentsLanguage'] = $languageIsoCode;

        // Replace all label references
        $configuration = $this->replaceLanguageFileReferences($configuration);
        // Replace all paths
        $configuration = $this->replaceAbsolutePathsToRelativeResourcesPath($configuration);

        // there are some places where we define an array, but it needs to be a list in order to work
        if (is_array($configuration['extraPlugins'])) {
            $configuration['extraPlugins'] = implode(',', $configuration['extraPlugins']);
        }
        if (is_array($configuration['removePlugins'])) {
            $configuration['removePlugins'] = implode(',', $configuration['removePlugins']);
        }
        if (is_array($configuration['removeButtons'])) {
            $configuration['removeButtons'] = implode(',', $configuration['removeButtons']);
        }

        return $configuration;
    }

    /**
     * Get configuration of external/additional plugins
     *
     * @param array $externalPlugins
     * @param array $urlParameters
     * @return array
     */
    protected function getExtraPlugins(array $externalPlugins, array $urlParameters): array
    {
        $pluginConfiguration = [];
        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
        foreach ($externalPlugins as $pluginName => $configuration) {
            $pluginConfiguration[$pluginName] = [
                'configName' => $configuration['configName'] ?? $pluginName,
                'resource' => $this->resolveUrlPath($configuration['resource']),
            ];
            unset($configuration['configName']);
            unset($configuration['resource']);

            if ($configuration['route']) {
                $configuration['routeUrl'] = (string)$uriBuilder->buildUriFromRoute($configuration['route'], $urlParameters);
            }

            $pluginConfiguration[$pluginName]['config'] = $configuration;
        }

        return $pluginConfiguration;
    }

    /**
     * Add configuration to replace LLL: references with the translated value
     * @param array $configuration
     *
     * @return array
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
     * @param array $configuration
     *
     * @return array
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
     *
     * @param string $value
     * @return string
     */
    protected function resolveUrlPath(string $value): string
    {
        $value = GeneralUtility::getFileAbsFileName($value);
        return PathUtility::getAbsoluteWebPath($value);
    }
}
