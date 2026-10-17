<?php

namespace App\Http\Controllers;

use App\Actions\ManageStudentInternshipRequest;
use App\Http\Requests\SaveInternshipRequestRequest;
use App\Models\EmancipationEvidence;
use App\Models\InternshipRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InternshipRequestController extends Controller
{
    public function update(SaveInternshipRequestRequest $request, InternshipRequest $internshipRequest, ManageStudentInternshipRequest $action): RedirectResponse
    {
        $operation = $request->routeIs('internship-requests.submit') ? 'submit' : ($request->routeIs('internship-requests.withdraw') ? 'withdraw' : 'save');
        $action->handle($request->user(), $request->integer('context_affiliation_id'), $internshipRequest, $operation,
            Arr::except($request->validated(), ['context_affiliation_id', 'expected_updated_at', 'terms_accepted']), $request->boolean('terms_accepted'), $request->string('expected_updated_at')->toString());

        return to_route('internship-requests.show', $internshipRequest)->with('status', $operation === 'submit' ? 'Solicitação enviada para análise.' : 'Solicitação salva.');
    }

    public function evidence(Request $request, EmancipationEvidence $evidence): BinaryFileResponse
    {
        Gate::authorize('view', $evidence);
        $media = $evidence->getFirstMedia('emancipation_evidence');
        abort_if($media === null, 404);

        return response()->file($media->getPath(), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}
