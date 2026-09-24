<?php

namespace BeeDelivery\GoogleMaps\Services\Impl;

use BeeDelivery\GoogleMaps\DTOs\OptimizedWaypointsDTO;
use BeeDelivery\GoogleMaps\DTOs\OptimizeWaypointsDTO;
use BeeDelivery\GoogleMaps\DTOs\WaypointDTO;
use BeeDelivery\GoogleMaps\Enums\RouteTravelModeEnum;
use BeeDelivery\GoogleMaps\Exceptions\RouteMatrixException;
use BeeDelivery\GoogleMaps\Services\Contracts\WaypointsOptimizer;
use BeeDelivery\GoogleMaps\Services\RouteMatrix;
use BeeDelivery\GoogleMaps\Utils\RouteMatrixDistanceOptimizerByTSP;

class WaypointsOptimizerByRouteMatrixDistance implements WaypointsOptimizer
{
    private RouteMatrix $routeMatrix;
    private RouteTravelModeEnum $mode;
    public function __construct(?RouteMatrix $routeMatrix = null)
    {
        $this->routeMatrix = $routeMatrix ?? new RouteMatrix();
        $this->mode = RouteTravelModeEnum::DRIVE;
    }

    /**
     * Optimize the waypoints by distance using the route matrix API
     * @param WaypointDTO $origin
     * @param WaypointDTO $destination
     * @param array<WaypointDTO> $waypoints
     * @param RouteTravelModeEnum $mode
     * @return array
     * @throws RouteMatrixException when the waypoints count is less than 3 or greater than 25
     */
    public function optimize(OptimizeWaypointsDTO $optimizeWaypointsDTO): OptimizedWaypointsDTO
    {
        $origins = $destinations = [$optimizeWaypointsDTO->origin, ...$optimizeWaypointsDTO->intermediateWaypoints, $optimizeWaypointsDTO->destination];
        $this->validateWaypointsCount($origins);
        
        $routeMatrix = $this->routeMatrix->getRouteMatrix($origins, $destinations, $this->mode);
        $tspOptimizer = new RouteMatrixDistanceOptimizerByTSP($routeMatrix, $optimizeWaypointsDTO->hasReturn);
        return $tspOptimizer->buildOptimizedWaypointsDTO();
    }
    
    /**
     * Validate the waypoints count
     * @param array<WaypointDTO> $waypoints
     * @throws RouteMatrixException when the waypoints count is less than 3 or greater than 25
     * @return void
     */
    private function validateWaypointsCount(array $waypoints): void
    {
        $waypointsCount = count($waypoints);
        if ($waypointsCount < 3) {
            throw new RouteMatrixException('You must provide at least 3 waypoints including origin and destination');
        }
        if ($waypointsCount > 25) {
            throw new RouteMatrixException("You must provide less than 26 waypoints including origin and destination, {$waypointsCount} were provided");
        }
    }

}
