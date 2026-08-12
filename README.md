[![Latest Stable Version](https://poser.pugx.org/localizationteam/l10nmgr/v/stable)](https://extensions.typo3.org/extension/l10nmgr/)
[![TYPO3 11](https://img.shields.io/badge/TYPO3-11-orange.svg?style=flat-square)](https://get.typo3.org/version/11)
[![TYPO3 12](https://img.shields.io/badge/TYPO3-12-orange.svg?style=flat-square)](https://get.typo3.org/version/12)
[![TYPO3 13 Priority Access](https://img.shields.io/badge/TYPO3-13%20Priority%20Access-blue.svg?style=flat-square)](https://coders.care/for/crowdfunding/l10nmgr-and-localizer)
[![Total Downloads](https://poser.pugx.org/localizationteam/l10nmgr/d/total)](https://packagist.org/packages/localizationteam/l10nmgr)
[![Monthly Downloads](https://poser.pugx.org/localizationteam/l10nmgr/d/monthly)](https://packagist.org/packages/localizationteam/l10nmgr)

# TYPO3 extension `l10nmgr`

The Localization Manager (l10nmgr) is a localization management extension for TYPO3 supporting a variety of
online and offline translation workflows: bulk export of pages and content into CAT XML or MS Excel, translation
with any state-of-the-art CAT tool, automatic re-import, and CLI-driven automation with FTP/email delivery for
language service provider workflows.

Get it from the [TER](https://extensions.typo3.org/extension/l10nmgr) or with
`composer require localizationteam/l10nmgr`. L10nmgr 13 for TYPO3 12/13 is available through
[Priority Access](https://coders.care/for/crowdfunding/l10nmgr-and-localizer).

## Version matrix

| L10nmgr | TYPO3      | PHP           | Status | Availability     |
|---------|------------|---------------|--------|-------------------|
| 13      | 12.4 / 13.4 LTS | 8.3+     | Stable | Priority Access  |
| 12      | 11.5 / 12.4 LTS | 8.1+     | Stable | TER              |

|                  | URL                                                            |
|------------------|-----------------------------------------------------------------|
| **Read online:** | https://docs.typo3.org/p/localizationteam/l10nmgr/main/en-us/  |
| **TER:**         | https://extensions.typo3.org/extension/l10nmgr                 |

<!-- Markdown link & img dfn's -->
[coders.care-url]: https://coders.care/for/crowdfunding/l10nmgr-and-localizer
[patreon-url]: https://www.patreon.com/cybercraft
[paypal-url]: https://www.paypal.me/cybercraftsponsoring/150
[amazon-url]: https://www.amazon.de/gp/registry/wishlist/2I80GX9ZSMYXX

## How L10nmgr is funded

L10nmgr is licensed under the GPL, and every version becomes publicly available in the TER. Development,
maintenance, testing and support for new TYPO3 releases are paid work.

That work is funded by the people who use L10nmgr commercially. If it is part of how you deliver projects,
funding it is what keeps it current.

### Priority Access and the two-stage release

Every L10nmgr version is released in two stages. It first goes to Priority Access, where sponsors fund the
development and work with the current version on the current TYPO3 release straight away. After the next TYPO3
LTS version has been shipped, that same version becomes publicly available for free in the TER.

Priority Access is therefore not a paywall. It funds the version that the whole community receives later, and
it keeps L10nmgr aligned with every new TYPO3 LTS release.

### Ways to contribute

| | |
|:--|:--|
| [Priority Access][coders.care-url] | Fund development directly and work with the current version for the current TYPO3 release. |
| [Patreon][patreon-url] | Monthly support. Depending on the tier, this includes a mention in the release notes and the option to put a feature request on the roadmap. |
| [PayPal][paypal-url] | One-off contributions of any amount. |

Beyond that, Joey and Petra appreciate a good single malt. [Wishlist][amazon-url]. Slàinte mhath!

## Makefile

The extension comes with a Makefile to provide a unified interface for some developer related tasks.

Run `make` without any parameters to get the help which shows all available tasks:

```
$ make
 help                          List available tasks on this project
 lint                          Lints all PHP files of the project
 fix                           Adjust the code to the CGL via PHP-CS-Fixer
 stan                          Run PHPStan on the files
 stan-baseline                 Creates a new PHPStan baseline
 docs_render                   Render the documentation
 docs_serve                    Serve the rendered documentation
```
