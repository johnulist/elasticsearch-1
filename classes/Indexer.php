<?php
/**
 * Copyright (C) 2017 thirty bees
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.md
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to contact@thirtybees.com so we can send you a copy immediately.
 *
 * @author    thirty bees <contact@thirtybees.com>
 * @copyright 2017 thirty bees
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

namespace ElasticsearchModule;

use Configuration;
use Elasticsearch;
use Exception;
use Language;
use Shop;

if (!defined('_TB_VERSION_')) {
    return;
}

/**
 * Class Indexer
 *
 * @package ElasticsearchModule
 */
class Indexer
{
    /** @var Fetcher $fetcher */
    protected $fetcher;

    /**
     * Indexer constructor.
     */
    public function __construct()
    {
        $this->fetcher = new Fetcher();
    }

    /**
     * @param \Product $product
     */
    public function indexProduct($product)
    {
        if (!$product->active == false) {
            return;
        }

        foreach (\Language::getLanguages() as $language) {
            $object = $this->fetcher->initProduct($product->id, $language);
        }
    }

    private function getCategories($idLang)
    {
        $cats = \Category::getNestedCategories(null, $idLang);

        $results = [];
        $this->getNestedCats($cats, [], $results, $idLang);

        $results = array_map(
            function ($cat) {
                $newCategory = $cat[count($cat) - 1];
                $path = $cat;
                array_pop($path);

                $path[] = $newCategory['name'];
                $newCategory['path'] = implode(' / ', $path);

                return $newCategory;
            },
            $results
        );

        return $results;
    }

    /**
     * Get nested categories
     *
     * @param $cats
     * @param $names
     * @param $results
     * @param $idLang
     */
    protected function getNestedCats($cats, $names, &$results, $idLang)
    {
        foreach ($cats as $cat) {
            if (isset($cat['children']) && is_array($cat['children']) && count($cat['children']) > 0) {
                if ($cat['is_root_category'] == 0) {
                    $names[] = $cat['name'];
                }

                $this->getNestedCats($cat['children'], $names, $results, $idLang);
            } else {
                if ($cat['is_root_category'] == 0) {
                    $category = new \Category($cat['id_category']);
                    $productCount = $category->getProducts($idLang, 1, 10000, null, null, true);
                    $link = new \Link();
                    $link = $link->getCategoryLink($cat['id_category'], null, $idLang);

                    $names[] = ['name' => $cat['name'], 'objectID' => $cat['id_category'], 'product_count' => $productCount, 'url' => $link];
                }

                $results[] = $names;
                array_pop($names);
            }
        }
    }

    /**
     * Erase Elasticsearch indices
     *
     * @param int[]|null $idLangs
     * @param int[]|null $idShops
     */
    public static function eraseIndices($idLangs = null, $idShops = null)
    {
        $indexPrefix = Configuration::get(Elasticsearch::INDEX_PREFIX);

        if (!is_array($idLangs) || empty($idLangs)) {
            $idLangs = Language::getLanguages(false, false, true);
        }
        if (!is_array($idShops) || empty($idShops)) {
            $idShops = Shop::getShops(false, null, true);
        }

        // Delete the indices first
        $client = Elasticsearch::getWriteClient();
        if (!$client) {
            return;
        }
        foreach ($idShops as $idShop) {
            foreach ($idLangs as $idLang) {
                try {
                    $client->indices()->delete(['index' => "{$indexPrefix}_{$idShop}_{$idLang}"]);
                } catch (Exception $e) {
                    $error = json_decode($e->getMessage());
                    if (isset($error->error->status)) {
                        if ((int) substr($error->error->status, 0, 1) !== 4) {
                            die($e->getMessage());
                        }
                    }
                }
            }
        }
    }

    /**
     * Create and push Elasticsearch mappings
     *
     * @param int[]|null $idLangs
     * @param int[]|null $idShops
     */
    public static function createMappings($idLangs = null, $idShops = null)
    {
        $indexPrefix = Configuration::get(Elasticsearch::INDEX_PREFIX);

        if (!is_array($idLangs) || empty($idLangs)) {
            $idLangs = Language::getLanguages(false, false, true);
        }
        if (!is_array($idShops) || empty($idShops)) {
            $idShops = Shop::getShops(false, null, true);
        }


        // Gather the properties and build the mappings
        $searchableMetas = current(Meta::getAllMetas());
        $properties = [];
        foreach ($searchableMetas as $meta) {
            // Searchable fields can have both text and keyword fields
            if (substr($meta['code'], -11) === '_color_code') {
                $properties[$meta['code']] = [
                    'type' => 'keyword',
                ];
            } else {
                $properties[$meta['code']] = [
                    'type' => $meta['elastic_type'],
                ];
            }

            // Filterable fields for facets require keyword fields instead of text fields
            // We turn them all into keywords, because they will have to become part of the friendly URL
            if (in_array($meta['elastic_type'], ['string', 'text'])) {
                $properties["{$meta['code']}_agg"] = [
                    'type' => 'keyword',
                ];
            } else {
                $properties["{$meta['code']}_agg"] = [
                    'type' => $meta['elastic_type'],
                ];
            }

            if ((int) $meta['display_type'] === Meta::DISPLAY_TYPE_COLORS) {
                $properties["{$meta['code']}_color_code"] = [
                    'type' => 'keyword',
                ];
            }

            // Force MySQL DATETIME format for dates, we can always check if there's a demand for other types
            if ($meta['elastic_type'] === 'date') {
                $properties[$meta['code']]['format'] = 'yyyy-MM-dd HH:mm:ss';
                $properties["{$meta['code']}_agg"]['format'] = 'yyyy-MM-dd HH:mm:ss';
            }
        }

        // Push the mappings to Elasticsearch
        $client = Elasticsearch::getWriteClient();
        if (!$client) {
            return;
        }
        foreach ($idShops as $idShop) {
            foreach ($idLangs as $idLang) {
                $params = [
                    'index' => "{$indexPrefix}_{$idShop}_{$idLang}",
                    'body'  => [
                        'settings' => [
                            'number_of_shards'   => (int) Configuration::get(Elasticsearch::SHARDS),
                            'number_of_replicas' => (int) Configuration::get(Elasticsearch::REPLICAS),
                        ],
                        'mappings' => [
                            'product' => [
                                '_source'    => [
                                    'enabled' => true,
                                ],
                                'properties' => $properties,
                            ],
                        ],
                    ],
                ];

                try {
                    // Create the index with mappings and settings
                    $client->indices()->create($params);
                } catch (Exception $e) {
                }
            }
        }
    }
}
