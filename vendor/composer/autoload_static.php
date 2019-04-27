<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit9042dda03b2c5b78aa2a92d82f443d22
{
    public static $classMap = array (
        'InvincibleBrands\\WcMfpc\\Admin' => __DIR__ . '/../..' . '/src/Admin.php',
        'InvincibleBrands\\WcMfpc\\Alert' => __DIR__ . '/../..' . '/src/Alert.php',
        'InvincibleBrands\\WcMfpc\\Config' => __DIR__ . '/../..' . '/src/Config.php',
        'InvincibleBrands\\WcMfpc\\Data' => __DIR__ . '/../..' . '/src/Data.php',
        'InvincibleBrands\\WcMfpc\\GlobalConfig' => __DIR__ . '/../..' . '/src/GlobalConfig.php',
        'InvincibleBrands\\WcMfpc\\Memcached' => __DIR__ . '/../..' . '/src/Memcached.php',
        'InvincibleBrands\\WcMfpc\\WcMfpc' => __DIR__ . '/../..' . '/src/WcMfpc.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->classMap = ComposerStaticInit9042dda03b2c5b78aa2a92d82f443d22::$classMap;

        }, null, ClassLoader::class);
    }
}
