<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\LanguagesService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class LanguagesServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['scheduler'];

    protected array $testExtensionsToLoad = ['localizationteam/l10nmgr'];

    protected function tearDown(): void
    {
        // The functional test instance directory (including config/sites/) is reused across all
        // test methods in this class - remove what this test wrote so the next method starts clean.
        $sitesPath = Environment::getConfigPath() . '/sites/';
        if (is_dir($sitesPath)) {
            GeneralUtility::rmdir($sitesPath, true);
        }
        $this->get(CacheManager::class)->getCache('core')->remove('sites-configuration');
        parent::tearDown();
    }

    private function writeSiteConfiguration(string $identifier, string $yaml): void
    {
        $siteConfigPath = Environment::getConfigPath() . '/sites/' . $identifier . '/';
        GeneralUtility::mkdir_deep($siteConfigPath);
        GeneralUtility::writeFile($siteConfigPath . 'config.yaml', $yaml);
        // SiteConfiguration caches resolved sites in the persistent 'core' cache, which (unlike the
        // in-memory runtime cache) survives across test methods sharing this instance - must be
        // cleared after writing so the next getAll() call actually sees the fresh file.
        $this->get(CacheManager::class)->getCache('core')->remove('sites-configuration');
    }

    #[Test]
    public function getAllReturnsAllLanguagesFromASingleSite(): void
    {
        $this->writeSiteConfiguration('test-site', <<<'YAML'
rootPageId: 1
base: 'https://example.com/'
languages:
  0:
    title: English
    enabled: true
    languageId: 0
    base: '/'
    typo3Language: default
    locale: en_US.UTF-8
    iso-639-1: en
    navigationTitle: English
    hreflang: en-US
    direction: ltr
    flag: us
  1:
    title: German
    enabled: true
    languageId: 1
    base: '/de/'
    typo3Language: de
    locale: de_DE.UTF-8
    iso-639-1: de
    navigationTitle: Deutsch
    hreflang: de-DE
    direction: ltr
    flag: de
YAML);

        $subject = new LanguagesService();
        $languages = $subject->getAll();

        self::assertCount(2, $languages);
        self::assertStringContainsString('English', $languages[0]['label']);
        self::assertStringContainsString('German', $languages[1]['label']);
    }

    #[Test]
    public function getAllMergesLabelsWhenTheSameLanguageIdIsProvidedByMultipleSites(): void
    {
        $this->writeSiteConfiguration('site-a', <<<'YAML'
rootPageId: 1
base: 'https://site-a.example.com/'
languages:
  0:
    title: English (A)
    enabled: true
    languageId: 0
    base: '/'
    typo3Language: default
    locale: en_US.UTF-8
    iso-639-1: en
    navigationTitle: English
    hreflang: en-US
    direction: ltr
    flag: us
YAML);
        $this->writeSiteConfiguration('site-b', <<<'YAML'
rootPageId: 2
base: 'https://site-b.example.com/'
languages:
  0:
    title: English (B)
    enabled: true
    languageId: 0
    base: '/'
    typo3Language: default
    locale: en_US.UTF-8
    iso-639-1: en
    navigationTitle: English
    hreflang: en-US
    direction: ltr
    flag: us
YAML);

        $subject = new LanguagesService();
        $languages = $subject->getAll();

        self::assertCount(1, $languages);
        self::assertStringContainsString('English (A)', $languages[0]['label']);
        self::assertStringContainsString('English (B)', $languages[0]['label']);
        self::assertStringContainsString('Site: site-a', $languages[0]['label']);
        self::assertStringContainsString('Site: site-b', $languages[0]['label']);
    }

    #[Test]
    public function getAllReturnsEmptyArrayWhenNoSiteIsConfigured(): void
    {
        $subject = new LanguagesService();

        self::assertSame([], $subject->getAll());
    }
}
