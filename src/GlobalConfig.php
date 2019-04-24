<?php

namespace InvincibleBrands\WcMfpc;


if (! defined('ABSPATH')) { exit; }

/**
 * Class GlobalConfig
 *
 * @package InvincibleBrands\WcMfpc
 */
class GlobalConfig
{

    /**
     * @var array|Config[]
     */
    public $globalConfig = [];

    /**
     * @return array|Config[]
     */
    public function getGlobalConfig()
    {
        return $this->globalConfig;
    }

    /**
     * @param array|Config[] $globalConfig
     *
     * @return GlobalConfig
     */
    public function setGlobalConfig($globalConfig)
    {
        $this->globalConfig = $globalConfig;

        return $this;
    }

    /**
     * @param string $domain
     * @param Config $config
     *
     * @return GlobalConfig
     */
    public function addConfig($domain, $config)
    {
        $this->globalConfig[ $domain ] = $config;

        return $this;
    }

    /**
     * @param string $domain
     *
     * @return GlobalConfig
     */
    public function removeConfig($domain)
    {
        unset($this->globalConfig[ $domain ]);

        return $this;
    }

    /**
     * @return array $globalConfig   GlobalConfig::$globalConfig
     */
    public function getArray()
    {
        $return = [];

        /**
         * @var string $domain
         * @var Config $config
         */
        foreach ($this->globalConfig as $domain => $config) {

            $return[ $domain ] = $config->getConfig();

        }

        return $return;
    }

}