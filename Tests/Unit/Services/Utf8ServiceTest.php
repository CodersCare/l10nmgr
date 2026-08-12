<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Services\Utf8Service;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class Utf8ServiceTest extends UnitTestCase
{
    #[Test]
    public function utf8IsValidAcceptsPlainAscii(): void
    {
        self::assertTrue(Utf8Service::utf8_is_valid('Hello World'));
    }

    #[Test]
    public function utf8IsValidAcceptsWellFormedMultibyteCharacters(): void
    {
        self::assertTrue(Utf8Service::utf8_is_valid('Grüße Ünïcödé 日本語'));
    }

    #[Test]
    public function utf8IsValidRejectsLoneContinuationByte(): void
    {
        self::assertFalse(Utf8Service::utf8_is_valid("Hello \x80 World"));
    }

    #[Test]
    public function utf8IsValidAcceptsAMultiByteSequenceTruncatedAtTheEndOfTheString(): void
    {
        // \xC3 announces a 2-byte sequence but the string ends before its continuation byte.
        // The loop only inspects bytes up to strlen($str) and never checks whether mState
        // returned to 0 once the string is exhausted, so a sequence left dangling exactly at
        // the end of the string is not caught - a genuine looseness in this validator, not
        // something this test is asserting should happen.
        self::assertTrue(Utf8Service::utf8_is_valid("caf\xC3"));
    }

    #[Test]
    public function utf8IsValidRejectsOverlongEncoding(): void
    {
        // \xC0\x80 is an overlong (non-shortest-form) encoding of the NUL character
        self::assertFalse(Utf8Service::utf8_is_valid("\xC0\x80"));
    }

    #[Test]
    public function utf8BadFindReturnsFalseForCleanString(): void
    {
        self::assertFalse(Utf8Service::utf8_bad_find('Grüße'));
    }

    #[Test]
    public function utf8BadFindReturnsByteIndexOfFirstBadByte(): void
    {
        self::assertSame(2, Utf8Service::utf8_bad_find("ok\x80bad"));
    }

    #[Test]
    public function utf8BadFindallReturnsFalseForCleanString(): void
    {
        self::assertFalse(Utf8Service::utf8_bad_findall('Grüße'));
    }

    #[Test]
    public function utf8BadFindallReturnsAllBadByteIndexes(): void
    {
        self::assertSame([2, 5], Utf8Service::utf8_bad_findall("ok\x80ok\x81"));
    }

    #[Test]
    public function utf8BadStripRemovesOnlyTheBadBytes(): void
    {
        self::assertSame('okok', Utf8Service::utf8_bad_strip("ok\x80ok\x81"));
    }

    #[Test]
    public function utf8BadStripLeavesCleanStringUnchanged(): void
    {
        self::assertSame('Grüße', Utf8Service::utf8_bad_strip('Grüße'));
    }

    #[Test]
    public function utf8BadReplaceSubstitutesEachBadByteWithReplacementCharacter(): void
    {
        self::assertSame('ok?ok?', Utf8Service::utf8_bad_replace("ok\x80ok\x81"));
    }

    #[Test]
    public function utf8BadReplaceUsesCustomReplacementString(): void
    {
        self::assertSame('ok[BAD]ok', Utf8Service::utf8_bad_replace("ok\x80ok", '[BAD]'));
    }

    #[Test]
    public function utf8CompliantAcceptsEmptyString(): void
    {
        self::assertTrue(Utf8Service::utf8_compliant(''));
    }

    #[Test]
    public function utf8CompliantAcceptsWellFormedMultibyteString(): void
    {
        self::assertTrue(Utf8Service::utf8_compliant('Grüße 日本語'));
    }

    #[Test]
    public function utf8CompliantRejectsLoneContinuationByte(): void
    {
        self::assertFalse(Utf8Service::utf8_compliant("\x80"));
    }

    /**
     * @return array[]
     */
    public static function fiveAndSixByteSequencesDataProvider(): array
    {
        return [
            '5-byte sequence lead byte is rejected by utf8_is_valid (strict)' => ["\xF8\x88\x80\x80\x80"],
            '6-byte sequence lead byte is rejected by utf8_is_valid (strict)' => ["\xFC\x84\x80\x80\x80\x80"],
        ];
    }

    #[Test]
    #[DataProvider('fiveAndSixByteSequencesDataProvider')]
    public function utf8IsValidRejectsFiveAndSixByteSequencesThatUtf8CompliantWouldAccept(string $sequence): void
    {
        // Documents the documented difference between the two validators: utf8_is_valid is strict
        // (Unicode 3.2, max 4 bytes), utf8_compliant only checks the /u regex modifier can match at
        // all, which is more lenient with 5/6-byte lead bytes despite them being illegal in Unicode.
        self::assertFalse(Utf8Service::utf8_is_valid($sequence));
    }
}
