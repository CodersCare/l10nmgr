<?php

declare(strict_types=1);

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
 * Contains xml tools
 * $Id$
 *
 * @author Daniel Pötzinger <development@aoemedia.de>
 */

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Html\RteHtmlParser;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class XmlTools implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(protected readonly RteHtmlParser $parseHTML)
    {
    }

    /**
     * Parses XML input into a PHP array with associative keys
     *
     * @param string $string XML data input
     * @param int $depth Number of element levels to resolve the XML into an array. Any further structure will be set as XML.
     * @param array $parserOptions Options that will be passed to PHP's xml_parser_set_option()
     * @return mixed The array with the parsed structure unless the XML parser returns with an error in which case the error message string is returned.
     */
    public static function xml2tree(string $string, int $depth = 999, array $parserOptions = []): mixed
    {
        $parser = xml_parser_create();
        $vals = [];
        $index = [];
        xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
        xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 0);
        foreach ($parserOptions as $option => $value) {
            xml_parser_set_option($parser, $option, $value);
        }
        xml_parse_into_struct($parser, $string, $vals, $index);
        if (xml_get_error_code($parser)) {
            return 'Line ' . xml_get_current_line_number($parser) . ': ' . xml_error_string(xml_get_error_code($parser));
        }
        xml_parser_free($parser);
        $stack = [[]];
        $stacktop = 0;
        $startPoint = 0;
        $tagi = [];
        foreach ($vals as $key => $val) {
            $type = $val['type'] ?? '';
            if ($type && !empty($val['tag'])) {
                // open tag:
                if ($type === 'open' || $type === 'complete') {
                    $stack[$stacktop++] = $tagi;
                    if ($depth == $stacktop) {
                        $startPoint = $key;
                    }
                    $tagi = ['tag' => $val['tag']];
                    if (isset($val['attributes'])) {
                        $tagi['attrs'] = $val['attributes'];
                    }
                    if (isset($val['value'])) {
                        $tagi['values'][] = $val['value'];
                    }
                }
                // finish tag:
                if ($type === 'complete' || $type === 'close') {
                    $oldtagi = $tagi;
                    $tagi = $stack[--$stacktop];
                    $oldtag = $oldtagi['tag'];
                    unset($oldtagi['tag']);
                    if ($depth == $stacktop + 1) {
                        if ($key - $startPoint > 0) {
                            $partArray = array_slice($vals, $startPoint + 1, $key - $startPoint - 1);
                            $oldtagi['XMLvalue'] = self::xmlRecompileFromStructValArray($partArray);
                        } else {
                            $oldtagi['XMLvalue'] = $oldtagi['values'][0] ?? '';
                        }
                    }
                    $tagi['ch'][$oldtag][] = $oldtagi;
                    unset($oldtagi);
                }
                // cdata
                if ($type === 'cdata') {
                    $tagi['values'][] = $val['value'] ?? '';
                }
            }
        }
        return $tagi['ch'] ?? [];
    }

    /**
     * This implodes an array of XML parts (made with xml_parse_into_struct()) into XML again.
     *
     * @param array $vals An array of XML parts, see xml2tree
     * @return string Re-compiled XML data.
     */
    protected static function xmlRecompileFromStructValArray(array $vals): string
    {
        $XMLcontent = '';
        $selfClosingTags = [
            'area' => 1,
            'base' => 1,
            'br' => 1,
            'col' => 1,
            'command' => 1,
            'hr' => 1,
            'img' => 1,
            'input' => 1,
            'keygen' => 1,
            'link' => 1,
            'meta' => 1,
            'param' => 1,
            'source' => 1,
        ];
        foreach ($vals as $val) {
            $type = $val['type'] ?? '';
            // Open tag:
            if ($type && !empty($val['tag'])) {
                if ($type === 'open' || $type === 'complete') {
                    $XMLcontent .= '<' . $val['tag'];
                    if (!empty($val['attributes'])) {
                        foreach ($val['attributes'] as $k => $v) {
                            $XMLcontent .= ' ' . $k . '="' . htmlspecialchars((string)$v) . '"';
                        }
                    }
                    if ($type === 'complete') {
                        if (!isset($val['value']) && isset($selfClosingTags[$val['tag']])) {
                            $XMLcontent .= ' />';
                        } else {
                            $XMLcontent .= '>' . htmlspecialchars((string)($val['value'] ?? null)) . '</' . $val['tag'] . '>';
                        }
                    } else {
                        $XMLcontent .= '>';
                    }
                    if ($type === 'open' && isset($val['value'])) {
                        $XMLcontent .= htmlspecialchars((string)$val['value']);
                    }
                }
                // Finish tag:
                if ($type === 'close') {
                    $XMLcontent .= '</' . $val['tag'] . '>';
                }
                // Cdata
                if ($type === 'cdata') {
                    $XMLcontent .= htmlspecialchars((string)$val['value']);
                }
            }
        }
        return $XMLcontent;
    }

    /**
     * Transforms a RTE Field to valid XML
     *
     * @param string $content HTML String which should be transformed
     *
     * @return mixed false if transformation failed, string with XML if all fine
     */
    public function RTE2XML(string $content, int $withStripBadUTF8 = 0): mixed
    {
        //function RTE2XML($content,$withStripBadUTF8=$this->getBackendUser()->getModuleData('l10nmgr/cm1/checkUTF8', '')) {
        //if (!$withStripBadUTF8) {
        // $withStripBadUTF8 = $this->getBackendUser()->getModuleData('l10nmgr/cm1/checkUTF8', '');
        //}
        //echo '###'.$withStripBadUTF8;
        // First call special transformations (registered using hooks)
        if (!empty($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['transformation'])) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['transformation'] as $classReference) {
                $processingObject = GeneralUtility::makeInstance($classReference);
                $content = $processingObject->transform_rte($content, $this->parseHTML);
            }
        }
        $content = str_replace(CR, '', $content);
        $pageTsConf = BackendUtility::getPagesTSconfig(0);
        $rteConfiguration = $pageTsConf['RTE.']['default.'] ?? [];
        $rteConfiguration['mode'] = 'rte';
        $content = $this->parseHTML->transformTextForRichTextEditor($content, $rteConfiguration);
        //substitute & with &amp;
        //$content=str_replace('&','&amp;',$content); Changed by DZ 2011-05-11
        $content = str_replace('<hr>', '<hr />', $content);
        $content = str_replace('<br>', '<br />', $content);
        $content = preg_replace('/&amp;([#[:alnum:]]*;)/', '&\\1', $content);
        if ($withStripBadUTF8 == 1) {
            $content = Utf8Tools::utf8_bad_strip($content);
        }
        if ($this->isValidXMLString($content)) {
            return $content;
        }
        return false;
    }

    public function isValidXMLString(string $xmlString): bool
    {
        return $this->isValidXML('<!DOCTYPE dummy [ <!ENTITY nbsp " "> ]><dummy>' . $xmlString . '</dummy>');
    }

    protected function isValidXML(string $xml): bool
    {
        $parser = xml_parser_create();
        $vals = [];
        $index = [];
        xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
        xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 0);
        xml_parse_into_struct($parser, $xml, $vals, $index);
        if (xml_get_error_code($parser)) {
            return false;
        }
        return true;
    }

    /**
     * Transforms a XML back to RTE / reverse function of RTE2XML
     *
     * @param string $xmlstring XMLString which should be transformed
     *
     * @return string string with HTML
     */
    public function XML2RTE(string $xmlstring): string
    {
        //fixed setting of Parser (TO-DO set it via typoscript)
        //Added because import failed
        $xmlstring = str_replace('<br/>', '<br>', $xmlstring);
        $xmlstring = str_replace('<br />', '<br>', $xmlstring);
        $xmlstring = str_replace('<hr/>', '<hr>', $xmlstring);
        $xmlstring = str_replace('<hr />', '<hr>', $xmlstring);
        $xmlstring = str_replace("\xc2\xa0", '&nbsp;', $xmlstring);
        //Writes debug information for CLI import.
        $this->logger->debug(__FILE__ . ': Before RTE transformation:' . LF . $xmlstring . LF);
        $pageTsConf = BackendUtility::getPagesTSconfig(0);
        $rteConfiguration = $pageTsConf['RTE.']['default.'] ?? [];
        $rteConfiguration['mode'] = 'db';
        $content = $this->parseHTML->transformTextForPersistence($xmlstring, $rteConfiguration);
        // Last call special transformations (registered using hooks)
        if (!empty($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['transformation'])) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['transformation'] as $classReference) {
                $processingObject = GeneralUtility::makeInstance($classReference);
                $content = $processingObject->transform_db($content, $this->parseHTML);
            }
        }
        //substitute URL in <link> for CLI import
        $content = preg_replace('/<link http(s)?:\/\/[\w\.\/]*\?id=/', '<link ', $content);
        //Writes debug information for CLI import.
        $this->logger->debug(__FILE__ . ': After RTE transformation:' . LF . $content . LF);
        return $content;
    }
}
