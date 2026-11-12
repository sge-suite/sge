<?php

use App\Models\GrantingParty;
use App\Support\StudentInternshipContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Local de estágio')] class extends Component
{
    #[Locked]
    public int $affiliationId;

    #[Locked]
    public int $grantingPartyId;

    public function mount(GrantingParty $grantingParty, StudentInternshipContext $context): void
    {
        Gate::authorize('viewStudentLocation', $grantingParty);
        $this->affiliationId = $context->student(Auth::user())->id;
        $this->grantingPartyId = $grantingParty->id;
    }

    public function hydrate(StudentInternshipContext $context): void
    {
        abort_unless($context->student(Auth::user())?->id === $this->affiliationId, 403);
        Gate::authorize('viewStudentLocation', GrantingParty::findOrFail($this->grantingPartyId, ['id', 'campus_id', 'deactivated_at']));
    }

    /** @return array{name: string, municipality: string, courses: list<string>} */
    #[Computed]
    public function location(): array
    {
        $party = GrantingParty::query()->with('address:id,city_id', 'address.city:id,name,state')
            ->findOrFail($this->grantingPartyId, ['id', 'name', 'campus_id', 'address_id', 'deactivated_at']);
        Gate::authorize('viewStudentLocation', $party);

        return [
            'name' => $party->name,
            'municipality' => $party->address->city->name.' / '.$party->address->city->state->value,
            'courses' => $party->coursesWithStartedInternships(),
        ];
    }
};
?>

<div class="w-full space-y-8">
    @php($location = $this->location)
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('internship-locations.index')" wire:navigate>Locais de estágio</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $location['name'] }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <flux:heading size="xl" level="1" class="wrap-break-word">{{ $location['name'] }}</flux:heading>
            <flux:text class="mt-2">{{ $location['municipality'] }}</flux:text>
        </div>
        <flux:button variant="outline" icon="arrow-left" :href="route('internship-locations.index')" wire:navigate>Voltar</flux:button>
    </div>
    <section class="border-t border-zinc-200 pt-6 dark:border-zinc-700">
        <flux:heading level="2" size="lg">Cursos com estágio iniciado</flux:heading>
        @if ($location['courses'] === [])
            <flux:text class="mt-2">Sem registro de estágio iniciado neste local.</flux:text>
        @else
            <ul class="mt-4 list-disc space-y-2 ps-5 text-sm">
                @foreach ($location['courses'] as $course)
                    <li wire:key="location-course-{{ $loop->index }}">{{ $course }}</li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
