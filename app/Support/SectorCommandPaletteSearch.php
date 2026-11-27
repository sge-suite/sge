<?php

namespace App\Support;

use App\Enums\AffiliationType;
use App\Helpers\BrazilianDocumentHelper;
use App\Models\Course;
use App\Models\DocumentTemplate;
use App\Models\GrantingParty;
use App\Models\Internship;
use App\Models\InternshipRequest;
use App\Models\InternshipType;
use App\Models\User;
use Meilisearch\Client;
use Meilisearch\Contracts\SearchQuery as MeilisearchQuery;

final class SectorCommandPaletteSearch
{
    public function __construct(
        private ActiveAffiliationContext $affiliationContext,
        private UserManagementScope $userManagementScope,
        private SearchQuery $searchQuery,
        private Client $meilisearch,
    ) {}

    /**
     * @return array{
     *     unavailable: bool,
     *     groups: list<array{
     *         key: string,
     *         label: string,
     *         items: list<array{
     *             id: string,
     *             title: string,
     *             subtitle: string,
     *             badge: ?string,
     *             href: string,
     *             icon: string,
     *         }>,
     *     }>,
     * }
     */
    public function search(User $actor, string $term): array
    {
        $affiliation = $this->affiliationContext->currentFor($actor, app('session.store'));

        abort_unless(
            $affiliation?->type === AffiliationType::InternshipOffice && $affiliation->campus_id !== null,
            403,
        );

        $trimmed = trim($term);
        if (mb_strlen($trimmed) < 2) {
            return ['unavailable' => false, 'groups' => []];
        }

        $campusId = (int) $affiliation->campus_id;

        /** @var list<string> $keys */
        $keys = [];
        /** @var list<MeilisearchQuery> $queries */
        $queries = [];

        if ($actor->can('viewAny', Internship::class)) {
            $keys[] = 'internships';
            $queries[] = (new MeilisearchQuery)
                ->setIndexUid((new Internship)->searchableAs())
                ->setQuery($trimmed)
                ->setLimit(5)
                ->setFilter(['campus_id = '.$campusId])
                ->setAttributesToRetrieve(['id']);
        }

        if ($actor->can('viewReviewList', InternshipRequest::class)) {
            $keys[] = 'requests';
            $queries[] = (new MeilisearchQuery)
                ->setIndexUid((new InternshipRequest)->searchableAs())
                ->setQuery($trimmed)
                ->setLimit(5)
                ->setFilter(['campus_id = '.$campusId])
                ->setAttributesToRetrieve(['id']);
        }

        if ($actor->can('viewAny', User::class)) {
            $scope = $this->userManagementScope->searchScope($actor);
            $keys[] = 'users';
            $queries[] = (new MeilisearchQuery)
                ->setIndexUid((new User)->searchableAs())
                ->setQuery($trimmed)
                ->setLimit(5)
                ->setFilter(['affiliation_scopes = "'.$scope.'"'])
                ->setAttributesToRetrieve(['id']);
        }

        if ($actor->can('viewAny', GrantingParty::class)) {
            $keys[] = 'granting_parties';
            $queries[] = (new MeilisearchQuery)
                ->setIndexUid((new GrantingParty)->searchableAs())
                ->setQuery($trimmed)
                ->setLimit(5)
                ->setFilter(['campus_id = '.$campusId])
                ->setAttributesToRetrieve(['id']);
        }

        if ($actor->can('viewAny', Course::class)) {
            $keys[] = 'courses';
            $queries[] = (new MeilisearchQuery)
                ->setIndexUid((new Course)->searchableAs())
                ->setQuery($trimmed)
                ->setLimit(4)
                ->setFilter(['campus_id = '.$campusId])
                ->setAttributesToRetrieve(['id']);
        }

        if ($actor->can('viewAny', InternshipType::class)) {
            $keys[] = 'internship_types';
            $queries[] = (new MeilisearchQuery)
                ->setIndexUid((new InternshipType)->searchableAs())
                ->setQuery($trimmed)
                ->setLimit(4)
                ->setFilter(['campus_id = '.$campusId])
                ->setAttributesToRetrieve(['id']);
        }

        if ($actor->can('viewAny', DocumentTemplate::class)) {
            $keys[] = 'document_templates';
            $queries[] = (new MeilisearchQuery)
                ->setIndexUid((new DocumentTemplate)->searchableAs())
                ->setQuery($trimmed)
                ->setLimit(4)
                ->setFilter(['campus_id = '.$campusId])
                ->setAttributesToRetrieve(['id']);
        }

        if ($queries === []) {
            return ['unavailable' => false, 'groups' => []];
        }

        try {
            /** @var array{results?: array<int, array{hits?: array<int, array{id?: int|string}>}>} $response */
            $response = $this->searchQuery->run(fn (): mixed => $this->meilisearch->multiSearch($queries));
        } catch (SearchUnavailable) {
            return ['unavailable' => true, 'groups' => []];
        }

        $results = $response['results'] ?? [];
        $idsByKey = [];
        foreach ($keys as $index => $key) {
            $hits = $results[$index]['hits'] ?? [];
            $ids = [];
            foreach ($hits as $hit) {
                if (isset($hit['id']) && is_numeric($hit['id'])) {
                    $ids[] = (int) $hit['id'];
                }
            }
            $idsByKey[$key] = $ids;
        }

        $groups = [];

        if (($idsByKey['internships'] ?? []) !== []) {
            $items = $this->hydrateInternships($idsByKey['internships']);
            if ($items !== []) {
                $groups[] = ['key' => 'internships', 'label' => 'Estágios', 'items' => $items];
            }
        }

        if (($idsByKey['requests'] ?? []) !== []) {
            $items = $this->hydrateRequests($idsByKey['requests']);
            if ($items !== []) {
                $groups[] = ['key' => 'requests', 'label' => 'Solicitações de estágio', 'items' => $items];
            }
        }

        if (($idsByKey['users'] ?? []) !== []) {
            $items = $this->hydrateUsers($idsByKey['users']);
            if ($items !== []) {
                $groups[] = ['key' => 'users', 'label' => 'Usuários', 'items' => $items];
            }
        }

        if (($idsByKey['granting_parties'] ?? []) !== []) {
            $items = $this->hydrateGrantingParties($idsByKey['granting_parties']);
            if ($items !== []) {
                $groups[] = ['key' => 'granting_parties', 'label' => 'Partes concedentes', 'items' => $items];
            }
        }

        if (($idsByKey['courses'] ?? []) !== []) {
            $items = $this->hydrateCourses($idsByKey['courses']);
            if ($items !== []) {
                $groups[] = ['key' => 'courses', 'label' => 'Cursos', 'items' => $items];
            }
        }

        if (($idsByKey['internship_types'] ?? []) !== []) {
            $items = $this->hydrateInternshipTypes($idsByKey['internship_types']);
            if ($items !== []) {
                $groups[] = ['key' => 'internship_types', 'label' => 'Tipos de estágio', 'items' => $items];
            }
        }

        if (($idsByKey['document_templates'] ?? []) !== []) {
            $items = $this->hydrateDocumentTemplates($idsByKey['document_templates']);
            if ($items !== []) {
                $groups[] = ['key' => 'document_templates', 'label' => 'Templates DOCX', 'items' => $items];
            }
        }

        return ['unavailable' => false, 'groups' => $groups];
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{id: string, title: string, subtitle: string, badge: ?string, href: string, icon: string}>
     */
    private function hydrateInternships(array $ids): array
    {
        $models = Internship::with(['course', 'grantingParty'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $items = [];
        foreach ($ids as $id) {
            $internship = $models->get($id);
            if (! $internship instanceof Internship) {
                continue;
            }

            $studentName = is_string($internship->student_snapshot['name'] ?? null) && $internship->student_snapshot['name'] !== ''
                ? $internship->student_snapshot['name']
                : 'Estágio #'.$internship->id;

            $parts = array_filter([
                $internship->course?->name,
                $internship->grantingParty?->name,
            ]);

            $items[] = [
                'id' => 'internship-'.$internship->id,
                'title' => $studentName,
                'subtitle' => implode(' · ', $parts),
                'badge' => $internship->status->label(),
                'href' => route('internships.show', $internship),
                'icon' => 'briefcase',
            ];
        }

        return $items;
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{id: string, title: string, subtitle: string, badge: ?string, href: string, icon: string}>
     */
    private function hydrateRequests(array $ids): array
    {
        $models = InternshipRequest::with(['course'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $items = [];
        foreach ($ids as $id) {
            $request = $models->get($id);
            if (! $request instanceof InternshipRequest) {
                continue;
            }

            $studentName = is_string($request->student_snapshot['name'] ?? null) && $request->student_snapshot['name'] !== ''
                ? $request->student_snapshot['name']
                : 'Solicitação #'.$request->id;

            $registration = is_string($request->student_snapshot['registration_number'] ?? null)
                ? $request->student_snapshot['registration_number']
                : null;

            $parts = array_filter([
                $request->course?->name,
                $registration,
            ]);

            $items[] = [
                'id' => 'request-'.$request->id,
                'title' => $studentName,
                'subtitle' => implode(' · ', $parts),
                'badge' => $request->status->label(),
                'href' => route('internship-review.show', $request),
                'icon' => 'clipboard-document-check',
            ];
        }

        return $items;
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{id: string, title: string, subtitle: string, badge: ?string, href: string, icon: string}>
     */
    private function hydrateUsers(array $ids): array
    {
        $models = User::whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $items = [];
        foreach ($ids as $id) {
            $user = $models->get($id);
            if (! $user instanceof User) {
                continue;
            }

            $items[] = [
                'id' => 'user-'.$user->id,
                'title' => $user->name,
                'subtitle' => $user->email,
                'badge' => null,
                'href' => route('users.show', $user),
                'icon' => 'user',
            ];
        }

        return $items;
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{id: string, title: string, subtitle: string, badge: ?string, href: string, icon: string}>
     */
    private function hydrateGrantingParties(array $ids): array
    {
        $models = GrantingParty::whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $items = [];
        foreach ($ids as $id) {
            $party = $models->get($id);
            if (! $party instanceof GrantingParty) {
                continue;
            }

            $items[] = [
                'id' => 'granting-party-'.$party->id,
                'title' => $party->name,
                'subtitle' => BrazilianDocumentHelper::formatCpfCnpj($party->document_number),
                'badge' => $party->deactivated_at === null ? null : 'Desativada',
                'href' => route('granting-parties.show', $party),
                'icon' => 'building-office',
            ];
        }

        return $items;
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{id: string, title: string, subtitle: string, badge: ?string, href: string, icon: string}>
     */
    private function hydrateCourses(array $ids): array
    {
        $models = Course::whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $items = [];
        foreach ($ids as $id) {
            $course = $models->get($id);
            if (! $course instanceof Course) {
                continue;
            }

            $items[] = [
                'id' => 'course-'.$course->id,
                'title' => $course->name,
                'subtitle' => $course->coordination_email,
                'badge' => $course->deactivated_at === null ? null : 'Desativado',
                'href' => route('courses.show', $course),
                'icon' => 'academic-cap',
            ];
        }

        return $items;
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{id: string, title: string, subtitle: string, badge: ?string, href: string, icon: string}>
     */
    private function hydrateInternshipTypes(array $ids): array
    {
        $models = InternshipType::with('course')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $items = [];
        foreach ($ids as $id) {
            $type = $models->get($id);
            if (! $type instanceof InternshipType) {
                continue;
            }

            $items[] = [
                'id' => 'internship-type-'.$type->id,
                'title' => $type->name,
                'subtitle' => implode(' · ', [$type->course->name, $type->required_hours.'h']),
                'badge' => $type->deactivated_at === null ? null : 'Desativado',
                'href' => route('internship-types.show', $type),
                'icon' => 'briefcase',
            ];
        }

        return $items;
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{id: string, title: string, subtitle: string, badge: ?string, href: string, icon: string}>
     */
    private function hydrateDocumentTemplates(array $ids): array
    {
        $models = DocumentTemplate::whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $items = [];
        foreach ($ids as $id) {
            $template = $models->get($id);
            if (! $template instanceof DocumentTemplate) {
                continue;
            }

            $items[] = [
                'id' => 'document-template-'.$template->id,
                'title' => $template->name,
                'subtitle' => $template->document_type->label(),
                'badge' => $template->deactivated_at === null ? null : 'Desativado',
                'href' => route('document-templates.show', $template),
                'icon' => 'document-text',
            ];
        }

        return $items;
    }
}
