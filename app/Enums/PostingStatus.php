<?php

namespace App\Enums;

enum PostingStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Void = 'void';
}
