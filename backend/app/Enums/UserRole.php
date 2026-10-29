<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Salesman = 'salesman';
    case Vendor = 'vendor';
    case Customer = 'customer';
}
