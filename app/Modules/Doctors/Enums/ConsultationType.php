<?php

namespace App\Modules\Doctors\Enums;

enum ConsultationType: string
{
    case Online = 'ONLINE';
    case InPerson = 'IN_PERSON';
}
