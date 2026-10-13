from django.shortcuts import render

from courses.models import Course


def home(request):
    featured_courses = Course.objects.filter(is_published=True).order_by("-created_at")[:3]
    return render(request, "core/home.html", {"featured_courses": featured_courses})
