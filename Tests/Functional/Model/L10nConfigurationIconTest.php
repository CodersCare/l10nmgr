<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Characterization test for the icon-rendering call pattern used twice in
 * L10nConfiguration::getInitializedTreeInformation() (Classes/Model/L10nConfiguration.php,
 * lines ~195/205), covering the L10N-020 fix: TYPO3\CMS\Core\Imaging\Icon::SIZE_SMALL is
 * removed entirely in CMS 14 (the string constant no longer exists, and
 * IconFactory::getIconForRecord()'s third parameter is strictly typed IconSize there,
 * vs. string|IconSize in CMS 13). IconSize::SMALL is confirmed present with an
 * identical enum case in both the CMS 13 and CMS 14 vendor trees, so the fix needs
 * no version guard. This test exercises the fixed call pattern directly rather than
 * the full surrounding method, which needs a page-tree/backend-user DB fixture
 * disproportionate to this being a one-constant swap.
 */
class L10nConfigurationIconTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['scheduler'];

    protected array $testExtensionsToLoad = ['localizationteam/l10nmgr'];

    #[Test]
    public function iconFactoryRendersPageIconUsingIconSizeSmall(): void
    {
        if (!class_exists(IconSize::class)) {
            self::markTestSkipped('TYPO3\CMS\Core\Imaging\IconSize does not exist before CMS 13.');
        }

        $iconFactory = $this->get(IconFactory::class);
        $page = ['uid' => 1, 'title' => 'Test Page', 'doktype' => 1, 'hidden' => 0];

        $html = $iconFactory->getIconForRecord('pages', $page, IconSize::SMALL)->render();

        self::assertNotSame('', $html);
        self::assertStringContainsString('<span', $html);
    }
}
