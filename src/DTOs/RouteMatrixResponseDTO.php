<?php
namespace BeeDelivery\GoogleMaps\DTOs;

class RouteMatrixResponseDTO
{
    /**
     * The value the route matrix API reports when it managed to route the element
     * @var string
     */
    const ROUTE_EXISTS_CONDITION = 'ROUTE_EXISTS';

    public function __construct(
        public readonly int $originIndex,
        public readonly int $destinationIndex,
        public readonly int $distanceMeters,
        public readonly int $durationInSeconds,
        /**
         * Whether the API managed to route this pair of waypoints. An element it
         * could not route comes without distanceMeters, which is indistinguishable
         * from a legitimate zero distance unless the condition is read.
         */
        public readonly bool $routeExists = true,
    ) {
    }
    
    public static function fromResponse(array $response): self
    {
        return new self(
            $response['originIndex'],
            $response['destinationIndex'],
            $response['distanceMeters'] ?? 0,
            (int) ($response['duration'] ?? 0),
            ($response['condition'] ?? self::ROUTE_EXISTS_CONDITION) === self::ROUTE_EXISTS_CONDITION,
        );
    }
}
