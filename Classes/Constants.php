<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr;

/**
 * Constants for the L10nmgr
 */
class Constants
{
    public const int L10NMGR_CONFIGURATION_DEFAULT = 0;

    public const int L10NMGR_CONFIGURATION_NONE = 1;

    public const int L10NMGR_CONFIGURATION_EXCLUDE = 2;

    public const int L10NMGR_CONFIGURATION_INCLUDE = 3;

    public const string  L10NMGR_LANGUAGE_RESTRICTION_FIELDNAME = 'l10nmgr_language_restriction';

    public const string TABLE_SYS_LANGUAGE = 'sys_language';
}
