<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Controller\LocalizationModuleController;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Characterization test for LocalizationModuleController::getFuncMenu(): the 'label' entry in
 * $options uses plain htmlspecialchars($text), matching the sibling 'value' entry and the outer
 * $label built two lines below in the same method, both of which rely on the PHP 8.1+ default
 * flags (ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401) - all three call sites stay in sync.
 */
class LocalizationModuleControllerTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['scheduler'];

    protected array $testExtensionsToLoad = ['localizationteam/l10nmgr'];

    #[Test]
    public function getFuncMenuEscapesSingleQuotesInOptionLabelsLikeItsSiblings(): void
    {
        $menu = LocalizationModuleController::getFuncMenu(
            1,
            'SET[test]',
            'a',
            ['a' => "O'Reilly & <b>Sons</b>"]
        );

        self::assertSame('O&#039;Reilly &amp; &lt;b&gt;Sons&lt;/b&gt;', $menu['options'][0]['label']);
    }

    #[Test]
    public function getFuncCheckMarksTheElementCheckedWhenTheCurrentValueIsTruthy(): void
    {
        $result = LocalizationModuleController::getFuncCheck(1, 'SET[onlyChanged]', '1');

        self::assertSame(' checked="checked"', $result['checked']);
    }

    #[Test]
    public function getFuncCheckLeavesTheElementUncheckedWhenTheCurrentValueIsEmpty(): void
    {
        $result = LocalizationModuleController::getFuncCheck(1, 'SET[onlyChanged]', '');

        self::assertSame('', $result['checked']);
    }

    #[Test]
    public function getFuncCheckEscapesTheLabel(): void
    {
        $result = LocalizationModuleController::getFuncCheck(1, 'SET[onlyChanged]', '', '', '', '', "O'Reilly <b>Sons</b>");

        self::assertSame('O&#039;Reilly &lt;b&gt;Sons&lt;/b&gt;', $result['label']);
    }

    #[Test]
    public function getFuncCheckPrefixesTagParamsWithASpaceWhenGiven(): void
    {
        $result = LocalizationModuleController::getFuncCheck(1, 'SET[onlyChanged]', '', '', '', 'data-test="1"');

        self::assertSame(' data-test="1"', $result['tagParams']);
    }
}
