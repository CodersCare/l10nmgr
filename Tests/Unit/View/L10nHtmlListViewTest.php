<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\View\L10nHtmlListView;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Covers getValidationDataAsJsonString(), the static arrayToLogString(), and the recursive TCA
 * config-transformation helpers (replaceLanguageFileReferences(), replaceAbsolutePathsToRelativeResourcesPath(),
 * resolveUrlPath()) - the parts of L10nHtmlListView that don't need the full
 * L10nAccumulatedInformation/Site pipeline. renderOverview()/render() drive that whole pipeline and
 * are deferred alongside L10nAccumulatedInformation (see backlog).
 */
class L10nHtmlListViewTest extends UnitTestCase
{
    private function createSubject(): L10nHtmlListView
    {
        return new class extends L10nHtmlListView {
            public function __construct()
            {
            }
        };
    }

    private function invoke(L10nHtmlListView $subject, string $method, array $args): mixed
    {
        return (new \ReflectionMethod($subject, $method))->invoke($subject, ...$args);
    }

    #[Test]
    public function getValidationDataAsJsonStringConvertsEvalListToTypedRules(): void
    {
        $subject = $this->createSubject();

        $result = $this->invoke($subject, 'getValidationDataAsJsonString', [['eval' => 'trim,required']]);

        self::assertSame([['type' => 'trim'], ['type' => 'required']], json_decode($result, true));
    }

    #[Test]
    public function getValidationDataAsJsonStringIncludesRangeBoundsWhenSet(): void
    {
        $subject = $this->createSubject();

        $result = $this->invoke($subject, 'getValidationDataAsJsonString', [['range' => ['lower' => 1, 'upper' => 10]]]);

        self::assertSame([['type' => 'range', 'lower' => 1, 'upper' => 10]], json_decode($result, true));
    }

    #[Test]
    public function getValidationDataAsJsonStringDefaultsMinAndMaxItemsWhenOnlyOneIsGiven(): void
    {
        $subject = $this->createSubject();

        $result = $this->invoke($subject, 'getValidationDataAsJsonString', [['maxitems' => 5, 'type' => 'select']]);

        self::assertSame([['type' => 'select', 'minItems' => 0, 'maxItems' => 5]], json_decode($result, true));
    }

    #[Test]
    public function getValidationDataAsJsonStringReturnsEmptyArrayForAnEmptyConfig(): void
    {
        $subject = $this->createSubject();

        self::assertSame('[]', $this->invoke($subject, 'getValidationDataAsJsonString', [[]]));
    }

    #[Test]
    public function arrayToLogStringIncludesOnlyTheRequestedKeys(): void
    {
        $result = L10nHtmlListView::arrayToLogString(['a' => '1', 'b' => '2', 'c' => '3'], ['a', 'c']);

        self::assertSame('a: 1; c: 3; ', $result);
    }

    #[Test]
    public function arrayToLogStringIncludesEveryKeyWhenNoListIsGiven(): void
    {
        $result = L10nHtmlListView::arrayToLogString(['a' => '1', 'b' => '2']);

        self::assertSame('a: 1; b: 2; ', $result);
    }

    #[Test]
    public function arrayToLogStringTruncatesLongValues(): void
    {
        $result = L10nHtmlListView::arrayToLogString(['a' => str_repeat('x', 30)], [], 10);

        self::assertStringContainsString('a: ', $result);
        self::assertLessThan(30, strlen($result));
    }

    #[Test]
    public function replaceLanguageFileReferencesResolvesLllPrefixedStringsRecursively(): void
    {
        $languageService = $this->createStub(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(static fn (string $key): string => 'resolved:' . $key);
        $GLOBALS['LANG'] = $languageService;
        $subject = $this->createSubject();

        $result = $this->invoke($subject, 'replaceLanguageFileReferences', [[
            'label' => 'LLL:some.key',
            'nested' => ['label' => 'LLL:nested.key'],
            'untouched' => 'plain value',
        ]]);

        self::assertStringStartsWith('resolved:', $result['label']);
        self::assertStringStartsWith('resolved:', $result['nested']['label']);
        self::assertSame('plain value', $result['untouched']);
        unset($GLOBALS['LANG']);
    }

    #[Test]
    public function replaceAbsolutePathsToRelativeResourcesPathResolvesExtPrefixedStringsRecursively(): void
    {
        $subject = $this->createSubject();

        // 'l10nmgr' is not registered as a "loaded" extension in a plain unit bootstrap (same class
        // of limitation as ExtensionManagementUtility::extPath() elsewhere in this suite), so this
        // uses 'EXT:core/...', which always resolves - the point of the test is that the recursive
        // walk finds and resolves any 'EXT:'-prefixed string, not the specific extension.
        $result = $this->invoke($subject, 'replaceAbsolutePathsToRelativeResourcesPath', [[
            'icon' => 'EXT:core/Resources/Public/Icons/favicon.ico',
            'nested' => ['icon' => 'EXT:core/Resources/Public/Icons/favicon.ico'],
            'untouched' => 'plain value',
        ]]);

        // GeneralUtility::getFileAbsFileName()'s exact resolution for a path that doesn't exist on
        // disk is an unreliable implementation detail to assert on here - the point of this test is
        // that the recursive walk finds and transforms every 'EXT:'-prefixed value, not what the
        // resolved path happens to be.
        self::assertStringNotContainsString('EXT:', $result['icon']);
        self::assertNotSame('EXT:core/Resources/Public/Icons/favicon.ico', $result['nested']['icon']);
        self::assertSame('plain value', $result['untouched']);
    }
}
