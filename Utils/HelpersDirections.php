<?php

namespace BeeDelivery\GoogleMaps\Utils;

use Exception;

trait HelpersDirections
{
    public function url()
    {
        return config('googlemaps.directions_url');
    }

    public function formatFieldMask(bool $optimize): string
    {
        $fieldMask = 'routes.distanceMeters,routes.duration';
        return $optimize ? $fieldMask . ',routes.optimizedIntermediateWaypointIndex' : $fieldMask;
    }

    public function formatRequest(array $waypoints, bool $optimize, string $mode): array
    {
        if (count($waypoints) < 2) {
            throw new \Exception('You must provide at least two waypoints');
        } elseif (count($waypoints) == 2) {
            $dataSearch = $this->formatForTwoWaypoints($waypoints, $mode);
        } else {
            $dataSearch = $this->formatForMoreThatTwoWaypointsOptimize($waypoints, $optimize, $mode);
        }

        $dataSearch['optimizeWaypointOrder'] = $optimize;
        $dataSearch['travelMode'] = $mode;

        return $dataSearch;
    }

    public function formatResponse(array $response): array
    {
        if (!isset($response['routes']) || empty($response['routes'])) {
            throw new Exception('No routes found in response', 404);
        }

        $distance = (int) ($response['routes'][0]['distanceMeters'] ?? 0);
        $duration = (int) ($response['routes'][0]['duration'] ?? 0);

        if ($distance == 0) {
            throw new Exception('Distance can not be zero', 404);
        }

        $formattedResponse = [
            'distance' => $distance,
            'distance_in_quilometers' => number_format(($distance / 1000), 2),
            'duration_in_seconds' => $duration,
            'duration_in_minutes' => number_format(($duration / 60), 2),
            'waypoint_order' => $response['routes'][0]['optimizedIntermediateWaypointIndex'] ?? '',
        ];

        return $formattedResponse;
    }

    private function formatForTwoWaypoints(array $waypoints): array
    {
        $origin = $waypoints[0];
        $destination = $waypoints[count($waypoints) - 1];

        return [
            'origin' => [
                'location' => [
                    'latLng' => [
                        'latitude' => $origin['lat'],
                        'longitude' => $origin['lng']
                    ]
                ]
            ],
            'destination' => [
                'location' => [
                    'latLng' => [
                        'latitude' => $destination['lat'],
                        'longitude' => $destination['lng']
                    ]
                ]
            ],
        ];
    }

    private function formatForMoreThatTwoWaypointsOptimize(array $waypoints): array
    {
        $dataSearch = $this->formatForTwoWaypoints($waypoints);
        // Adicionar pontos intermediários, se houver mais de dois
        $intermediateWaypoints = array_slice($waypoints, 1, -1);

        foreach ($intermediateWaypoints as $waypoint) {
            $dataSearch['intermediates'][] = [
                'location' => [
                    'latLng' => [
                        'latitude' => $waypoint['lat'],
                        'longitude' => $waypoint['lng']
                    ]
                ]
            ];
        }

        return $dataSearch;
    }
}
