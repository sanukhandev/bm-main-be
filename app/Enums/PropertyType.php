<?php

namespace App\Enums;

enum PropertyType: string
{
    case Apartment = 'apartment';
    case Villa = 'villa';
    case Shop = 'shop';
    case Office = 'office';
    case Space = 'space';
    case LaborCamp = 'labor_camp';
    case Warehouse = 'warehouse';
    case Land = 'land';
}
