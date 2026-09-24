<?php

namespace BeeDelivery\GoogleMaps\Utils;

use BeeDelivery\GoogleMaps\DTOs\WaypointDTO;
use BeeDelivery\GoogleMaps\Enums\RouteTravelModeEnum;
use BeeDelivery\GoogleMaps\Exceptions\RouteMatrixException;

trait HelpersRouteMatrix
{
    public function url()
    {
        return config('googlemaps.distance_matrix_url');
    }

    public function formatFieldMask(): string
    {
        return 'originIndex,duration,destinationIndex,distanceMeters,condition';
    }

    /**
     * format the request for the distance matrix API
     * @param array<WaypointDTO> $origins
     * @param array<WaypointDTO> $destinations
     * @param RouteTravelModeEnum $mode
     * @return array{origins: array, destinations: array, travelMode: string}
     * @throws RouteMatrixException when the origins or destinations are empty or the number of elements is greater than 100
     */
    public function formatRequest(array $origins, array $destinations, RouteTravelModeEnum $mode = RouteTravelModeEnum::DRIVE): array
    {
        $this->validateRequest($origins, $destinations);

        $dataSearch = [
            'origins' => [],
            'destinations' => [],
            'travelMode' => $mode->value,
        ];
        
        foreach ($origins as $origin) {
            $dataSearch['origins'][] = [
                'waypoint' => [
                    'location' => [
                        'latLng' => [
                            'latitude' => $origin->latitude,
                            'longitude' => $origin->longitude,
                        ]
                    ]
                ]
            ];
        }

        foreach ($destinations as $destination) {
            $dataSearch['destinations'][] = [
                'waypoint' => [
                    'location' => [
                        'latLng' => [
                            'latitude' => $destination->latitude,
                            'longitude' => $destination->longitude,
                        ]
                    ]
                ]
            ];
        }

        return $dataSearch;
    }

    /**
     * Validate the request
     * @param array<WaypointDTO> $origins
     * @param array<WaypointDTO> $destinations
     * @throws RouteMatrixException when the request is invalid
     * @return void
     */
    private function validateRequest(array $origins, array $destinations): void
    {
        $originQuantity = count($origins);
        $destinationQuantity = count($destinations);
        if ($originQuantity === 0) {
            throw new RouteMatrixException('You must provide at least one origin');
        }

        if ($destinationQuantity < 2) {
            throw new RouteMatrixException('You must provide at least two destinations');
        }
        
        $numberOfElements = $originQuantity * $destinationQuantity;
        if ($numberOfElements > 100) {
            throw new RouteMatrixException("The number of elements(origins * destinations = {$numberOfElements}) must be less than or equal to 100");
        }
    }
}
