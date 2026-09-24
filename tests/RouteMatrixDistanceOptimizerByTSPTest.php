<?php

namespace Tests;

use BeeDelivery\GoogleMaps\DTOs\RouteMatrixResponseDTO;
use BeeDelivery\GoogleMaps\Utils\RouteMatrixDistanceOptimizerByTSP;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

class RouteMatrixDistanceOptimizerByTSPTest extends PHPUnitTestCase
{
    /**
     * Monta uma matriz completa a partir de posições em uma reta (em metros).
     *
     * O índice do array é o índice do ponto na matriz: 0 é a origem, o último é
     * o ponto terminal e os do meio são as paradas intermediárias.
     *
     * @param  array<int, int>  $positionsInMeters
     * @return array<RouteMatrixResponseDTO>
     */
    private function matrixFromLine(array $positionsInMeters): array
    {
        $matrix = [];

        foreach ($positionsInMeters as $originIndex => $originPosition) {
            foreach ($positionsInMeters as $destinationIndex => $destinationPosition) {
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

    public function testDoesNotAddTheReturnLegWhenTheRouteHasNoReturn(): void
    {
        // Rota sem retorno já lançada na ordem ótima: loja -> 10km -> 20km -> 30km.
        // O OrderHelper monta a matriz repetindo a origem no fim, porque a API do
        // route matrix precisa de um ponto terminal.
        $matrix = $this->matrixFromLine([0, 10000, 20000, 30000, 0]);

        $optimized = (new RouteMatrixDistanceOptimizerByTSP($matrix, hasReturn: false))
            ->buildOptimizedWaypointsDTO();

        $this->assertSame(30000, $optimized->distanceInMeters);
        $this->assertSame(30.0, $optimized->distanceInKilometers);
    }

    public function testKeepsTheReturnLegWhenTheRouteGoesBackToTheOrigin(): void
    {
        // Mesma geometria, mas agora a entrega volta para a loja: a perna final conta.
        $matrix = $this->matrixFromLine([0, 10000, 20000, 30000, 0]);

        $optimized = (new RouteMatrixDistanceOptimizerByTSP($matrix, hasReturn: true))
            ->buildOptimizedWaypointsDTO();

        $this->assertSame(60000, $optimized->distanceInMeters);
    }

    public function testNeverReturnsARouteLongerThanTheOneSubmittedByTheMerchant(): void
    {
        // O guloso (nearest neighbor) sai da loja pelas paradas mais próximas e deixa
        // a mais distante por último, ficando pior que a ordem lançada pelo lojista.
        // Ordem lançada: loja(0) -> -2km -> 1km -> 2km -> 3km = 7km.
        $matrix = $this->matrixFromLine([0, -2000, 1000, 2000, 3000, 0]);

        $optimized = (new RouteMatrixDistanceOptimizerByTSP($matrix, hasReturn: false))
            ->buildOptimizedWaypointsDTO();

        $this->assertLessThanOrEqual(7000, $optimized->distanceInMeters);
    }

    public function testKeepsTheOrderSubmittedByTheMerchantWhenItIsAlreadyTheShortestOne(): void
    {
        $matrix = $this->matrixFromLine([0, 10000, 20000, 30000, 0]);

        $optimized = (new RouteMatrixDistanceOptimizerByTSP($matrix, hasReturn: false))
            ->buildOptimizedWaypointsDTO();

        $this->assertSame([0, 1, 2], $optimized->intermediateWaypointsOrder);
    }

    public function testReordersTheStopsWhenThatMakesTheRouteShorter(): void
    {
        // Ordem lançada: loja(0) -> 9km -> 1km -> 5km = 21km.
        // Melhor ordem: loja(0) -> 1km -> 5km -> 9km = 9km.
        $matrix = $this->matrixFromLine([0, 9000, 1000, 5000, 0]);

        $optimized = (new RouteMatrixDistanceOptimizerByTSP($matrix, hasReturn: false))
            ->buildOptimizedWaypointsDTO();

        $this->assertSame(9000, $optimized->distanceInMeters);
        $this->assertSame([1, 2, 0], $optimized->intermediateWaypointsOrder);
    }

    public function testHandlesTwoStopsAtTheSameAddress(): void
    {
        // Duas paradas no mesmo endereço (mesmo prédio, mesmo condomínio): a perna
        // entre elas tem distância zero e não pode sumir da matriz.
        $matrix = $this->matrixFromLine([0, 5000, 5000, 9000, 0]);

        $optimized = (new RouteMatrixDistanceOptimizerByTSP($matrix, hasReturn: false))
            ->buildOptimizedWaypointsDTO();

        $this->assertSame(9000, $optimized->distanceInMeters);
    }

    public function testHandlesAStopAtTheSameAddressAsTheOrigin(): void
    {
        // Uma das paradas é no próprio endereço da loja. A perna até ela tem
        // distância zero, e a versão anterior estourava RouteMatrixException aqui.
        // A rota mais curta entrega essa parada primeiro (0km) e depois a de 3km.
        $matrix = $this->matrixFromLine([0, 3000, 0, 0]);

        $optimized = (new RouteMatrixDistanceOptimizerByTSP($matrix, hasReturn: false))
            ->buildOptimizedWaypointsDTO();

        $this->assertSame(3000, $optimized->distanceInMeters);
        $this->assertSame([1, 0], $optimized->intermediateWaypointsOrder);
    }
}
