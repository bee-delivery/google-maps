<?php

namespace BeeDelivery\GoogleMaps\Utils;

use BeeDelivery\GoogleMaps\DTOs\OptimizedWaypointsDTO;
use BeeDelivery\GoogleMaps\DTOs\OptimizedWaypointsSummaryDTO;
use BeeDelivery\GoogleMaps\DTOs\RouteMatrixResponseDTO;
use BeeDelivery\GoogleMaps\Enums\WaypointsOptimizerType;
use BeeDelivery\GoogleMaps\Exceptions\RouteMatrixException;

class RouteMatrixDistanceOptimizerByTSP
{
    /**
     * The maximum number of waypoints allowed by the route matrix API
     * @var int
     */
    const MAX_WAYPOINTS_COUNT_ALLOWED = 25;
    /**
     * The maximum number of 2-opt passes over the route built by the greedy algorithm
     * @var int
     */
    const MAX_TWO_OPT_PASSES = 50;
    /**
     * The map of the route matrix (origin => destinations sorted by distance)
     * @var array<int, array<RouteMatrixResponseDTO>>
     */
    private array $map = [];
    /**
     * The route matrix rows indexed by "originIndex:destinationIndex"
     * @var array<string, RouteMatrixResponseDTO>
     */
    private array $legs = [];
    /**
     * The visited indexes by the algorithm
     * @var array<int>
     */
    private array $visitedIndexes = [];
    /**
     * The route matrix
     * @var array<RouteMatrixResponseDTO>
     */
    private array $routeMatrix;
    /**
     * The index of the destination waypoint
     * @var int
     */
    private int $destinationWaypointIndex;
    /**
     * The chosen order of the intermediate waypoints, as matrix indexes
     * @var array<int>
     */
    private array $optimalIntermediateWaypointsPath = [];

    /**
     * Construct the RouteMatrixDistanceOptimizerByTSP object
     * @param array<RouteMatrixResponseDTO> $routeMatrix the route matrix
     * @param bool $hasReturn whether the route really ends at the last waypoint of the
     *                        matrix. When false that waypoint is only a placeholder
     *                        required by the route matrix API and the leg towards it
     *                        must not be counted. Required on purpose: defaulting it
     *                        would let a caller silently charge a return that the
     *                        route does not have.
     * @throws RouteMatrixException when the route matrix is empty
     */
    public function __construct(array $routeMatrix, private readonly bool $hasReturn)
    {
        $this->routeMatrix = $routeMatrix;
        $this->initializeMap();
        $this->destinationWaypointIndex = $this->resolveDestinationWaypointIndex();
    }

    /**
     * Initialize the map
     * @throws RouteMatrixException when the route matrix is empty
     * @return void
     */
    private function initializeMap(): void
    {
        if (empty($this->routeMatrix)) {
            throw new RouteMatrixException('The route matrix is empty');
        }
        for ($i = 0; $i < count($this->routeMatrix); $i++) {
            $this->add($this->routeMatrix[$i]);
        }
    }

    /**
     * Add a destination for a specific origin
     * @param RouteMatrixResponseDTO $routeMatrixRow
     * @return void
     */
    private function add(RouteMatrixResponseDTO $routeMatrixRow): void
    {
        if ($routeMatrixRow->originIndex === $routeMatrixRow->destinationIndex) {
            return;
        }

        if (! $routeMatrixRow->routeExists) {
            // Um elemento que a API não conseguiu rotear chega sem distanceMeters,
            // ou seja, como zero. Mantê-lo faria dele a perna mais barata da matriz.
            return;
        }

        $originIndex = $routeMatrixRow->originIndex;
        $this->map[$originIndex][] = $routeMatrixRow;
        $this->legs[$this->legKey($originIndex, $routeMatrixRow->destinationIndex)] = $routeMatrixRow;

        usort($this->map[$originIndex], fn(RouteMatrixResponseDTO $a, RouteMatrixResponseDTO $b) => $a->distanceMeters <=> $b->distanceMeters);
    }

    /**
     * Build the key used to index a leg of the route matrix
     * @param int $originIndex
     * @param int $destinationIndex
     * @return string
     */
    private function legKey(int $originIndex, int $destinationIndex): string
    {
        return $originIndex . ':' . $destinationIndex;
    }

    /**
     * Resolve the index of the last waypoint of the matrix.
     *
     * It is read from the matrix rows instead of from the size of the map so that a
     * row missing from the response (for instance an element the API could not route)
     * does not silently shift every index.
     *
     * @return int
     */
    private function resolveDestinationWaypointIndex(): int
    {
        $indexes = [];
        foreach ($this->routeMatrix as $routeMatrixRow) {
            $indexes[] = $routeMatrixRow->originIndex;
            $indexes[] = $routeMatrixRow->destinationIndex;
        }

        return max($indexes);
    }

    /**
     * Get all destinations for a specific origin
     * @param int $originIndex
     * @return array<RouteMatrixResponseDTO>
     */
    public function getDestinations(int $originIndex): array
    {
        return $this->map[$originIndex] ?? [];
    }

    /**
     * Get the destination data for a specific origin and destination index
     * @param int $originIndex
     * @param int $destinationIndex
     * @return RouteMatrixResponseDTO
     * @throws RouteMatrixException when the destination is not found
     */
    public function getDestination(int $originIndex, int $destinationIndex): RouteMatrixResponseDTO
    {
        return $this->findDestination($originIndex, $destinationIndex) ??
            throw new RouteMatrixException('Destination not found for origin index: ' . $originIndex . ' and destination index: ' . $destinationIndex);
    }

    /**
     * Get the leg between two waypoints, or null when the matrix has no route for it
     * @param int $originIndex
     * @param int $destinationIndex
     * @return RouteMatrixResponseDTO|null
     */
    public function findDestination(int $originIndex, int $destinationIndex): ?RouteMatrixResponseDTO
    {
        return $this->legs[$this->legKey($originIndex, $destinationIndex)] ?? null;
    }

    /**
     * Calculate the order of the intermediate waypoints that yields the shortest route.
     *
     * The greedy route is refined with 2-opt and then compared against the order the
     * merchant submitted: the optimized route is never allowed to be longer than the
     * original one, and ties keep the original order untouched.
     *
     * @return void
     */
    private function calculateOptimalIntermediateWaypointsOrderByDistance(): void
    {
        if ($this->destinationWaypointIndex < 2) {
            throw new RouteMatrixException('The route matrix must have at least one intermediate waypoint');
        }

        $originalPath = range(1, $this->destinationWaypointIndex - 1);
        $originalDistance = $this->pathDistanceInMeters($originalPath);

        $greedyPath = $this->buildGreedyPath();
        $candidatePath = $greedyPath === null ? null : $this->improveWithTwoOpt($greedyPath);
        $candidateDistance = $candidatePath === null ? null : $this->pathDistanceInMeters($candidatePath);

        if ($candidateDistance !== null && ($originalDistance === null || $candidateDistance < $originalDistance)) {
            $this->optimalIntermediateWaypointsPath = $candidatePath;
            return;
        }

        if ($originalDistance === null) {
            throw new RouteMatrixException('No route could be calculated between the given waypoints');
        }

        $this->optimalIntermediateWaypointsPath = $originalPath;
    }

    /**
     * Build a route visiting, from each waypoint, the closest waypoint not visited yet
     * @throws RouteMatrixException when the maximum number of iterations is reached in case the algorithm gets stuck in a loop
     * @return array<int>|null the intermediate waypoints as matrix indexes, in visiting
     *                         order, or null when the matrix has no route to reach all of them
     */
    private function buildGreedyPath(): ?array
    {
        $this->visitedIndexes = [];
        $path = [];
        $index = 0;
        $intermediateWaypointsCount = $this->destinationWaypointIndex - 1;
        $maxIterations = self::MAX_WAYPOINTS_COUNT_ALLOWED;
        while (count($path) < $intermediateWaypointsCount) {
            $closestWaypoint = $this->findClosestWaypointTo($index);
            if ($closestWaypoint === null) {
                return null;
            }
            $this->visitedIndexes[] = $index;
            $index = $closestWaypoint->destinationIndex;
            $path[] = $index;
            $maxIterations--;
            if ($maxIterations < 0) {
                // just in case the algorithm gets stuck in a loop
                throw new RouteMatrixException('Max iterations reached while calculating optimal intermediate waypoints by distance');
            }
        }

        return $path;
    }

    /**
     * Get the closest waypoint not visited yet, or null when there is none left
     * @param int $index
     * @return RouteMatrixResponseDTO|null
     */
    private function findClosestWaypointTo(int $index): ?RouteMatrixResponseDTO
    {
        $destinations = $this->getDestinations($index);
        $destinations = array_filter($destinations, function (RouteMatrixResponseDTO $destination) {
            $wasNotVisitedYet = !in_array($destination->destinationIndex, $this->visitedIndexes);
            $isNotDestinationWaypoint = $destination->originIndex !== $this->destinationWaypointIndex;
            $doesNotPointToDestinationWaypoint = $destination->destinationIndex !== $this->destinationWaypointIndex;
            return $wasNotVisitedYet && $isNotDestinationWaypoint && $doesNotPointToDestinationWaypoint;
        });
        return array_values($destinations)[0] ?? null;
    }

    /**
     * Refine a route by reversing the segments that make it shorter (2-opt).
     *
     * Only strictly shorter routes are accepted, so the result is never worse than
     * the route received.
     *
     * @param array<int> $path the intermediate waypoints as matrix indexes
     * @return array<int>
     */
    private function improveWithTwoOpt(array $path): array
    {
        $bestPath = $path;
        $bestDistance = $this->pathDistanceInMeters($bestPath) ?? PHP_INT_MAX;
        $waypointsCount = count($bestPath);
        $passes = 0;
        $improved = true;

        while ($improved && $passes < self::MAX_TWO_OPT_PASSES) {
            $improved = false;
            $passes++;
            for ($start = 0; $start < $waypointsCount - 1; $start++) {
                for ($end = $start + 1; $end < $waypointsCount; $end++) {
                    $candidatePath = $bestPath;
                    $length = $end - $start + 1;
                    array_splice($candidatePath, $start, $length, array_reverse(array_slice($candidatePath, $start, $length)));
                    $candidateDistance = $this->pathDistanceInMeters($candidatePath) ?? PHP_INT_MAX;
                    if ($candidateDistance < $bestDistance) {
                        $bestPath = $candidatePath;
                        $bestDistance = $candidateDistance;
                        $improved = true;
                    }
                }
            }
        }

        return $bestPath;
    }

    /**
     * Get the legs travelled by a route, from the origin up to its last waypoint
     * @param array<int> $path the intermediate waypoints as matrix indexes
     * @return array<RouteMatrixResponseDTO>|null null when the matrix has no route for some leg
     */
    private function pathLegs(array $path): ?array
    {
        $legs = $this->intermediateLegs($path);

        if ($legs === null) {
            return null;
        }

        if ($this->hasReturn) {
            $closingLeg = $this->findDestination(end($path) ?: 0, $this->destinationWaypointIndex);
            if ($closingLeg === null) {
                return null;
            }
            $legs[] = $closingLeg;
        }

        return $legs;
    }

    /**
     * Get the legs travelled between the origin and the intermediate waypoints
     * @param array<int> $path the intermediate waypoints as matrix indexes
     * @return array<RouteMatrixResponseDTO>|null null when the matrix has no route for some leg
     */
    private function intermediateLegs(array $path): ?array
    {
        $legs = [];
        $originIndex = 0;
        foreach ($path as $destinationIndex) {
            $leg = $this->findDestination($originIndex, $destinationIndex);
            if ($leg === null) {
                return null;
            }
            $legs[] = $leg;
            $originIndex = $destinationIndex;
        }

        return $legs;
    }

    /**
     * Get the total distance of a route
     * @param array<int> $path the intermediate waypoints as matrix indexes
     * @return int|null null when the matrix has no route for some leg
     */
    private function pathDistanceInMeters(array $path): ?int
    {
        $legs = $this->pathLegs($path);

        if ($legs === null) {
            return null;
        }

        return array_reduce(
            $legs,
            fn(int $total, RouteMatrixResponseDTO $leg) => $total + $leg->distanceMeters,
            0
        );
    }

    /**
     * Get the optimal intermediate waypoints order by distance (calculated if not already calculated)
     * @return array<RouteMatrixResponseDTO>
     */
    public function getOptimalIntermediateWaypointsOrderByDistance(): array
    {
        return $this->intermediateLegs($this->getOptimalIntermediateWaypointsPath()) ??
            throw new RouteMatrixException('No route could be calculated between the given waypoints');
    }

    /**
     * Get the chosen order of the intermediate waypoints as matrix indexes
     * @return array<int>
     */
    private function getOptimalIntermediateWaypointsPath(): array
    {
        if (empty($this->optimalIntermediateWaypointsPath)) {
            $this->calculateOptimalIntermediateWaypointsOrderByDistance();
        }
        return $this->optimalIntermediateWaypointsPath;
    }

    /**
     * Get the optimized waypoints summary
     * @return OptimizedWaypointsSummaryDTO
     */
    public function getOptimizedWaypointsSummary(): OptimizedWaypointsSummaryDTO
    {
        $distanceInMeters = 0;
        $durationInSeconds = 0;

        $legs = $this->pathLegs($this->getOptimalIntermediateWaypointsPath()) ??
            throw new RouteMatrixException('No route could be calculated between the given waypoints');

        foreach ($legs as $leg) {
            $distanceInMeters += $leg->distanceMeters;
            $durationInSeconds += $leg->durationInSeconds;
        }

        $distanceInKilometers = round($distanceInMeters / 1000, 2);
        $durationInMinutes = round($durationInSeconds / 60, 2);

        return new OptimizedWaypointsSummaryDTO(
            distanceInMeters: $distanceInMeters,
            distanceInKilometers: $distanceInKilometers,
            durationInSeconds: $durationInSeconds,
            durationInMinutes: $durationInMinutes,
        );
    }

    /**
     * Convert the optimized waypoints order to an indexed array (0-based index)
     * @return array<int>
     */
    private function optimizedWaypointsOrderToIndexedArray(): array
    {
        return array_map(function (int $destinationIndex) {
            if ($destinationIndex <= 0) {
                throw new RouteMatrixException('Destination index can not be less than or equal to 0');
            }
            return $destinationIndex - 1;
        }, $this->getOptimalIntermediateWaypointsPath());
    }

    /**
     * Build the optimized waypoints DTO
     * @return OptimizedWaypointsDTO
     */
    public function buildOptimizedWaypointsDTO(): OptimizedWaypointsDTO
    {
        $summary = $this->getOptimizedWaypointsSummary();
        return new OptimizedWaypointsDTO(
            distanceInMeters: $summary->distanceInMeters,
            distanceInKilometers: $summary->distanceInKilometers,
            durationInSeconds: $summary->durationInSeconds,
            durationInMinutes: $summary->durationInMinutes,
            optimizationType: WaypointsOptimizerType::MIN_DISTANCE,
            intermediateWaypointsOrder: $this->optimizedWaypointsOrderToIndexedArray(),
        );
    }
}
