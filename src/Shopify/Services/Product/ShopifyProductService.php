<?php

namespace Tcg\Common\Shopify\Services\Product;

use App\Models\ProductDimension;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Shopify\Clients\Graphql;
use Shopify\Exception\MissingArgumentException;

class ShopifyProductService
{
    /**
     * @param $session
     * @param $shop
     *
     * @return array
     */
    public static function getAllProducts($session, $shop): array
    {
        $products    = [];
        $hasNextPage = true;
        $endCursor   = null;

        $client = new Graphql($shop, $session->getAccessToken());

        while ($hasNextPage) {
            try {
                // Build GraphQL query
                $query = <<<'GRAPHQL'
                query getProducts($cursor: String, $variantCursor: String) {
                    products(first: 100, after: $cursor) {
                        edges {
                            node {
                                id
                                title
                                handle
                                variants(first: 100, after: $variantCursor) {
                                    edges {
                                        node {
                                            id
                                            title
                                            price
                                            sku
                                            inventoryItem {
                                                measurement {
                                                  weight {
                                                    value
                                                    unit
                                                  }
                                                }
                                            }
                                        }
                                    }
                                    pageInfo {
                                        hasNextPage
                                        endCursor
                                    }
                                }
                            }
                        }
                        pageInfo {
                            hasNextPage
                            endCursor
                        }
                    }
                }
                GRAPHQL;

                // Send request using Shopify GraphQL client
                $response = $client->query([
                    'query' => $query,
                    'variables' => [
                        'cursor' => $endCursor
                    ]
                ]);

                $body = $response->getDecodedBody();

                // Handle pagination
                $productEdges = $body['data']['products']['edges'] ?? [];
                foreach ($productEdges as $edge) {
                    $products[] = $edge['node'];
                }

                $pageInfo    = $body['data']['products']['pageInfo'] ?? [];
                $hasNextPage = $pageInfo['hasNextPage'] ?? false;
                $endCursor   = $pageInfo['endCursor'] ?? null;
            } catch (Exception $e) {
                Log::error('Error fetching products via GraphQL: ' . $e->getMessage(), [
                    'shop' => $shop,
                    'endCursor' => $endCursor
                ]);
                break;
            }
        }

        return $products;
    }

    /**
     * @param $products
     * @param $shop
     *
     * @return void
     */
    public static function saveProductDimensions($products, $shop): void
    {
        // Get existing dims keyed by "productId-variantId" for fast lookup
        $existingDimensions = ProductDimension::where('shop', $shop)
                                              ->get()
                                              ->keyBy(function ($item) {
                                                  return $item->product_id . '-' . $item->variant_id;
                                              });

        $existingKeys    = $existingDimensions->keys()->toArray();
        $newKeys         = [];
        $recordsToInsert = [];
        $recordsToUpdate = [];

        foreach ($products as $product) {
            if (!isset($product['variants']['edges']) || !is_array($product['variants']['edges'])) {
                Log::error("Missing 'variants.edges' structure in product data.", ['product_data' => $product]);
                continue;
            }

            foreach ($product['variants']['edges'] as $edge) {
                $variant   = $edge['node'] ?? [];
                $productId = $product['id'] ? self::extractId($product['id']) : null;
                $variantId = $variant['id'] ? self::extractId($variant['id']) : null;

                if (!$productId || !$variantId) {
                    Log::error("Product ID or Variant ID is missing.", ['product_data' => $product]);
                    continue;
                }

                $key       = $productId . '-' . $variantId;
                $newKeys[] = $key;

                // Convert weight to grams if incoming unit is kilograms
                $weight = $variant['inventoryItem']['measurement']['weight'] ?? null;
                $grams  = null;
                if (is_array($weight) && isset($weight['value'])) {
                    $grams = $weight['value'];
                    if (($weight['unit'] ?? '') === 'KILOGRAMS') {
                        $grams = $grams * 1000;
                    }
                }

                $data = [
                    'shop'          => $shop,
                    'product_id'    => $productId,
                    'variant_id'    => $variantId,
                    'product_title' => $product['title'] ?? '',
                    'variant_title' => $variant['title'] ?? '',
                    'sku'           => $variant['sku'] ?? '',
                ];

                // Only set grams when we actually have a value
                if ($grams !== null) {
                    $data['grams'] = $grams;
                }

                // Check if record exists
                if ($existingDimensions->has($key)) {
                    // Prepare for batch update - only include fields we want to update
                    $existingRecord = $existingDimensions->get($key);
                    $updateData     = [];

                    // Only add fields that have changed to minimize updates
                    if ($existingRecord->product_title !== $data['product_title']) {
                        $updateData['product_title'] = $data['product_title'];
                    }
                    if ($existingRecord->variant_title !== $data['variant_title']) {
                        $updateData['variant_title'] = $data['variant_title'];
                    }
                    if ($existingRecord->sku !== $data['sku']) {
                        $updateData['sku'] = $data['sku'];
                    }
                    if (isset($data['grams']) && $existingRecord->grams != $data['grams']) {
                        $updateData['grams'] = $data['grams'];
                    }

                    if (!empty($updateData)) {
                        $updateData['updated_at']             = now();
                        $recordsToUpdate[$existingRecord->id] = $updateData;
                    }
                } else {
                    // Prepare for batch insert
                    $data['created_at'] = now();
                    $data['updated_at'] = now();
                    $recordsToInsert[]  = $data;
                }
            }
        }

        // Batch insert new records
        if (!empty($recordsToInsert)) {
            ProductDimension::insert($recordsToInsert);
        }

        // Batch update existing records
        if (!empty($recordsToUpdate)) {
            foreach ($recordsToUpdate as $id => $updateData) {
                DB::table('product_dimensions')
                  ->where('id', $id)
                  ->update($updateData);
            }
        }

        // Delete dimensions that are no longer needed - use more efficient approach
        $keysToDelete = array_diff($existingKeys, $newKeys);
        if (!empty($keysToDelete)) {
            $idsToDelete = [];
            foreach ($keysToDelete as $key) {
                [$productId, $variantId] = explode('-', $key);
                $record = $existingDimensions->get($key);
                if ($record) {
                    $idsToDelete[] = $record->id;
                }
            }

            if (!empty($idsToDelete)) {
                ProductDimension::whereIn('id', $idsToDelete)->delete();
            }
        }

        // Remove duplicate records more efficiently
        $duplicateKeys = array_intersect($existingKeys, $newKeys);
        if (!empty($duplicateKeys)) {
            $productVariantPairs = [];
            foreach ($duplicateKeys as $key) {
                [$productId, $variantId] = explode('-', $key);
                $productVariantPairs[] = ['product_id' => $productId, 'variant_id' => $variantId];
            }

            // Find all duplicates in a single query
            $allDuplicates = ProductDimension::where('shop', $shop)
                                             ->where(function ($query) use ($productVariantPairs) {
                                                 foreach ($productVariantPairs as $pair) {
                                                     $query->orWhere(function ($subQuery) use ($pair) {
                                                         $subQuery->where('product_id', $pair['product_id'])
                                                                  ->where('variant_id', $pair['variant_id']);
                                                     });
                                                 }
                                             })
                                             ->orderBy('product_id')
                                             ->orderBy('variant_id')
                                             ->orderByDesc('updated_at')
                                             ->get();

            // Group by product_id-variant_id and keep only the most recent
            $grouped = $allDuplicates->groupBy(function ($item) {
                return $item->product_id . '-' . $item->variant_id;
            });

            $idsToDelete = [];
            foreach ($grouped as $group) {
                if ($group->count() > 1) {
                    // Skip the first (most recent) and mark the rest for deletion
                    $idsToDelete = array_merge($idsToDelete, $group->slice(1)->pluck('id')->toArray());
                }
            }

            if (!empty($idsToDelete)) {
                ProductDimension::whereIn('id', $idsToDelete)->delete();
            }
        }
    }

    /**
     * @param $gid
     *
     * @return string
     */
    public static function extractId($gid): string
    {
        preg_match('/\d+/', $gid, $matches);

        return $matches[0];
    }

    /**
     * @throws MissingArgumentException
     * @throws \JsonException
     */
    public static function getVariantsByIds($session, array $variantIds): array
    {
        if (empty($variantIds)) {
            return [];
        }

        $client = new Graphql($session->getShop(), $session->getAccessToken());

        // Shopify GraphQL requires IDs in global ID (gid) format
        $globalVariantIds = array_map(
            fn($id) => "gid://shopify/ProductVariant/{$id}",
            $variantIds
        );

        $query = <<<'QUERY'
        query getVariantsByIds($ids: [ID!]!) {
            nodes(ids: $ids) {
                ... on ProductVariant {
                    id
                    title
                    sku
                    price
                    inventoryQuantity
                    product {
                        id
                        title
                    }
                }
            }
        }
        QUERY;

        try {
            $response = $client->query([
                'query' => $query,
                'variables' => ['ids' => $globalVariantIds],
            ]);

            $decoded = $response->getDecodedBody();

            // Return clean array of variants
            return $decoded['data']['nodes'] ?? [];
        } catch (\Exception $e) {
            logger()->error('Shopify getVariantsByIds error: ' . $e->getMessage());
            return [];
        }
    }
}