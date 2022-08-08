<?php

namespace Organik\ImageResizer\Classes;

class Activator
{
    public static function activate()
    {
        flush_rewrite_rules();
    }

    public static function deactivate()
    {
        flush_rewrite_rules();
    }
}
