(() => {
  const form = document.getElementById('school-schedule-form');
  if (!form) return;

  const type = document.getElementById('schedule-entry-type');
  const recurrence = document.getElementById('schedule-recurrence');
  const classSelect = document.getElementById('schedule-class');
  const subject = document.getElementById('schedule-subject');
  const teacher = document.getElementById('schedule-teacher');
  const day = document.getElementById('schedule-day');
  const date = document.getElementById('schedule-date');
  const subjectField = document.getElementById('schedule-subject-field');
  const teacherField = document.getElementById('schedule-teacher-field');
  const titleField = document.getElementById('schedule-break-title-field');
  const dayField = document.getElementById('schedule-day-field');
  const dateField = document.getElementById('schedule-date-field');
  const subjectOptions = [...subject.options].slice(1);
  const teacherOptions = [...teacher.options].slice(1);

  function updateSubjects() {
    const selectedClass = classSelect.value;
    let firstAvailable = '';
    for (const option of subjectOptions) {
      const available = selectedClass !== '' && option.dataset.classId === selectedClass;
      option.hidden = !available;
      option.disabled = !available;
      if (available && firstAvailable === '') firstAvailable = option.value;
    }
    if (!subjectOptions.some((option) => option.value === subject.value && !option.disabled)) {
      subject.value = firstAvailable;
    }
    updateTeachers();
  }

  function updateTeachers() {
    const selectedSubject = subject.value;
    let firstAvailable = '';
    for (const option of teacherOptions) {
      const available = selectedSubject !== '' && option.dataset.classSubjectId === selectedSubject;
      option.hidden = !available;
      option.disabled = !available;
      if (available && firstAvailable === '') firstAvailable = option.value;
    }
    if (!teacherOptions.some((option) => option.value === teacher.value && !option.disabled)) {
      teacher.value = firstAvailable;
    }
  }

  function updateForm() {
    const lesson = type.value === 'LESSON';
    const weekly = recurrence.value === 'WEEKLY';
    subjectField.hidden = !lesson;
    teacherField.hidden = !lesson;
    titleField.hidden = lesson;
    subject.required = lesson;
    teacher.required = lesson;
    classSelect.required = lesson;
    document.getElementById('schedule-break-title').required = !lesson;
    document.getElementById('schedule-break-title').disabled = lesson;
    dayField.hidden = !weekly;
    dateField.hidden = weekly;
    day.disabled = !weekly;
    day.required = weekly;
    date.disabled = weekly;
    date.required = !weekly;
    updateSubjects();
  }

  classSelect.addEventListener('change', updateSubjects);
  subject.addEventListener('change', updateTeachers);
  type.addEventListener('change', updateForm);
  recurrence.addEventListener('change', updateForm);
  updateForm();

  for (const confirmForm of document.querySelectorAll('form[data-confirm]')) {
    confirmForm.addEventListener('submit', (event) => {
      if (!window.confirm(confirmForm.dataset.confirm)) event.preventDefault();
    });
  }
})();
