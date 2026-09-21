<?php

namespace App\Livewire;

use App\Services\Queries\PriceQueries;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('Browse prices — PriceWatch MY')]
class ItemBrowser extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $state = '';

    #[Url(except: '')]
    public string $category = '';

    /**
     * Any change to a filter must send the user back to page one, or a narrow
     * result set leaves them stranded on an empty page 7.
     */
    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'state', 'category'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'state', 'category']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->state !== '' || $this->category !== '';
    }

    public function render(PriceQueries $queries)
    {
        return view('livewire.item-browser', [
            'items' => $queries->searchItems(
                term: $this->search !== '' ? $this->search : null,
                state: $this->state !== '' ? $this->state : null,
                category: $this->category !== '' ? $this->category : null,
            ),
            'states' => $queries->states(),
            'categories' => $queries->categories(),
            'coverage' => $queries->coverage(),
        ]);
    }
}
