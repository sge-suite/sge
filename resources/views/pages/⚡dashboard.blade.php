<?php

use App\Actions\GetAdministrativeDashboardMetrics;
use App\Actions\GetInternshipOfficeDashboard;
use App\Actions\GetSupervisorDashboard;
use App\Enums\AffiliationType;
use App\Models\User;
use App\Models\InternshipRequest;
use App\Models\Internship;
use App\Support\StudentInternshipContext;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Painel')] class extends Component
{
    /** @return array<string, mixed> */
    #[Computed]
    public function officeOverview(): array
    {
        return app(GetInternshipOfficeDashboard::class)(auth()->user());
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function supervisorOverview(): array
    {
        return app(GetSupervisorDashboard::class)(auth()->user());
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function internshipOverview(): array
    {
        Gate::authorize('viewAny', Internship::class);
        $owner = app(StudentInternshipContext::class)->selected(auth()->user());
        $query = Internship::query()->visibleToAffiliation($owner);
        $counts = (clone $query)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');

        return [
            'role' => $owner->type->label(),
            'campus' => $owner->campus->name,
            'description' => match ($owner->type) {
                AffiliationType::Advisor => 'Acompanhe seus orientandos e consulte as notas dos estágios.',
                AffiliationType::Coordinator => 'Acompanhe os estágios dos cursos que coordena e suas orientações.',
                AffiliationType::TeachingDirection => 'Consulte a situação dos estágios do campus.',
                default => 'Acompanhe os estágios disponíveis para você.',
            },
            'isOffice' => $owner->type === AffiliationType::InternshipOffice,
            'total' => (int) $counts->sum(),
            'inProgress' => (int) $counts->get('in_progress', 0),
            'paused' => (int) $counts->get('paused', 0),
            'completed' => (int) $counts->get('completed', 0),
            'internships' => $query->with(['course', 'advisorAffiliation.user'])->latest('updated_at')->latest('id')->limit(5)->get(),
        ];
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function studentOverview(): array
    {
        Gate::authorize('viewAny', InternshipRequest::class);
        $owner = app(StudentInternshipContext::class)->student(auth()->user());
        $counts = $owner->internshipRequests()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $internships = Internship::query()->where('student_affiliation_id', $owner->id)
            ->whereHas('course', fn ($query) => $query->where('campus_id', $owner->campus_id));

        return [
            'canEdit' => Gate::allows('create', InternshipRequest::class),
            'course' => $owner->course->name,
            'campus' => $owner->campus->name,
            'actionable' => (int) $counts->get('draft', 0) + (int) $counts->get('pending_correction', 0),
            'pending' => (int) $counts->get('submitted', 0) + (int) $counts->get('under_review', 0),
            'internshipsCount' => (clone $internships)->count(),
            'completed' => (clone $internships)->where('status', \App\Enums\InternshipStatus::Completed)->count(),
            'requests' => $owner->internshipRequests()->with(['internshipType', 'grantingParty'])->latest('updated_at')->latest('id')->limit(3)->get(),
            'internships' => $internships->with('advisorAffiliation.user')->latest('updated_at')->latest('id')->limit(3)->get(),
        ];
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function metrics(): array
    {
        Gate::authorize('viewAdministration', User::class);

        return app(GetAdministrativeDashboardMetrics::class)();
    }
}; ?>

@can('viewAdministration', \App\Models\User::class)
    @include('pages.dashboard.partials.system-administrator', ['metrics' => $this->metrics])
@elsecan('viewReviewList', \App\Models\InternshipRequest::class)
    @include('pages.dashboard.partials.internship-office', ['overview' => $this->officeOverview])
@else
    @can('viewAny', \App\Models\InternshipRequest::class)
        @include('pages.dashboard.partials.student', ['overview' => $this->studentOverview])
    @else
        @can('viewAny', \App\Models\Internship::class)
            @include('pages.dashboard.partials.academic', ['overview' => $this->internshipOverview])
        @elsecan('viewAny', \App\Models\SupervisorEvaluation::class)
            @include('pages.dashboard.partials.supervisor', ['overview' => $this->supervisorOverview])
        @else
            @include('pages.dashboard.partials.default')
        @endcan
    @endcan
@endcan
