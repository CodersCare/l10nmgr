<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Model\L10nConfiguration;
use Localizationteam\L10nmgr\View\L10nConfigurationDetailView;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class L10nConfigurationDetailViewTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $languageService = self::createStub(LanguageService::class);
        $languageService->method('sL')->willReturnArgument(0);
        $GLOBALS['LANG'] = $languageService;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function renderReturnsAnInvalidMarkerWhenTheConfigurationIsNotLoaded(): void
    {
        $subject = new L10nConfigurationDetailView(new L10nConfiguration());

        $result = $subject->render();

        self::assertTrue($result['isInvalid']);
        self::assertArrayNotHasKey('header', $result);
    }

    #[Test]
    public function renderReturnsHtmlEscapedFieldsForALoadedConfiguration(): void
    {
        $l10nConfiguration = new L10nConfiguration();
        $l10nConfiguration->l10ncfg = [
            'uid' => 5,
            'title' => 'Config <script>',
            'depth' => 2,
            'tablelist' => 'tt_content',
            'exclude' => '',
            'include' => '',
        ];
        $subject = new L10nConfigurationDetailView($l10nConfiguration);

        $result = $subject->render();

        self::assertSame('Config &lt;script&gt; [5]', $result['header']);
        self::assertSame('2', $result['depth']);
    }

    #[Test]
    public function renderAddsASpaceAfterEveryCommaInAllFields(): void
    {
        $l10nConfiguration = new L10nConfiguration();
        $l10nConfiguration->l10ncfg = [
            'uid' => 5,
            'title' => 'My Config',
            'depth' => 2,
            'tablelist' => 'tt_content,pages,tx_news_domain_model_news',
            'exclude' => '1,2,3',
            'include' => '',
        ];
        $subject = new L10nConfigurationDetailView($l10nConfiguration);

        $result = $subject->render();

        self::assertSame('tt_content, pages, tx_news_domain_model_news', $result['tables']);
        self::assertSame('1, 2, 3', $result['exclude']);
    }

    #[Test]
    public function renderDoubleSpacesAFieldThatAlreadyHadACommaFollowedBySpace(): void
    {
        // Characterization of a real, currently-live quirk (found while writing this test, not
        // introduced by it - not fixed here, out of scope for a coverage pass): render() blindly
        // str_replace(',', ', ', ...)'s every field, including the header (built from the title).
        // A title that already contains ", " (comma already followed by a space) ends up with a
        // doubled space, because the original space right after the comma is untouched - only the
        // comma itself gets a new space inserted before it.
        $l10nConfiguration = new L10nConfiguration();
        $l10nConfiguration->l10ncfg = [
            'uid' => 5,
            'title' => 'Acme Inc, Ltd',
            'depth' => 0,
            'tablelist' => '',
            'exclude' => '',
            'include' => '',
        ];
        $subject = new L10nConfigurationDetailView($l10nConfiguration);

        $result = $subject->render();

        self::assertSame('Acme Inc,  Ltd [5]', $result['header']);
    }
}
