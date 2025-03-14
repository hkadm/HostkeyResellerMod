<?php

namespace WHMCS\Module\Addon\Hostkeyresellermod;

class HostkeyResellerModCounter
{

    private static $groups = [];
    private static $products = [];

    public static function getGroups(): array
    {
        return self::$groups;
    }

    public static function getProducts(): array
    {
        return self::$products;
    }

    public static function addGroup($group)
    {
        self::$groups[] = $group;
    }

    public static function addProduct($product)
    {
        self::$products[] = $product;
    }
}
