<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Services\MkPreviewLinkService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Characterization test for MkPreviewLinkService::compilePreviewKeyword(): its
 * `?int $fullWorkspace = null` parameter accepts both a null and an explicit int with no
 * runtime behavior difference between the two.
 */
class MkPreviewLinkServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['scheduler', 'workspaces'];

    protected array $testExtensionsToLoad = ['localizationteam/l10nmgr'];

    #[Test]
    public function compilePreviewKeywordAcceptsDefaultNullFullWorkspace(): void
    {
        $subject = new MkPreviewLinkService(0, 0, [1]);

        $keyword = $subject->compilePreviewKeyword('id=1', '1');

        self::assertSame(32, strlen($keyword));
    }

    #[Test]
    public function compilePreviewKeywordAcceptsExplicitIntFullWorkspace(): void
    {
        $subject = new MkPreviewLinkService(0, 0, [1]);

        $keyword = $subject->compilePreviewKeyword('id=1', '1', 172800, 2);

        self::assertSame(32, strlen($keyword));
    }
}
