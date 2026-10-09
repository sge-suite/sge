<?php

namespace App\Http\Controllers;

use App\Actions\ManageCourse;
use App\Http\Requests\DeactivateCourseRequest;
use App\Http\Requests\ReactivateCourseRequest;
use App\Http\Requests\StoreCourseRequest;
use App\Http\Requests\UpdateCourseRequest;
use App\Models\Course;
use Illuminate\Http\RedirectResponse;

class CourseController extends Controller
{
    public function __construct(private ManageCourse $manageCourse) {}

    public function store(StoreCourseRequest $request): RedirectResponse
    {
        $course = $this->manageCourse->handle($request->user(), null, 'create', $request->validated());

        return redirect()->route('courses.show', $course)->with('status', 'Curso cadastrado com sucesso.');
    }

    public function update(UpdateCourseRequest $request, Course $course): RedirectResponse
    {
        $this->manageCourse->handle($request->user(), $course, 'update', $request->validated());

        return redirect()->route('courses.show', $course)->with('status', 'Curso atualizado com sucesso.');
    }

    public function deactivate(DeactivateCourseRequest $request, Course $course): RedirectResponse
    {
        $this->manageCourse->handle($request->user(), $course, 'deactivate', $request->validated());

        return redirect()->route('courses.show', $course)->with('status', 'Curso desativado com sucesso.');
    }

    public function reactivate(ReactivateCourseRequest $request, Course $course): RedirectResponse
    {
        $this->manageCourse->handle($request->user(), $course, 'reactivate');

        return redirect()->route('courses.show', $course)->with('status', 'Curso reativado com sucesso.');
    }
}
