<?php

namespace Tests\Unit;

use App\Support\LineChart;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LineChartTest extends TestCase
{
    /**
     * @param  array<int, array{string, float}>  $rows
     * @return Collection<int, object>
     */
    private function series(array $rows): Collection
    {
        return collect($rows)->map(fn (array $row): object => (object) [
            'date' => $row[0],
            'avg_price' => $row[1],
        ]);
    }

    #[Test]
    public function it_needs_at_least_two_points_to_draw_a_line(): void
    {
        $this->assertNull(LineChart::fromSeries($this->series([])));
        $this->assertNull(LineChart::fromSeries($this->series([['2026-01-01', 10.0]])));
    }

    #[Test]
    public function it_places_the_lowest_value_at_the_bottom_and_the_highest_at_the_top(): void
    {
        $chart = LineChart::fromSeries(
            $this->series([['2026-01-01', 10.0], ['2026-01-02', 20.0]]),
            width: 200,
            height: 100,
            padding: 10,
        );

        // SVG's y axis grows downward, so the larger value must have the smaller y.
        $this->assertSame(90.0, $chart->points[0]['y'], 'The minimum should sit on the bottom edge of the plot.');
        $this->assertSame(10.0, $chart->points[1]['y'], 'The maximum should sit on the top edge of the plot.');
    }

    #[Test]
    public function it_spreads_points_evenly_across_the_plot_width(): void
    {
        $chart = LineChart::fromSeries(
            $this->series([['2026-01-01', 1.0], ['2026-01-02', 2.0], ['2026-01-03', 3.0]]),
            width: 220,
            height: 100,
            padding: 10,
        );

        $this->assertSame(10.0, $chart->points[0]['x']);
        $this->assertSame(110.0, $chart->points[1]['x']);
        $this->assertSame(210.0, $chart->points[2]['x']);
    }

    #[Test]
    public function a_flat_series_still_renders_instead_of_dividing_by_zero(): void
    {
        $chart = LineChart::fromSeries(
            $this->series([['2026-01-01', 10.0], ['2026-01-02', 10.0], ['2026-01-03', 10.0]])
        );

        $this->assertNotNull($chart);
        $this->assertLessThan($chart->max, $chart->min);

        foreach ($chart->points as $point) {
            $this->assertIsFloat($point['y']);
            $this->assertFalse(is_nan($point['y']), 'A flat series must not produce NaN coordinates.');
        }
    }

    #[Test]
    public function it_builds_a_line_path_beginning_with_a_move_command(): void
    {
        $chart = LineChart::fromSeries($this->series([['2026-01-01', 10.0], ['2026-01-02', 12.0]]));

        $this->assertStringStartsWith('M', $chart->linePath);
        $this->assertStringContainsString(' L', $chart->linePath);
    }

    #[Test]
    public function the_area_path_is_closed_so_it_can_be_filled(): void
    {
        $chart = LineChart::fromSeries($this->series([['2026-01-01', 10.0], ['2026-01-02', 12.0]]));

        $this->assertStringEndsWith('Z', $chart->areaPath);
    }

    #[Test]
    public function it_reports_the_change_across_the_series(): void
    {
        $chart = LineChart::fromSeries($this->series([['2026-01-01', 10.0], ['2026-01-02', 12.50]]));

        $this->assertSame(25.0, $chart->changePercent());
    }

    #[Test]
    public function it_reports_a_negative_change_when_the_price_falls(): void
    {
        $chart = LineChart::fromSeries($this->series([['2026-01-01', 20.0], ['2026-01-02', 15.0]]));

        $this->assertSame(-25.0, $chart->changePercent());
    }

    #[Test]
    public function reading_the_series_twice_does_not_fail_on_the_readonly_points(): void
    {
        // Regression: changePercent() originally used end(), which takes its array
        // by reference and so counted as an indirect write to a readonly property.
        $chart = LineChart::fromSeries($this->series([['2026-01-01', 10.0], ['2026-01-02', 12.0]]));

        $this->assertSame(20.0, $chart->changePercent());
        $this->assertSame(20.0, $chart->changePercent());
        $this->assertSame(12.0, $chart->lastPoint()['value']);
    }

    #[Test]
    public function it_labels_four_grid_lines_spanning_the_value_range(): void
    {
        $chart = LineChart::fromSeries($this->series([['2026-01-01', 10.0], ['2026-01-02', 20.0]]));

        $this->assertCount(4, $chart->gridLines);
        $this->assertSame(20.0, $chart->gridLines[0]['value']);
        $this->assertSame(10.0, $chart->gridLines[3]['value']);
    }
}
