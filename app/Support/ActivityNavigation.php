<?php

namespace App\Support;

use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\Course;
use App\Models\DocumentTemplate;
use App\Models\EmancipationEvidence;
use App\Models\GeneratedDocument;
use App\Models\GrantingParty;
use App\Models\Internship;
use App\Models\InternshipCalendarOverride;
use App\Models\InternshipPause;
use App\Models\InternshipRequest;
use App\Models\InternshipRequestCorrection;
use App\Models\InternshipType;
use App\Models\TemplateVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ActivityNavigation
{
    /** @var array<string, string|null> */
    private array $referenceUrls = [];

    public function __construct(private StudentInternshipContext $context) {}

    public function forModel(?Model $model): ?string
    {
        if ($model === null || ! $model->exists || auth()->user() === null) {
            return null;
        }
        if ($model instanceof Affiliation) {
            $selected = $this->context->selected(auth()->user());
            if (! Gate::allows('view', $model)
                && ! ($selected?->type === AffiliationType::InternshipOffice && $selected->campus_id === $model->campus_id)) {
                return null;
            }

            return $this->forModel($model->user);
        }
        if ($model instanceof TemplateVersion) {
            $url = $this->forModel($model->documentTemplate);

            return $url === null ? null : $url.'#template-versions-heading';
        }
        if ($model instanceof GeneratedDocument) {
            if (! Gate::allows('view', $model)) {
                return null;
            }
            $url = $this->forModel($model->internship);

            return $url === null ? null : $url.'#documents-heading';
        }
        if ($model instanceof InternshipPause || $model instanceof InternshipCalendarOverride) {
            return $this->forModel($model->internship);
        }
        if ($model instanceof InternshipRequestCorrection || $model instanceof EmancipationEvidence) {
            return $this->forModel($model->internshipRequest);
        }
        if ($model instanceof InternshipRequest && Gate::allows('review', $model)) {
            return route('internship-review.show', $model);
        }
        if (! Gate::allows('view', $model)) {
            return null;
        }

        return match (true) {
            $model instanceof Campus => Gate::allows('viewAdministration', Campus::class)
                ? route('campuses.show', $model) : route('campuses.own'),
            $model instanceof User => route('users.show', $model),
            $model instanceof Course => route('courses.show', $model),
            $model instanceof GrantingParty => route('granting-parties.show', $model),
            $model instanceof InternshipType => route('internship-types.show', $model),
            $model instanceof DocumentTemplate => route('document-templates.show', $model),
            $model instanceof Internship => route('internships.show', $model),
            $model instanceof InternshipRequest => route('internship-requests.show', $model),
            default => null,
        };
    }

    public function forReference(string $field, mixed $value): ?string
    {
        if ((! is_int($value) && ! is_string($value))
            || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return null;
        }
        $model = match ($field) {
            'user_id' => User::class,
            'campus_id' => Campus::class,
            'course_id' => Course::class,
            'internship_id' => Internship::class,
            'internship_request_id' => InternshipRequest::class,
            'internship_type_id' => InternshipType::class,
            'granting_party_id' => GrantingParty::class,
            'document_template_id' => DocumentTemplate::class,
            'template_version_id', 'previous_version_id', 'current_version_id' => TemplateVersion::class,
            'affiliation_id', 'student_affiliation_id', 'advisor_affiliation_id', 'supervisor_affiliation_id',
            'primary_coordinator_affiliation_id', 'secondary_coordinator_affiliation_id',
            'released_by_affiliation_id', 'uploaded_by_affiliation_id',
            'report_graded_by_affiliation_id', 'presentation_graded_by_affiliation_id' => Affiliation::class,
            default => null,
        };

        if ($model === null) {
            return null;
        }
        $key = $model.':'.$value;
        if (! array_key_exists($key, $this->referenceUrls)) {
            $this->referenceUrls[$key] = $this->forModel($model::query()->find($value));
        }

        return $this->referenceUrls[$key];
    }

    public function backUrl(mixed $candidate): string
    {
        $fallback = route('audit.index');
        if (! is_string($candidate) || preg_match('/[\x00-\x20\x7f\\\\]/', $candidate) === 1) {
            return $fallback;
        }
        $parts = parse_url($candidate);
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])
            || ($parts['scheme'] ?? null) !== request()->getScheme()
            || ($parts['host'] ?? null) !== request()->getHost()
            || ($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80)) !== request()->getPort()) {
            return $fallback;
        }
        try {
            $route = Route::getRoutes()->match(Request::create($candidate, 'GET'));
        } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            return $fallback;
        }
        $destination = match ($route->getName()) {
            'audit.index' => $fallback,
            'campuses.own' => Gate::allows('viewOwn', Campus::class) ? route('campuses.own') : null,
            'campuses.show' => $this->forReference('campus_id', $route->parameter('campus')),
            'courses.show' => $this->forReference('course_id', $route->parameter('course')),
            'users.show' => $this->forReference('user_id', $route->parameter('user')),
            'granting-parties.show' => $this->forReference('granting_party_id', $route->parameter('grantingParty')),
            'internship-types.show' => $this->forReference('internship_type_id', $route->parameter('internshipType')),
            'document-templates.show' => $this->forReference('document_template_id', $route->parameter('documentTemplate')),
            'internships.show' => $this->forReference('internship_id', $route->parameter('internship')),
            'internship-review.show', 'internship-requests.show' => $this->forReference('internship_request_id', $route->parameter('internshipRequest')),
            default => null,
        };
        if ($destination === null || parse_url($destination, PHP_URL_PATH) !== ($parts['path'] ?? '/')) {
            return $fallback;
        }

        return $candidate;
    }
}
