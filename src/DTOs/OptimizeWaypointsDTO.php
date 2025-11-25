<?php
namespace BeeDelivery\GoogleMaps\DTOs;

class OptimizeWaypointsDTO
{
    public function __construct(
        public readonly WaypointDTO $origin,
        public readonly WaypointDTO $destination,
        /** @var array<WaypointDTO> */
        public readonly array $intermediateWaypoints,
        public readonly bool $hasReturn = false,
    ){}
}
