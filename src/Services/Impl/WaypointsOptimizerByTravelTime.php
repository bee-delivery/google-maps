<?php
namespace BeeDelivery\GoogleMaps\Services\Impl;

use BeeDelivery\GoogleMaps\DTOs\OptimizedWaypointsDTO;
use BeeDelivery\GoogleMaps\DTOs\OptimizeWaypointsDTO;
use BeeDelivery\GoogleMaps\DTOs\WaypointDTO;
use BeeDelivery\GoogleMaps\Enums\WaypointsOptimizerType;
use BeeDelivery\GoogleMaps\Exceptions\RoutesException;
use BeeDelivery\GoogleMaps\Services\Contracts\WaypointsOptimizer;
use BeeDelivery\GoogleMaps\Services\Routes;

class WaypointsOptimizerByTravelTime implements WaypointsOptimizer
{
    private Routes $routes;
    public function __construct()
    {
        $this->routes = new Routes();
    }
    
    /**
     * Optimize the waypoints by travel time
     * @param OptimizeWaypointsDTO $optimizeWaypointsDTO
     * @throws RoutesException when there is an error in the response
     * @return OptimizedWaypointsDTO
     */
    public function optimize(OptimizeWaypointsDTO $optimizeWaypointsDTO): OptimizedWaypointsDTO
    {
        if ($optimizeWaypointsDTO->hasReturn) {
            return $this->optimizeWithReturn($optimizeWaypointsDTO);
        }

        return $this->optimizeWithoutReturn($optimizeWaypointsDTO);
    }

    /**
     * Optimize the waypoints by travel time when there is a return to the origin
     * @param OptimizeWaypointsDTO $optimizeWaypointsDTO
     * @throws RoutesException when there is an error in the response
     * @return OptimizedWaypointsDTO
     */
    private function optimizeWithReturn(OptimizeWaypointsDTO $optimizeWaypointsDTO): OptimizedWaypointsDTO
    {
        $response = $this->routes->routes(
            $optimizeWaypointsDTO->origin->toRoutesApiArray(),
            $optimizeWaypointsDTO->destination->toRoutesApiArray(),
            array_map(fn (WaypointDTO $waypoint) => $waypoint->toRoutesApiArray(), $optimizeWaypointsDTO->intermediateWaypoints),
        );

        if (isset($response['code'])) {
            throw new RoutesException("Failed to optimize waypoints by travel time,
            code: {$response['code']}, message: {$response['response']}");
        }

        return new OptimizedWaypointsDTO(
            distanceInMeters: $response['distance'],
            distanceInKilometers: $response['distance_in_quilometers'],
            durationInSeconds: $response['duration_in_seconds'],
            durationInMinutes: $response['duration_in_minutes'],
            optimizationType: WaypointsOptimizerType::MIN_TRAVEL_TIME,
            intermediateWaypointsOrder: $response['waypoint_order'],
        );
    }

    /**
     * Optimize the waypoints by travel time when there is no return to the origin
     * @param OptimizeWaypointsDTO $optimizeWaypointsDTO
     * @throws RoutesException when there is an error in the response
     * @return OptimizedWaypointsDTO
     */
    private function optimizeWithoutReturn(OptimizeWaypointsDTO $optimizeWaypointsDTO): OptimizedWaypointsDTO
    {
        $fieldMask = 'routes.optimized_intermediate_waypoint_index,routes.legs.distanceMeters,routes.legs.duration';
        $response = $this->routes->routesPersonFieldMask(
            $fieldMask,
            $optimizeWaypointsDTO->origin->toRoutesApiArray(),
            $optimizeWaypointsDTO->destination->toRoutesApiArray(),
            array_map(fn (WaypointDTO $waypoint) => $waypoint->toRoutesApiArray(), $optimizeWaypointsDTO->intermediateWaypoints),
        );

        if (isset($response['code'])) {
            throw new RoutesException("Failed to optimize waypoints by travel time,
            code: {$response['code']}, message: {$response['response']}");
        }

        $route = $response['routes'][0] ?? [];
        $legs = $route['legs'] ?? [];

        if (empty($legs)) {
            throw new RoutesException('Failed to calculate totals: missing legs data from Routes API response.');
        }

        $legsWithoutReturn = array_slice($legs, 0, -1);

        $distanceInMeters = array_sum(array_map(
            fn($leg) => $leg['distanceMeters'] ?? 0,
            $legsWithoutReturn
        ));
        
        $durationInSeconds = array_sum(array_map(
            fn($leg) => $this->parseDurationInSeconds($leg['duration'] ?? '0s'),
            $legsWithoutReturn
        ));

        return new OptimizedWaypointsDTO(
            distanceInMeters: $distanceInMeters,
            distanceInKilometers: round($distanceInMeters / 1000, 2),
            durationInSeconds: $durationInSeconds,
            durationInMinutes: round($durationInSeconds / 60, 2),
            optimizationType: WaypointsOptimizerType::MIN_TRAVEL_TIME,
            intermediateWaypointsOrder: $route['optimizedIntermediateWaypointIndex'] ?? [],
        );
    }

    /**
     * Parse Routes API duration strings (e.g. "12345s") into seconds.
     */
    private function parseDurationInSeconds(string $duration): int
    {
        $normalized = rtrim($duration, 's');
        return (int) $normalized;
    }
}
