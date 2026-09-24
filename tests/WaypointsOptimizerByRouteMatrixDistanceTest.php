<?php

namespace Tests;

use BeeDelivery\GoogleMaps\DTOs\OptimizeWaypointsDTO;
use BeeDelivery\GoogleMaps\DTOs\RouteMatrixResponseDTO;
use BeeDelivery\GoogleMaps\DTOs\WaypointDTO;
use BeeDelivery\GoogleMaps\Enums\RouteTravelModeEnum;
use BeeDelivery\GoogleMaps\Services\Impl\WaypointsOptimizerByRouteMatrixDistance;
use BeeDelivery\GoogleMaps\Services\RouteMatrix;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

class WaypointsOptimizerByRouteMatrixDistanceTest extends PHPUnitTestCase
{
    /**
     * Um route matrix que devolve as distâncias de pontos dispostos em uma reta,
     * sem tocar a API do Google.
     *
     * @param  array<int, int>  $positionsInMeters
     */
    private function fakeRouteMatrix(array $positionsInMeters): RouteMatrix
    {
        return new class($positionsInMeters) extends RouteMatrix
        {
            /** @param array<int, int> $positionsInMeters */
            public function __construct(private readonly array $positionsInMeters)
            {
            }

            public function getRouteMatrix(array $origins, array $destinations, RouteTravelModeEnum $mode = RouteTravelModeEnum::DRIVE): array
            {
                $matrix = [];
                foreach ($this->positionsInMeters as $originIndex => $originPosition) {
                    foreach ($this->positionsInMeters as $destinationIndex => $destinationPosition) {
                        $distanceMeters = abs($originPosition - $destinationPosition);
                        $matrix[] = new RouteMatrixResponseDTO(
                            $originIndex,
                            $destinationIndex,
                            $distanceMeters,
                            (int) ($distanceMeters / 10),
                        );
                    }
                }

                return $matrix;
            }
        };
    }

    public function testDoesNotChargeTheReturnToTheOriginOnARouteWithoutReturn(): void
    {
        // Loja em 0km e paradas em 10km, 20km e 30km. Como a rota não tem retorno,
        // o OrderHelper repete a origem como ponto terminal da matriz.
        $optimizer = new WaypointsOptimizerByRouteMatrixDistance(
            $this->fakeRouteMatrix([0, 10000, 20000, 30000, 0])
        );

        $optimized = $optimizer->optimize(new OptimizeWaypointsDTO(
            origin: new WaypointDTO(latitude: 0, longitude: 0),
            destination: new WaypointDTO(latitude: 0, longitude: 0),
            intermediateWaypoints: [
                new WaypointDTO(latitude: 0.1, longitude: 0),
                new WaypointDTO(latitude: 0.2, longitude: 0),
                new WaypointDTO(latitude: 0.3, longitude: 0),
            ],
            hasReturn: false,
        ));

        $this->assertSame(30000, $optimized->distanceInMeters);
    }

    public function testChargesTheReturnToTheOriginOnARouteWithReturn(): void
    {
        $optimizer = new WaypointsOptimizerByRouteMatrixDistance(
            $this->fakeRouteMatrix([0, 10000, 20000, 30000, 0])
        );

        $optimized = $optimizer->optimize(new OptimizeWaypointsDTO(
            origin: new WaypointDTO(latitude: 0, longitude: 0),
            destination: new WaypointDTO(latitude: 0, longitude: 0),
            intermediateWaypoints: [
                new WaypointDTO(latitude: 0.1, longitude: 0),
                new WaypointDTO(latitude: 0.2, longitude: 0),
                new WaypointDTO(latitude: 0.3, longitude: 0),
            ],
            hasReturn: true,
        ));

        $this->assertSame(60000, $optimized->distanceInMeters);
    }
}
