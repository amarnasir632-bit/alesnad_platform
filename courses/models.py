from django.conf import settings
from django.db import models


class Course(models.Model):
    title = models.CharField("اسم الدورة", max_length=200)
    summary = models.CharField("نبذة قصيرة", max_length=300, blank=True)
    description = models.TextField("وصف الدورة", blank=True)
    instructor = models.CharField("المعلم", max_length=150, blank=True)
    is_published = models.BooleanField("منشورة", default=False)
    created_at = models.DateTimeField("تاريخ الإنشاء", auto_now_add=True)

    class Meta:
        ordering = ["-created_at"]
        verbose_name = "دورة"
        verbose_name_plural = "الدورات"

    def __str__(self):
        return self.title


class Lesson(models.Model):
    course = models.ForeignKey(Course, related_name="lessons", on_delete=models.CASCADE, verbose_name="الدورة")
    title = models.CharField("عنوان الدرس", max_length=200)
    content = models.TextField("محتوى الدرس", blank=True)
    video_url = models.URLField("رابط الفيديو", blank=True)
    position = models.PositiveIntegerField("الترتيب", default=1)

    class Meta:
        ordering = ["position", "id"]
        verbose_name = "درس"
        verbose_name_plural = "الدروس"

    def __str__(self):
        return f"{self.course}: {self.title}"


class Enrollment(models.Model):
    student = models.ForeignKey(settings.AUTH_USER_MODEL, related_name="enrollments", on_delete=models.CASCADE, verbose_name="الطالب")
    course = models.ForeignKey(Course, related_name="enrollments", on_delete=models.CASCADE, verbose_name="الدورة")
    enrolled_at = models.DateTimeField("تاريخ التسجيل", auto_now_add=True)

    class Meta:
        ordering = ["-enrolled_at"]
        constraints = [models.UniqueConstraint(fields=["student", "course"], name="unique_student_course_enrollment")]
        verbose_name = "اشتراك"
        verbose_name_plural = "الاشتراكات"

    def __str__(self):
        return f"{self.student} — {self.course}"
