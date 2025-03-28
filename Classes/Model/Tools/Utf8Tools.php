<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Model\Tools;

/**
 * Contains utf8 tools (taken from sourceforge phputf8 project)
 *
 * @author Daniel Pötzinger <development@aoemedia.de>
 */
class Utf8Tools
{
    /**
     * @version $Id: bad.php,v 1.2 2006/02/26 13:20:44 harryf Exp $
     * Tools for locating / replacing bad bytes in UTF-8 strings
     * The Original Code is Mozilla Communicator client code.
     * The Initial Developer of the Original Code is
     * Netscape Communications Corporation.
     * Portions created by the Initial Developer are Copyright (C) 1998
     * the Initial Developer. All Rights Reserved.
     * Ported to PHP by Henri Sivonen (http://hsivonen.iki.fi)
     * Slight modifications to fit with phputf8 library by Harry Fuecks (hfuecks gmail com)
     * @see http://lxr.mozilla.org/seamonkey/source/intl/uconv/src/nsUTF8ToUnicode.cpp
     * @see http://lxr.mozilla.org/seamonkey/source/intl/uconv/src/nsUnicodeToUTF8.cpp
     * @see http://hsivonen.iki.fi/php-utf8/
     * @see utf8_is_valid
     */
    /**
     * Locates the first bad byte in a UTF-8 string returning it's
     * byte index in the string
     * PCRE Pattern to locate bad bytes in a UTF-8 string
     * Comes from W3 FAQ: Multilingual Forms
     * Note: modified to include full ASCII range including control chars
     *
     * @see http://www.w3.org/International/questions/qa-forms-utf-8
     *
     * @return false|int integer byte index or FALSE if no bad found
     */
    public static function utf8_bad_find(string $str): mixed
    {
        $UTF8_BAD = '([\x00-\x7F]' . // ASCII (including control chars)
            '|[\xC2-\xDF][\x80-\xBF]' . // non-overlong 2-byte
            '|\xE0[\xA0-\xBF][\x80-\xBF]' . // excluding overlongs
            '|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}' . // straight 3-byte
            '|\xED[\x80-\x9F][\x80-\xBF]' . // excluding surrogates
            '|\xF0[\x90-\xBF][\x80-\xBF]{2}' . // planes 1-3
            '|[\xF1-\xF3][\x80-\xBF]{3}' . // planes 4-15
            '|\xF4[\x80-\x8F][\x80-\xBF]{2}' . // plane 16
            '|(.{1}))';// invalid byte
        $pos = 0;
        while (preg_match('/' . $UTF8_BAD . '/S', $str, $matches)) {
            $bytes = strlen($matches[0]);
            if (isset($matches[2])) {
                return $pos;
            }
            $pos += $bytes;
            $str = substr($str, $bytes);
        }
        return false;
    }

    /**
     * Locates all bad bytes in a UTF-8 string and returns a list of their
     * byte index in the string
     * PCRE Pattern to locate bad bytes in a UTF-8 string
     * Comes from W3 FAQ: Multilingual Forms
     * Note: modified to include full ASCII range including control chars
     *
     * @see http://www.w3.org/International/questions/qa-forms-utf-8
     *
     * @return array|false array of integers or FALSE if no bad found
     */
    public static function utf8_bad_findall(string $str): mixed
    {
        $UTF8_BAD = '([\x00-\x7F]' . // ASCII (including control chars)
            '|[\xC2-\xDF][\x80-\xBF]' . // non-overlong 2-byte
            '|\xE0[\xA0-\xBF][\x80-\xBF]' . // excluding overlongs
            '|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}' . // straight 3-byte
            '|\xED[\x80-\x9F][\x80-\xBF]' . // excluding surrogates
            '|\xF0[\x90-\xBF][\x80-\xBF]{2}' . // planes 1-3
            '|[\xF1-\xF3][\x80-\xBF]{3}' . // planes 4-15
            '|\xF4[\x80-\x8F][\x80-\xBF]{2}' . // plane 16
            '|(.{1}))';// invalid byte
        $pos = 0;
        $badList = [];
        while (preg_match('/' . $UTF8_BAD . '/S', $str, $matches)) {
            $bytes = strlen($matches[0]);
            if (isset($matches[2])) {
                $badList[] = $pos;
            }
            $pos += $bytes;
            $str = substr($str, $bytes);
        }
        if (count($badList) > 0) {
            return $badList;
        }
        return false;
    }

    /**
     * Strips out any bad bytes from a UTF-8 string and returns the rest
     * PCRE Pattern to locate bad bytes in a UTF-8 string
     * Comes from W3 FAQ: Multilingual Forms
     * Note: modified to include full ASCII range including control chars
     *
     * @see http://www.w3.org/International/questions/qa-forms-utf-8
     */
    public static function utf8_bad_strip(string $str): string
    {
        $UTF8_BAD = '([\x00-\x7F]' . // ASCII (including control chars)
            '|[\xC2-\xDF][\x80-\xBF]' . // non-overlong 2-byte
            '|\xE0[\xA0-\xBF][\x80-\xBF]' . // excluding overlongs
            '|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}' . // straight 3-byte
            '|\xED[\x80-\x9F][\x80-\xBF]' . // excluding surrogates
            '|\xF0[\x90-\xBF][\x80-\xBF]{2}' . // planes 1-3
            '|[\xF1-\xF3][\x80-\xBF]{3}' . // planes 4-15
            '|\xF4[\x80-\x8F][\x80-\xBF]{2}' . // plane 16
            '|(.{1}))';// invalid byte
        ob_start();
        while (preg_match('/' . $UTF8_BAD . '/S', $str, $matches)) {
            if (!isset($matches[2])) {
                echo $matches[0];
            }
            $str = substr($str, strlen($matches[0]));
        }
        $result = ob_get_contents();
        ob_end_clean();
        return $result;
    }

    /**
     * Replace bad bytes with an alternative character - ASCII character
     * recommended is replacement char
     * PCRE Pattern to locate bad bytes in a UTF-8 string
     * Comes from W3 FAQ: Multilingual Forms
     * Note: modified to include full ASCII range including control chars
     *
     * @see http://www.w3.org/International/questions/qa-forms-utf-8
     *
     * @param string $str String to search
     * @param string $replace String to replace bad bytes with (defaults to '?') - use ASCII
     */
    public static function utf8_bad_replace(string $str, string $replace = '?'): string
    {
        $UTF8_BAD = '([\x00-\x7F]' . // ASCII (including control chars)
            '|[\xC2-\xDF][\x80-\xBF]' . // non-overlong 2-byte
            '|\xE0[\xA0-\xBF][\x80-\xBF]' . // excluding overlongs
            '|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}' . // straight 3-byte
            '|\xED[\x80-\x9F][\x80-\xBF]' . // excluding surrogates
            '|\xF0[\x90-\xBF][\x80-\xBF]{2}' . // planes 1-3
            '|[\xF1-\xF3][\x80-\xBF]{3}' . // planes 4-15
            '|\xF4[\x80-\x8F][\x80-\xBF]{2}' . // plane 16
            '|(.{1}))';// invalid byte
        ob_start();
        while (preg_match('/' . $UTF8_BAD . '/S', $str, $matches)) {
            if (!isset($matches[2])) {
                echo $matches[0];
            } else {
                echo $replace;
            }
            $str = substr($str, strlen($matches[0]));
        }
        $result = ob_get_contents();
        ob_end_clean();
        return $result;
    }
    /**
     * @version $Id: validation.php,v 1.2 2006/02/26 13:20:44 harryf Exp $
     * Tools for validing a UTF-8 string is well formed.
     * The Original Code is Mozilla Communicator client code.
     * The Initial Developer of the Original Code is
     * Netscape Communications Corporation.
     * Portions created by the Initial Developer are Copyright (C) 1998
     * the Initial Developer. All Rights Reserved.
     * Ported to PHP by Henri Sivonen (http://hsivonen.iki.fi)
     * Slight modifications to fit with phputf8 library by Harry Fuecks (hfuecks gmail com)
     * @see http://lxr.mozilla.org/seamonkey/source/intl/uconv/src/nsUTF8ToUnicode.cpp
     * @see http://lxr.mozilla.org/seamonkey/source/intl/uconv/src/nsUnicodeToUTF8.cpp
     * @see http://hsivonen.iki.fi/php-utf8/
     */

    /**
     * Tests a string as to whether it's valid UTF-8 and supported by the
     * Unicode standard
     * Note: this function has been modified to simple return true or false
     *
     * @param string $str UTF-8 encoded string
     *
     * @return bool true if valid
     * @author <hsivonen@iki.fi>
     *
     * @see http://hsivonen.iki.fi/php-utf8/
     * @see utf8_compliant
     */
    public static function utf8_is_valid(string $str): bool
    {
        $mState = 0; // cached expected number of octets after the current octet
        // until the beginning of the next UTF8 character sequence
        $mUcs4 = 0; // cached Unicode character
        $mBytes = 1; // cached expected number of octets in the current sequence
        $len = strlen($str);
        for ($i = 0; $i < $len; $i++) {
            $in = ord($str[$i]);
            if ($mState == 0) {
                // When mState is zero we expect either a US-ASCII character or a
                // multi-octet sequence.
                if (0 == (0x80 & ($in))) {
                    // US-ASCII, pass straight through.
                    $mBytes = 1;
                } else {
                    if (0xC0 == (0xE0 & ($in))) {
                        // First octet of 2 octet sequence
                        $mUcs4 = ($in);
                        $mUcs4 = ($mUcs4 & 0x1F) << 6;
                        $mState = 1;
                        $mBytes = 2;
                    } else {
                        if (0xE0 == (0xF0 & ($in))) {
                            // First octet of 3 octet sequence
                            $mUcs4 = ($in);
                            $mUcs4 = ($mUcs4 & 0x0F) << 12;
                            $mState = 2;
                            $mBytes = 3;
                        } else {
                            if (0xF0 == (0xF8 & ($in))) {
                                // First octet of 4 octet sequence
                                $mUcs4 = ($in);
                                $mUcs4 = ($mUcs4 & 0x07) << 18;
                                $mState = 3;
                                $mBytes = 4;
                            } else {
                                if (0xF8 == (0xFC & ($in))) {
                                    /* First octet of 5 octet sequence.
                                    *
                                    * This is illegal because the encoded codepoint must be either
                                    * (a) not the shortest form or
                                    * (b) outside the Unicode range of 0-0x10FFFF.
                                    * Rather than trying to resynchronize, we will carry on until the end
                                    * of the sequence and let the later error handling code catch it.
                                    */
                                    $mUcs4 = ($in);
                                    $mUcs4 = ($mUcs4 & 0x03) << 24;
                                    $mState = 4;
                                    $mBytes = 5;
                                } else {
                                    if (0xFC == (0xFE & ($in))) {
                                        // First octet of 6 octet sequence, see comments for 5 octet sequence.
                                        $mUcs4 = ($in);
                                        $mUcs4 = ($mUcs4 & 1) << 30;
                                        $mState = 5;
                                        $mBytes = 6;
                                    } else {
                                        /* Current octet is neither in the US-ASCII range nor a legal first
                                        * octet of a multi-octet sequence.
                                        */
                                        return false;
                                    }
                                }
                            }
                        }
                    }
                }
            } else {
                // When mState is non-zero, we expect a continuation of the multi-octet
                // sequence
                if (0x80 == (0xC0 & ($in))) {
                    // Legal continuation.
                    $shift = ($mState - 1) * 6;
                    $tmp = $in;
                    $tmp = ($tmp & 0x0000003F) << $shift;
                    $mUcs4 |= $tmp;
                    /**
                     * End of the multi-octet sequence. mUcs4 now contains the final
                     * Unicode codepoint to be output
                     */
                    if (0 == --$mState) {
                        /*
                        * Check for illegal sequences and codepoints.
                        */
                        // From Unicode 3.1, non-shortest form is illegal
                        if (((2 == $mBytes) && ($mUcs4 < 0x0080)) || ((3 == $mBytes) && ($mUcs4 < 0x0800)) || ((4 == $mBytes) && ($mUcs4 < 0x10000)) || (4 < $mBytes) || // From Unicode 3.2, surrogate characters are illegal
                            (($mUcs4 & 0xFFFFF800) == 0xD800) || // Codepoints outside the Unicode range are illegal
                            ($mUcs4 > 0x10FFFF)
                        ) {
                            return false;
                        }
                        // initialize UTF8 cache
                        $mState = 0;
                        $mUcs4 = 0;
                        $mBytes = 1;
                    }
                } else {
                    /**
                     *((0xC0 & (*in) != 0x80) && (mState != 0))
                     * Incomplete multi-octet sequence.
                     */
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Tests whether a string complies as UTF-8. This will be much
     * faster than utf8_is_valid but will pass five and six octet
     * UTF-8 sequences, which are not supported by Unicode and
     * so cannot be displayed correctly in a browser. In other words
     * it is not as strict as utf8_is_valid but it's faster. If you use
     * is to validate user input, you place yourself at the risk that
     * attackers will be able to inject 5 and 6 byte sequences (which
     * may or may not be a significant risk, depending on what you are
     * are doing)
     *
     * @param string $str UTF-8 string to check
     *
     * @return bool TRUE if string is valid UTF-8
     * @see utf8_is_valid
     * @see http://www.php.net/manual/en/reference.pcre.pattern.modifiers.php#54805
     */
    public static function utf8_compliant(string $str): bool
    {
        if (strlen($str) == 0) {
            return true;
        }
        // If even just the first character can be matched, when the /u
        // modifier is used, then it's valid UTF-8. If the UTF-8 is somehow
        // invalid, nothing at all will match, even if the string contains
        // some valid sequences
        return preg_match('/^.{1}/us', $str, $ar) == 1;
    }
}
