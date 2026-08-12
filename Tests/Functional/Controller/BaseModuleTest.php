<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Controller\BaseModule;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class BaseModuleTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['scheduler'];

    protected array $testExtensionsToLoad = ['localizationteam/l10nmgr'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
    }

    #[Test]
    public function initSetsIdFromTheRequestAttribute(): void
    {
        $subject = new BaseModule();
        $subject->MCONF = ['name' => 'l10nmgr_test_module'];
        $subject->request = (new ServerRequest('https://example.com/typo3/'))->withAttribute('id', 42);

        $subject->init();

        self::assertSame(42, $subject->id);
    }

    #[Test]
    public function initDefaultsIdToZeroWhenNotPresentOnTheRequest(): void
    {
        $subject = new BaseModule();
        $subject->MCONF = ['name' => 'l10nmgr_test_module'];
        $subject->request = new ServerRequest('https://example.com/typo3/');

        $subject->init();

        self::assertSame(0, $subject->id);
    }

    #[Test]
    public function initFallsBackToTheGlobalMconfWhenNoNameIsSetOnTheInstance(): void
    {
        $GLOBALS['MCONF'] = ['name' => 'global_module_name'];
        $subject = new BaseModule();
        $subject->request = new ServerRequest('https://example.com/typo3/');

        $subject->init();

        self::assertSame('global_module_name', $subject->MCONF['name']);
        unset($GLOBALS['MCONF']);
    }

    #[Test]
    public function menuConfigMergesSetValuesFromQueryParamsOverParsedBody(): void
    {
        $subject = new BaseModule();
        $subject->MCONF = ['name' => 'l10nmgr_test_module'];
        $subject->MOD_MENU = ['function' => ['a' => 'Option A', 'b' => 'Option B']];
        $subject->request = (new ServerRequest('https://example.com/typo3/'))
            ->withParsedBody(['SET' => ['function' => 'a']])
            ->withQueryParams(['SET' => ['function' => 'b']]);

        $subject->menuConfig();

        self::assertSame('b', $subject->MOD_SETTINGS['function']);
    }
}
