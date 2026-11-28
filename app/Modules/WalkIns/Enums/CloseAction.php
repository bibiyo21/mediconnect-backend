<?php

namespace App\Modules\WalkIns\Enums;

/**
 * What staff record for each live entry when a queue is closed (increment 5.8, D-F). CANCEL_WITH_NOTICE cancels the entry and sends the
 * generic notice (patients only: a guest has no channel). TOLD_IN_PERSON cancels it after staff have told the patient in person; no notice is sent.
 */
enum CloseAction: string
{
    case CancelWithNotice = 'CANCEL_WITH_NOTICE';
    case ToldInPerson = 'TOLD_IN_PERSON';
}
