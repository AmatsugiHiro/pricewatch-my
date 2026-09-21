<?php

namespace Tests\Feature;

use App\Livewire\PipelineDashboard;
use App\Models\IngestionRun;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PipelineDashboardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_prompts_for_a_sync_when_nothing_has_run(): void
    {
        Livewire::test(PipelineDashboard::class)
            ->assertOk()
            ->assertSee('No ingestion runs recorded yet');
    }

    #[Test]
    public function it_lists_recent_runs_with_their_figures(): void
    {
        IngestionRun::factory()->create([
            'period' => '2026-08',
            'rows_read' => 1_933_285,
            'rows_upserted' => 1_901_122,
            'rows_quarantined' => 32_163,
            'duration_ms' => 145_900,
            'peak_memory_bytes' => 46_137_344,
        ]);

        Livewire::test(PipelineDashboard::class)
            ->assertSee('2026-08')
            ->assertSee('1,933,285')
            ->assertSee('1,901,122')
            ->assertSee('32,163')
            ->assertSee('44.0 MB');
    }

    #[Test]
    public function a_failed_run_shows_its_error(): void
    {
        IngestionRun::factory()->failed('Failed to download (HTTP 404).')->create();

        Livewire::test(PipelineDashboard::class)
            ->assertSee('failed')
            ->assertSee('Failed to download (HTTP 404).');
    }

    #[Test]
    public function it_surfaces_reference_codes_the_publisher_never_defined(): void
    {
        IngestionRun::factory()->create([
            'period' => '2026-08',
            'unknown_codes' => ['items' => [2057, 2094], 'premises' => []],
        ]);

        Livewire::test(PipelineDashboard::class)
            ->assertSee('Unresolvable reference codes')
            ->assertSee('2057')
            ->assertSee('2094');
    }

    #[Test]
    public function a_code_the_publisher_has_since_defined_is_marked_resolved(): void
    {
        Item::factory()->withCode(2057)->create(['item' => 'A LATE ARRIVAL']);

        IngestionRun::factory()->create([
            'period' => '2026-08',
            'unknown_codes' => ['items' => [2057, 2094], 'premises' => []],
        ]);

        Livewire::test(PipelineDashboard::class)
            ->assertSee('since published');
    }

    #[Test]
    public function it_says_nothing_about_unknown_codes_when_everything_resolved(): void
    {
        IngestionRun::factory()->create(['unknown_codes' => null]);

        Livewire::test(PipelineDashboard::class)
            ->assertDontSee('Unresolvable reference codes');
    }
}
