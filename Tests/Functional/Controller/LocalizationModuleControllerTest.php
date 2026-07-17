<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Controller\LocalizationModuleController;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Characterization test for LocalizationModuleController::getFuncMenu(), covering the
 * L10N-017 fix: the 'label' entry in $options previously called
 * htmlspecialchars($text, ENT_COMPAT, 'UTF-8', false), leaving single quotes unescaped,
 * unlike the sibling 'value' entry and the outer $label built two lines below in the same
 * method, both of which rely on the PHP 8.1+ default flags (ENT_QUOTES | ENT_SUBSTITUTE |
 * ENT_HTML401). Fixing it to plain htmlspecialchars($text) aligns all three call sites.
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

        self::assertSame("O&#039;Reilly &amp; &lt;b&gt;Sons&lt;/b&gt;", $menu['options'][0]['label']);
    }
}
