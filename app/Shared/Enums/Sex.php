<?php

namespace App\Shared\Enums;

/**
 * Sex where clinically required (§4.3). Values follow the HL7 administrative
 * gender set. Optional everywhere it is collected.
 */
enum Sex: string
{
    case Female = 'FEMALE';
    case Male = 'MALE';
    case Other = 'OTHER';
    case Unknown = 'UNKNOWN';
}
