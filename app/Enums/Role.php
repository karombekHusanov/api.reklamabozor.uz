<?php

namespace App\Enums;

enum Role: string
{
    case Client = 'client';
    case Agent = 'agent';
    case Designer = 'designer';
    case Admin = 'admin';
    case Seller = 'seller';
}
