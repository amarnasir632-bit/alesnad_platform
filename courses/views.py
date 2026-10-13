from django.contrib import messages
from django.contrib.auth.decorators import login_required
from django.shortcuts import get_object_or_404, redirect, render

from .models import Course, Enrollment


def course_list(request):
    courses = Course.objects.filter(is_published=True).prefetch_related("lessons")
    return render(request, "courses/course_list.html", {"courses": courses})


def course_detail(request, pk):
    course = get_object_or_404(Course, pk=pk, is_published=True)
    enrolled = request.user.is_authenticated and Enrollment.objects.filter(student=request.user, course=course).exists()
    return render(request, "courses/course_detail.html", {"course": course, "enrolled": enrolled})


@login_required
def enroll(request, pk):
    if request.method != "POST":
        return redirect("courses:detail", pk=pk)
    course = get_object_or_404(Course, pk=pk, is_published=True)
    _, created = Enrollment.objects.get_or_create(student=request.user, course=course)
    messages.success(request, "تم تسجيلك في الدورة." if created else "أنت مسجل في هذه الدورة بالفعل.")
    return redirect("courses:detail", pk=course.pk)
