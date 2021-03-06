<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInita4a57066b1cdb5c2cf1c76d702d348cd
{
    public static $fallbackDirsPsr4 = array (
        0 => __DIR__ . '/..' . '/lyracom/rest-php-sdk/src',
    );

    public static $prefixesPsr0 = array (
        'T' => 
        array (
            'Thelia\\Composer' => 
            array (
                0 => __DIR__ . '/..' . '/thelia/installer/src',
            ),
        ),
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->fallbackDirsPsr4 = ComposerStaticInita4a57066b1cdb5c2cf1c76d702d348cd::$fallbackDirsPsr4;
            $loader->prefixesPsr0 = ComposerStaticInita4a57066b1cdb5c2cf1c76d702d348cd::$prefixesPsr0;

        }, null, ClassLoader::class);
    }
}
