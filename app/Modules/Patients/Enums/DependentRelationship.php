<?php

namespace App\Modules\Patients\Enums;

/** How the dependent is related to the guardian (from the guardian's side: "my CHILD"). */
enum DependentRelationship: string
{
    case Child = 'CHILD';
    case Parent = 'PARENT';
    case Spouse = 'SPOUSE';
    case Sibling = 'SIBLING';
    case Grandparent = 'GRANDPARENT';
    case Grandchild = 'GRANDCHILD';
    case LegalWard = 'LEGAL_WARD';
    case Other = 'OTHER';
}
