<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Command;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the  GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Localizationteam\L10nmgr\Model\Dto\EmConfiguration;
use Localizationteam\L10nmgr\Traits\BackendUserTrait;
use Localizationteam\L10nmgr\Traits\LanguageServiceTrait;
use Symfony\Component\Console\Command\Command;

/**
 * Class L10nCommand
 */
class L10nCommand extends Command
{
    use BackendUserTrait;
    use LanguageServiceTrait;

    public function __construct(protected readonly EmConfiguration $emConfiguration)
    {
        parent::__construct();
    }
}
