<?php

namespace Tcg\Common\Packing\Controllers;

use App\Http\Controllers\Controller;
use App\Models\TcgService;
use Illuminate\Http\Request;
use Tcg\Common\Packing\Controllers\CommonPackingController;

class ShipLogicParcelController
{
    private array $boxes;

    private const DEFAULT_DIMENSION = 1;
    private const DEFAULT_MASS      = 0.1;
    private array $fittingItems;
    private int $j;
    public CommonPackingController $commonPackingController;

    /**
     * @param array $boxes
     */
    public function __construct(array $boxes)
    {
        $this->boxes = $boxes;
        // Unset the unused boxes, sort dimensions
        foreach ($this->boxes as $key => $box) {
            $length    = $box['length'] ?? 0.0;
            $width     = $box['width'] ?? 0.0;
            $height    = $box['height'] ?? 0.0;
            $boxValues = [$length, $width, $height];
            rsort($boxValues);
            foreach (['length', 'width', 'height'] as $index => $boxKey) {
                $this->boxes[$key][$boxKey] = $boxValues[$index];
            }
            if ((int)$length === 0 || (int)$width === 0 || (int)$height === 0) {
                unset($this->boxes[$key]);
            }
        }
        $this->j = 0;

        $this->commonPackingController = new CommonPackingController($this->boxes);
    }

    /**
     * Parcel up single items
     * They either fit into a box, or have their own dimensions
     *
     * @param array $items
     *
     * @return array
     */
    public function packSingleItems(array $items): array
    {
        $parcels = [];
        if (empty($items)) {
            return $parcels;
        }
        foreach ($items as $item) {
            $fitsIndex = $this->commonPackingController->getFitsIndex($item);

            // If it fits in a box pack into the box
            if ($fitsIndex !== null) {
                for ($i = 0; $i < $item->quantity; $i++) {
                    $parcels[] = [
                        'mass'   => $item->dimension['mass'] ?? 0.1, // default to 0.1 kg
                        'value'  => $item->price,
                        'length' => $this->boxes[$fitsIndex]['dimension']['length'],
                        'width'  => $this->boxes[$fitsIndex]['dimension']['width'],
                        'height' => $this->boxes[$fitsIndex]['dimension']['height'],
                    ];
                }
            } else {
                // pack as an individual parcel
                for ($i = 0; $i < $item->quantity; $i++) {
                    $parcels[] = [
                        'mass'        => $item->dimension['mass'] ?? 0.1,
                        'value'       => $item->price,
                        'length'      => (float)($item->dimension['length'] > 0.0 ? $item->dimension['length'] : 1.0),
                        'width'       => (float)($item->dimension['width'] > 0.0 ? $item->dimension['width'] : 1.0),
                        'height'      => (float)($item->dimension['height'] > 0.0 ? $item->dimension['height'] : 1.0),
                        'description' => $item->description,
                    ];
                }
            }
        }

        return $parcels;
    }

    /**
     * Parcel up too-heavy items
     * They don't fit in boxes so have their own dimensions
     *
     * @param array $items
     *
     * @return array
     */
    public function packTooHeavyItems(array $items): array
    {
        $parcels = [];
        if (empty($items)) {
            return $parcels;
        }
        foreach ($items as $item) {
            // pack as an individual parcel
            for ($i = 0; $i < $item->quantity; $i++) {
                $parcels[] = [
                    'mass'        => $item->dimension['mass'] ?? 0.1,
                    'value'       => $item->price,
                    'length'      => (float)($item->dimension['length'] > 0.0 ? $item->dimension['length'] : 1.0),
                    'width'       => (float)($item->dimension['width'] > 0.0 ? $item->dimension['width'] : 1.0),
                    'height'      => (float)($item->dimension['height'] > 0.0 ? $item->dimension['height'] : 1.0),
                    'description' => $item->description,
                ];
            }
        }

        return $parcels;
    }

    /**
     * Parcel up too-biig items
     * They don't fit in boxes so have their own dimensions
     *
     * @param array $items
     *
     * @return array
     */
    public function packTooBigItems(array $items): array
    {
        $parcels = [];
        if (empty($items)) {
            return $parcels;
        }
        foreach ($items as $item) {
            // pack as an individual parcel
            for ($i = 0; $i < $item->quantity; $i++) {
                $parcels[] = [
                    'mass'        => $item->dimension['mass'] ?? 0.1,
                    'value'       => $item->price,
                    'length'      => (float)($item->dimension['length'] > 0.0 ? $item->dimension['length'] : 1.0),
                    'width'       => (float)($item->dimension['width'] > 0.0 ? $item->dimension['width'] : 1.0),
                    'height'      => (float)($item->dimension['height'] > 0.0 ? $item->dimension['height'] : 1.0),
                    'description' => $item->description,
                ];
            }
        }

        return $parcels;
    }

    public function packContainers(array $containers, array $fittingItems): array
    {
        $packedContainers = [];

        foreach ($containers as $container) {
            if (empty($fittingItems)) {
                $packedContainers[] = unserialize(serialize($container));
                $fittingItems       = unserialize(serialize($fittingItems));
            } else {
                $containerDimension = $container->dimension;
                unset($containerDimension['mass']);
                unset($containerDimension['volume']);
                $containerDimension = array_values($containerDimension);
                rsort($containerDimension);
                $containerDimension['volume'] = $containerDimension[0] * $containerDimension[1] * $containerDimension[2];
                // Now we need to try and fit the other items into the container
                $containerPackingController = new CommonPackingController([$container->dimension]);
                [$packedContainer, $fittingItems] = $containerPackingController->calculateMultiFittingItems(
                    $fittingItems,
                    true,
                    $container
                );
                $packedContainers[] = unserialize(serialize($packedContainer));
                $fittingItems       = unserialize(serialize($fittingItems));
            }
        }


        return [$packedContainers, $fittingItems];
    }

    /**
     * @param $massBased
     * @param float $totalMass
     * @param int $totalValue
     * @param array $parcels
     *
     * @return array
     */
    public function getMassBased($massBased): array
    {
        if (empty($massBased)) {
            return [];
        }

        return $this->fitMassItemsInBoxes($massBased);
    }

    private function fitMassItemsInBoxes($massBased): array
    {
        $parcels = [];

        foreach ($this->boxes as $boxIndex => $box) {
            $boxMaxWeight = $box['max_weight'];
            if ($boxMaxWeight <= 0) {
                continue;
            }
            $parcel             = [
                'mass'   => 0.00,
                'value'  => 0.00,
                'length' => $box['dimension']['length'] ?? $box['length'],
                'width'  => $box['dimension']['width'] ?? $box['width'],
                'height' => $box['dimension']['height'] ?? $box['height'],
            ];
            $boxAddedWeight     = 0.0;
            $boxAvailableWeight = $boxMaxWeight - $boxAddedWeight;
            $unplacedItems      = array_reduce($massBased, function ($carry, $item) {
                return $carry + $item->quantity;
            }, 0);
            while ($unplacedItems > 0) {
                if ($boxAvailableWeight <= 0) {
                    $parcel = [
                        'mass'   => 0.00,
                        'value'  => 0.00,
                        'length' => $box['dimension']['length'] ?? $box['length'],
                        'width'  => $box['dimension']['width'] ?? $box['width'],
                        'height' => $box['dimension']['height'] ?? $box['height'],
                    ];
                }
                foreach ($massBased as $item) {
                    $remainingItems = $item->quantity;
                    // Mass-based items include those with no dimension data at all - default mass
                    $itemMass = $item->dimension['mass'] > 0 ? $item->dimension['mass'] : 0.1;
                    if ($itemMass <= 0) {
                        $unplacedItems--;
                        continue;
                    }
                    while ($remainingItems > 0 && $unplacedItems > 0) {
                        $maxItems = (int)floor($boxAvailableWeight / $itemMass);
                        if ($maxItems <= 0) {
                            $parcels[$boxIndex][] = $parcel;
                            $parcel               = [
                                'mass'   => 0.00,
                                'value'  => 0.00,
                                'length' => $box['dimension']['length'] ?? $box['length'],
                                'width'  => $box['dimension']['width'] ?? $box['width'],
                                'height' => $box['dimension']['height'] ?? $box['height'],
                            ];
                            $boxAvailableWeight   = $boxMaxWeight;
                            $maxItems             = (int)floor($boxAvailableWeight / $itemMass);
                        }
                        $addedItems      = min($remainingItems, $maxItems);
                        $parcel['mass']  += $addedItems * $itemMass;
                        $parcel['value'] += $addedItems * $item->price;

                        $remainingItems     -= $addedItems;
                        $unplacedItems      -= $addedItems;
                        $boxAvailableWeight -= $addedItems * $itemMass;
                        if ($unplacedItems === 0) {
                            $parcels[$boxIndex][] = $parcel;
                        }
                    }
                }
            }
        }

        uasort($parcels, function ($a, $b) {
            return count($a) <=> count($b); // Sort in descending order of parcel count
        });
        $keys         = array_keys($parcels);
        $values       = array_values($parcels);
        $finalParcels = [];
        $selectedKey  = $keys[0];
        if ($selectedKey !== 0) {
            foreach ($values[0] as $value) {
                for ($i = 0; $i < $selectedKey; $i++) {
                    if ($this->boxes[$i]['max_weight'] >= $value['mass']) {
                        $value['length'] = $this->boxes[$i]['dimension']['length'];
                        $value['width']  = $this->boxes[$i]['dimension']['width'];
                        $value['height'] = $this->boxes[$i]['dimension']['height'];
                        $finalParcels[]  = $value;
                        break;
                    } else {
                        $finalParcels[] = $value;
                    }
                }
            }
        } else {
            $finalParcels = $values[0];
        }

        return $finalParcels;
    }


    /**
     * Calculate optimum packing into boxes based on product dimensions
     * Box weight limits are ignored in this
     *
     * @param array $items
     *
     * @return array
     */
    public function packDimensionedItems(array $fittingItems): array
    {
        $parcels = [];

        if (empty($fittingItems)) {
            return $parcels;
        }

        $this->fittingItems = $fittingItems;

        // Now the fitting items - use advanced algorithm
        return $this->commonPackingController->calculateMultiFittingItems($fittingItems);
    }

    /**
     * Partition dimensioned items into those too big for any box and others
     *
     * @param array $items
     *
     * @return array
     */
    private function getFittingItems(array $items): array
    {
        $tooBigItems = [];
        foreach ($items as $key => $item) {
            $fits = $this->commonPackingController->getFitsIndex($item);
            if ($fits === null) {
                $tooBigItems[] = $item;
                unset($items[$key]);
            }
        }
        $items = array_values($items);

        return [$tooBigItems, $items];
    }

    /**
     * @param array $package
     *
     * @return float
     */
    private function packVol(array $package): float
    {
        return (float)$package['dim1'] * (float)$package['dim2'] * (float)$package['dim3'];
    }

    /**
     * @param array $box
     * @param array $pdims
     *
     * @return int
     */
    private static function getMaxPackingConfiguration(array $box, array $pdims): int
    {
        $boxPermutations = [
            [0, 1, 2],
            [0, 2, 1],
            [1, 0, 2],
            [1, 2, 0],
            [2, 1, 0],
            [2, 0, 1]
        ];
        if (isset($box['dimension'])) {
            $box = array_values($box['dimension']);
        }
        $maxItems = 0;
        foreach ($boxPermutations as $boxPermutation) {
            $boxItems = (int)($box[0] / $pdims[$boxPermutation[0]]);
            $boxItems *= (int)($box[1] / $pdims[$boxPermutation[1]]);
            $boxItems *= (int)($box[2] / $pdims[$boxPermutation[2]]);
            $maxItems = max($maxItems, $boxItems);
        }

        return $maxItems;
    }

    /**
     * @param array $items
     * @param array $fits
     * @param int $boxndx
     *
     * @return array|null
     */
    private function fitItemsInRealBoxes(array $items, array $fits, int $boxndx = 0): ?array
    {
        $items1 = array_values($items);

        foreach ($fits as $fitKey => $fit) {
            if ((int)$fitKey < $boxndx) {
                unset($fits[$fitKey]);
            }
        }
        $j = $this->j;
        $j++;
        $entry  = [];
        $boxKey = null;

        for ($key = 0; $key < count($items1); $key++) {
            $item = $items1[$key];
            if ($item['quantity'] === 0) {
                continue;
            }
            $slug                 = $key;
            $boxKey               = !$boxKey ? $this->getBoxKey($fits, $slug, $item['quantity']) : $boxKey;
            $box                  = $this->boxes[$boxKey];
            $entry['item']        = $j;
            $entry['description'] = $item['name'];
            $entry['pieces']      = 1;
            $entry['dim1']        = $box['dimension']['length'] ?? $box['length'];
            $entry['dim2']        = $box['dimension']['width'] ?? $box['width'];
            $entry['dim3']        = $box['dimension']['height'] ?? $box['height'];
            $entry['actmass']     = 0.0;
            $entry['value']       = 0;

            // Calculate how many can be added
            $pdims    = [
                $item['dimension']['length'],
                $item['dimension']['width'],
                $item['dimension']['height'],
            ];
            $maxItems = self::getMaxPackingConfiguration($box, $pdims);
            $itemMass = $this->resolveItemMassKg($item);
            if (($box['max_weight'] ?? 0) > 0) {
                // Never let volumetric fit alone decide how many go in - a box that
                // fits N items by volume may still only carry fewer by weight.
                $maxItems = min($maxItems, (int)floor($box['max_weight'] / $itemMass));
            }
            if ($maxItems <= 0) {
                return null;
            }
            $nItemsToAdd = min($maxItems, $item['quantity']);
            // Put them into the box
            $entry['value']         += $nItemsToAdd * $item->price;
            $entry['actmass']       += $nItemsToAdd * ($item['mass'] ?? 0.1);
            $items1[$key]->quantity -= $nItemsToAdd;

            // Calculate the remaining boxes content
            $vboxes = self::getActualPackingConfigurationAdvanced($box, $pdims, $nItemsToAdd);
            // There are up to three virtual boxes
            for ($vboxi = 0; $vboxi < count($vboxes); $vboxi++) {
                $this->fitItemsInVbox($vboxes[$vboxi], $items1, $entry, (float)($box['max_weight'] ?? 0.0));
            }
            break;
        }
        $r2[]           = $entry;
        $itemsRemaining = 0;
        foreach ($items1 as $item1) {
            $itemsRemaining += $item1['quantity'];
        }
        $anyItemsLeft = $itemsRemaining > 0;
        $this->j      = $j;

        return [$r2, $anyItemsLeft, array_values($items1)];
    }

    /**
     * @param $fits
     * @param $slug
     * @param $itemCount
     *
     * @return int|string
     */
    private function getBoxKey($fits, $slug, $itemCount)
    {
        $fitsSlug = 0;
        foreach ($fits as $key => $fit) {
            $fitsSlug = $key;
            if ($fit[$slug] >= $itemCount) {
                break;
            }
        }

        return $fitsSlug;
    }

    /**
     * @param $box
     * @param $item
     * @param $count
     *
     * @return array
     */
    private static function getActualPackingConfigurationAdvanced($box, $item, $count): array
    {
        $boxPermutations = [
            [0, 1, 2],
            [0, 2, 1],
            [1, 0, 2],
            [1, 2, 0],
            [2, 1, 0],
            [2, 0, 1]
        ];

        if (isset($box['dimension'])) {
            $boxLength = $box['dimension']['length'];
            $boxWidth  = $box['dimension']['width'];
            $boxHeight = $box['dimension']['height'];
        } else {
            $boxLength = $box[0];
            $boxWidth  = $box[1];
            $boxHeight = $box[2];
        }

        $usedHeight = $boxHeight;
        $useds      = [];
        foreach ($boxPermutations as $permutation) {
            $nl = min($count, (int)($boxLength / $item[$permutation[0]]));
            $nw = min($count, (int)($boxWidth / $item[$permutation[1]]));
            $na = $nl * $nw;
            $h  = 0;
            if ($na !== 0) {
                $h = ceil($count / $na) * $item[$permutation[2]];
                if ($h <= $usedHeight) {
                    $usedHeight = $h;
                }
            }
            $useds[] = [$nl * $item[$permutation[0]], $nw * $item[$permutation[1]], $h];
        }

        $used = [];
        foreach ($useds as $u) {
            if (self::floatsAreEqual($u[2], $usedHeight)) {
                $used = $u;
                break;
            }
        }

        $remainingBoxes = [];
        if (!empty($used)) {
            $vb1 = [$used[0], $used[1], $boxHeight - $used[2]];
            rsort($vb1);
            $vb1['volume'] = $vb1[0] * $vb1[1] * $vb1[2];
            if ($vb1['volume'] > 0) {
                $remainingBoxes[] = $vb1;
            }

            $vb2 = [$boxLength - $used[0], $boxWidth, $boxHeight];
            rsort($vb2);
            $vb2['volume'] = $vb2[0] * $vb2[1] * $vb2[2];
            if ($vb2['volume'] > 0) {
                $remainingBoxes[] = $vb2;
            }

            $vb3 = [
                $boxLength,
                $boxWidth - $used[1],
                $boxHeight
            ];
            rsort($vb3);
            $vb3['volume'] = $vb3[0] * $vb3[1] * $vb3[2];
            if ($vb3['volume'] > 0) {
                $remainingBoxes[] = $vb3;
            }
        }

        return $remainingBoxes;
    }

    /**
     * Calculate fit of items into virtual boxes
     * Called recursively
     *
     * @param $vbox
     * @param $items1
     * @param $entry
     *
     * @return void
     */
    private function fitItemsInVbox($vbox, &$items1, &$entry, float $boxMaxWeight = 0.0)
    {
        for ($itemi = 0; $itemi < count($items1); $itemi++) {
            $itemvb = $items1[$itemi];
            if ($itemvb['quantity'] === 0) {
                continue;
            }

            // Calculate how many can be added
            $pdims    = [
                $itemvb['dimension']['length'],
                $itemvb['dimension']['width'],
                $itemvb['dimension']['height'],
            ];
            $maxItems = self::getMaxPackingConfiguration($vbox, $pdims);
            if ($maxItems == 0) {
                continue;
            }

            $itemMass = $this->resolveItemMassKg($itemvb);
            if ($boxMaxWeight > 0) {
                // Cap by what's left of the physical box's weight budget, not just
                // by the leftover volumetric space in this virtual box.
                $remainingWeight = $boxMaxWeight - $entry['actmass'];
                $maxItems        = min($maxItems, (int)floor($remainingWeight / $itemMass));
            }
            if ($maxItems <= 0) {
                continue;
            }

            // Else put items into this virtual box
            $nitems = min(
                $maxItems,
                $itemvb['quantity']
            );

            $items1[$itemi]['quantity'] -= $nitems;
            $entry['actmass']           += $nitems * ($itemvb['grams'] ?? 0) / 1000.0;
            $entry['value']             += $nitems * (
                    $itemvb['price'] ?? $itemvb['originalUnitPriceSet']['shopMoney']['amount']
                );

            // Calculate the remaining vboxes content
            $vboxes = self::getActualPackingConfigurationAdvanced($vbox, $pdims, $nitems);
            for ($vbi = 0; $vbi < count($vboxes); $vbi++) {
                $this->fitItemsInVbox($vboxes[$vbi], $items1, $entry, $boxMaxWeight);
            }
            break;
        }
    }

    private function sort1($a, $b)
    {
        if (count($a) === count($b)) {
            $avol = 0.0;
            foreach ($a as $value) {
                $avol += $this->packVol($value);
            }
            $bvol = 0.0;
            foreach ($b as $value) {
                $bvol += $this->packVol($value);
            }

            return $avol <=> $bvol;
        }

        return count($a) <=> count($b);
    }

    private static function floatsAreEqual($a, $b): bool
    {
        return abs($a - $b) < 0.0001;
    }

    /**
     * Resolve an item's mass in kg, falling back to the Shopify variant weight,
     * then to the default mass, so weight-based packing limits always have a
     * usable (non-zero) figure to work with.
     */
    private function resolveItemMassKg(array $item): float
    {
        $grams = $item['grams'] ?? 0.0;
        if ($grams <= 0.0) {
            $grams = $item['variant']['inventoryItem']['measurement']['weight']['value'] ?? 0.0;
        }
        if ($grams <= 0.0) {
            $grams = self::DEFAULT_MASS * 1000.0;
        }

        return $grams / 1000.0;
    }
}
