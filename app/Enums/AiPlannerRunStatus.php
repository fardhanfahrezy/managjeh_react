<?php

namespace App\Enums;

enum AiPlannerRunStatus: string
{
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';
}
