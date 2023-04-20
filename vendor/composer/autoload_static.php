<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit66f366203ca8fe8d58684609d341da58
{
    public static $prefixLengthsPsr4 = array (
        'N' => 
        array (
            'NextjsRevalidate\\' => 17,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'NextjsRevalidate\\' => 
        array (
            0 => __DIR__ . '/../..' . '/include',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
        'NextJsRevalidate\\Cron' => __DIR__ . '/../..' . '/include/Cron.php',
        'NextJsRevalidate\\I18n' => __DIR__ . '/../..' . '/include/I18n.php',
        'NextJsRevalidate\\Revalidate' => __DIR__ . '/../..' . '/include/Revalidate.php',
        'NextJsRevalidate\\Settings' => __DIR__ . '/../..' . '/include/Settings.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit66f366203ca8fe8d58684609d341da58::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit66f366203ca8fe8d58684609d341da58::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInit66f366203ca8fe8d58684609d341da58::$classMap;

        }, null, ClassLoader::class);
    }
}
