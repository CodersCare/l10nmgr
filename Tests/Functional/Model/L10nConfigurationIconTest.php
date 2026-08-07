<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Characterization test for the icon-rendering call pattern used twice in
 * L10nConfiguration::getL10nAccumulatedInformationsObjectForLanguage()
 * (Classes/Model/L10nConfiguration.php): on CMS >=13 the call passes IconSize::SMALL
 * (Icon::SIZE_SMALL triggers a runtime deprecation there via
 * IconFactory::getIconForRecord()'s triggerDeprecation() call); on CMS 12, where the IconSize
 * class does not exist at all, it still passes Icon::SIZE_SMALL. This test exercises the
 * call pattern directly rather than the full surrounding method, which needs a
 * page-tree/backend-user DB fixture disproportionate to this being a one-constant swap.
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

    #[Test]
    public function iconFactoryRendersPageIconUsingLegacyIconSizeSmall(): void
    {
        if (class_exists(IconSize::class)) {
            self::markTestSkipped('TYPO3\CMS\Core\Imaging\IconSize exists since CMS 13; Icon::SIZE_SMALL is the CMS 12 codepath.');
        }

        $iconFactory = $this->get(IconFactory::class);
        $page = ['uid' => 1, 'title' => 'Test Page', 'doktype' => 1, 'hidden' => 0];

        $html = $iconFactory->getIconForRecord('pages', $page, Icon::SIZE_SMALL)->render();

        self::assertNotSame('', $html);
        self::assertStringContainsString('<span', $html);
    }
}
