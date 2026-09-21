<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Turns a price series into the geometry for an inline SVG chart.
 *
 * Deliberately not a JavaScript charting library. The series is at most a few
 * hundred points, the shape is known at render time, and computing it server-side
 * means the chart appears in the initial HTML: it needs no JS to render, survives
 * being printed into a report, and adds no dependency to audit.
 */
final readonly class LineChart
{
    /**
     * @param  array<int, array{x: float, y: float, label: string, value: float}>  $points
     * @param  array<int, array{y: float, value: float}>  $gridLines
     */
    public function __construct(
        public string $linePath,
        public string $areaPath,
        public array $points,
        public array $gridLines,
        public float $min,
        public float $max,
        public int $width,
        public int $height,
    ) {}

    /**
     * @param  Collection<int, object>  $series  Rows with ->date and the value key.
     */
    public static function fromSeries(
        Collection $series,
        string $valueKey = 'avg_price',
        int $width = 760,
        int $height = 240,
        int $padding = 32,
    ): ?self {
        if ($series->count() < 2) {
            return null;
        }

        $values = $series->map(fn (object $row): float => (float) $row->{$valueKey});
        $min = (float) $values->min();
        $max = (float) $values->max();

        // A flat series would divide by zero. Open a small window around the value
        // so the line renders through the middle rather than collapsing onto an edge.
        if ($max - $min < 0.0001) {
            $padValue = max(0.5, abs($max) * 0.05);
            $min -= $padValue;
            $max += $padValue;
        }

        $plotWidth = $width - ($padding * 2);
        $plotHeight = $height - ($padding * 2);
        $lastIndex = $series->count() - 1;
        $range = $max - $min;

        $points = [];

        foreach ($series->values() as $index => $row) {
            $value = (float) $row->{$valueKey};

            $points[] = [
                'x' => round($padding + ($index / $lastIndex) * $plotWidth, 2),
                // SVG's y axis grows downward, so the value is inverted.
                'y' => round($padding + (1 - (($value - $min) / $range)) * $plotHeight, 2),
                'label' => (string) $row->date,
                'value' => $value,
            ];
        }

        $linePath = '';

        foreach ($points as $index => $point) {
            $linePath .= ($index === 0 ? 'M' : ' L').$point['x'].' '.$point['y'];
        }

        $areaPath = $linePath
            .' L'.end($points)['x'].' '.($height - $padding)
            .' L'.$points[0]['x'].' '.($height - $padding)
            .' Z';

        // Four horizontal reference lines, labelled with the value they sit at.
        $gridLines = [];

        for ($i = 0; $i <= 3; $i++) {
            $fraction = $i / 3;

            $gridLines[] = [
                'y' => round($padding + $fraction * $plotHeight, 2),
                'value' => round($max - ($fraction * $range), 2),
            ];
        }

        return new self(
            linePath: $linePath,
            areaPath: $areaPath,
            points: $points,
            gridLines: $gridLines,
            min: $min,
            max: $max,
            width: $width,
            height: $height,
        );
    }

    /**
     * Percentage change from the first point to the last.
     */
    public function changePercent(): float
    {
        $first = $this->points[0]['value'];
        // Not end(): it takes its argument by reference to move the array pointer,
        // which PHP rejects as an indirect modification of a readonly property.
        $last = $this->points[count($this->points) - 1]['value'];

        if ($first == 0.0) {
            return 0.0;
        }

        return round((($last - $first) / $first) * 100, 2);
    }

    /**
     * @return array{x: float, y: float, label: string, value: float}
     */
    public function lastPoint(): array
    {
        return $this->points[count($this->points) - 1];
    }
}
