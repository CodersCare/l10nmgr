<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Traits\BackendUserTrait;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class BackendUserTraitTestDouble
{
    use BackendUserTrait;

    public function callGetBackendUser(): BackendUserAuthentication
    {
        return $this->getBackendUser();
    }
}

class BackendUserTraitTest extends UnitTestCase
{
    // GeneralUtility::makeInstance(BackendUserAuthentication::class) registers a LogManager
    // singleton as a side effect (logger auto-injection for LoggerAwareInterface) - reset it.
    protected bool $resetSingletonInstances = true;

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function getBackendUserReturnsTheGlobalBeUserWhenSet(): void
    {
        $beUser = $this->createStub(BackendUserAuthentication::class);
        $GLOBALS['BE_USER'] = $beUser;

        self::assertSame($beUser, (new BackendUserTraitTestDouble())->callGetBackendUser());
    }

    #[Test]
    public function getBackendUserCreatesANewInstanceWhenGlobalBeUserIsNotSet(): void
    {
        unset($GLOBALS['BE_USER']);

        self::assertInstanceOf(BackendUserAuthentication::class, (new BackendUserTraitTestDouble())->callGetBackendUser());
    }
}
