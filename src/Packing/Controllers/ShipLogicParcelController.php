<?php

namespace Tcg\Common\Packing\Controllers;

use App\Http\Controllers\Controller;
use App\Models\TcgService;
use Illuminate\Http\Request;
use Tcg\Common\Packing\Controllers\CommonPackingController;

class ShipLogicParcelController
{
    private array $boxes;
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
        // Sort the boxes in ascending order by dimension
        usort($this->boxes, function ($a, $b) {
            return $a['length'] <=> $b['length']
                ?: $a['width'] <=> $b['width']
                    ?: $a['height'] <=> $b['height']
                        ?: $a['max_weight'] <=> $b['max_weight'];
        });
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
     * Parcel up too-big items
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
     *
     * @return array
     */
    public function getMassBased($massBased): array
    {
        return [];
    }

    /**
     * Calculate optimum packing into boxes based on product dimensions
     * Box weight limits are ignored in this
     *
     * @param array $fittingItems
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
}
