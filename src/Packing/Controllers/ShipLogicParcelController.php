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
    private const DEFAULT_MASS = 0.1;
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

//    private function fitMassItemsInBoxes($massBased): array
//    {
//        $parcels = [];
//
//        foreach ($this->boxes as $boxIndex => $box) {
//            $boxMaxWeight = $box['max_weight'];
//            if ($boxMaxWeight <= 0) {
//                continue;
//            }
//            $parcel             = [
//                'mass'   => 0.00,
//                'value'  => 0.00,
//                'length' => $box['dimension']['length'] ?? $box['length'],
//                'width'  => $box['dimension']['width'] ?? $box['width'],
//                'height' => $box['dimension']['height'] ?? $box['height'],
//            ];
//            $boxAddedWeight     = 0.0;
//            $boxAvailableWeight = $boxMaxWeight - $boxAddedWeight;
//            $unplacedItems      = array_reduce($massBased, function ($carry, $item) {
//                return $carry + $item->quantity;
//            }, 0);
//            while ($unplacedItems > 0) {
//                if ($boxAvailableWeight <= 0) {
//                    $parcel = [
//                        'mass'   => 0.00,
//                        'value'  => 0.00,
//                        'length' => $box['dimension']['length'] ?? $box['length'],
//                        'width'  => $box['dimension']['width'] ?? $box['width'],
//                        'height' => $box['dimension']['height'] ?? $box['height'],
//                    ];
//                }
//                foreach ($massBased as $item) {
//                    $remainingItems = $item->quantity;
//                    // Mass-based items include those with no dimension data at all - default mass
//                    $itemMass = $item->dimension['mass'] > 0 ? $item->dimension['mass'] : 0.1;
//                    if ($itemMass <= 0) {
//                        $unplacedItems--;
//                        continue;
//                    }
//                    while ($remainingItems > 0 && $unplacedItems > 0) {
//                        $maxItems = (int)floor($boxAvailableWeight / $itemMass);
//                        if ($maxItems <= 0) {
//                            $parcels[$boxIndex][] = $parcel;
//                            $parcel               = [
//                                'mass'   => 0.00,
//                                'value'  => 0.00,
//                                'length' => $box['dimension']['length'] ?? $box['length'],
//                                'width'  => $box['dimension']['width'] ?? $box['width'],
//                                'height' => $box['dimension']['height'] ?? $box['height'],
//                            ];
//                            $boxAvailableWeight   = $boxMaxWeight;
//                            $maxItems             = (int)floor($boxAvailableWeight / $itemMass);
//                        }
//                        $addedItems      = min($remainingItems, $maxItems);
//                        $parcel['mass']  += $addedItems * $itemMass;
//                        $parcel['value'] += $addedItems * $item->price;
//
//                        $remainingItems     -= $addedItems;
//                        $unplacedItems      -= $addedItems;
//                        $boxAvailableWeight -= $addedItems * $itemMass;
//                        if ($unplacedItems === 0) {
//                            $parcels[$boxIndex][] = $parcel;
//                        }
//                    }
//                }
//            }
//        }
//
//        uasort($parcels, function ($a, $b) {
//            return count($a) <=> count($b); // Sort in descending order of parcel count
//        });
//        $keys         = array_keys($parcels);
//        $values       = array_values($parcels);
//        $finalParcels = [];
//        $selectedKey  = $keys[0];
//        if ($selectedKey !== 0) {
//            foreach ($values[0] as $value) {
//                for ($i = 0; $i < $selectedKey; $i++) {
//                    if ($this->boxes[$i]['max_weight'] >= $value['mass']) {
//                        $value['length'] = $this->boxes[$i]['dimension']['length'];
//                        $value['width']  = $this->boxes[$i]['dimension']['width'];
//                        $value['height'] = $this->boxes[$i]['dimension']['height'];
//                        $finalParcels[]  = $value;
//                        break;
//                    } else {
//                        $finalParcels[] = $value;
//                    }
//                }
//            }
//        } else {
//            $finalParcels = $values[0];
//        }
//
//        return $finalParcels;
//    }


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
}
