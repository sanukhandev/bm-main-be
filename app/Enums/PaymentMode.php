<?php

namespace App\Enums;

enum PaymentMode: string
{
    case Cash = 'cash';
    case Cheque = 'cheque';
    case BankTransfer = 'bank_transfer';
}
