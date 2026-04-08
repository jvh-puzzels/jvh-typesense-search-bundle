<?php

namespace JvH\JvHTypesenseSearchBundle\ContaoManager;

use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;

class Plugin implements BundlePluginInterface
{
    public function getBundles(ParserInterface $parser): array
    {
        return [
            BundleConfig::create('JvH\JvHTypesenseSearchBundle\JvHTypesenseSearchBundle')
                ->setLoadAfter(['Krabo\TypesenseSearchBundle\TypesenseSearchBundle'])
        ];
    }


}
