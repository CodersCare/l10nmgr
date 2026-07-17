<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Services\MkPreviewLinkService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Characterization test for MkPreviewLinkService::compilePreviewKeyword(), covering the
 * L10N-015 fix: `int $fullWorkspace = null` is an implicit-nullable parameter, deprecated
 * since PHP 8.4 ("Implicitly marking parameter type as nullable is deprecated"). Fixing it
 * to `?int $fullWorkspace = null` is a pure type-declaration change with no runtime
 * behavior difference — these tests prove the method still accepts both a null and an
 * explicit int for this parameter, and should pass identically before and after the fix.
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
