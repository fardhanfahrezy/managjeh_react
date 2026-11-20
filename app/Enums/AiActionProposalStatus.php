<?php

namespace App\Enums;

enum AiActionProposalStatus: string
{
    case Pending = 'pending';
    case Executed = 'executed';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Expired = 'expired';
}
