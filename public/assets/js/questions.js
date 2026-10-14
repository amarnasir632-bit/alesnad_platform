(() => {
  const subject = document.getElementById('question_subject');
  const lesson = document.getElementById('question_lesson');
  const type = document.getElementById('question_type');
  const optionFieldset = document.querySelector('.question-options');
  const tfAnswer = document.querySelector('.question-tf-answer');

  if (subject && lesson) {
    const filterLessons = () => {
      for (const option of lesson.options) {
        option.hidden = option.value !== '' && option.dataset.subject !== subject.value;
      }
      if (lesson.selectedOptions[0]?.hidden) lesson.value = '';
    };
    subject.addEventListener('change', filterLessons);
    filterLessons();
  }

  if (type && optionFieldset && tfAnswer) {
    const optionInputs = optionFieldset.querySelectorAll('input[name="options[]"]');
    const correctInputs = optionFieldset.querySelectorAll('input[name="correct_option"]');
    const tfInput = tfAnswer.querySelector('select');
    const updateType = () => {
      const isMcq = type.value === 'MCQ';
      const isTrueFalse = type.value === 'TRUE_FALSE';
      optionFieldset.hidden = !isMcq;
      tfAnswer.hidden = !isTrueFalse;
      optionInputs.forEach((input, index) => { input.required = isMcq && index < 2; });
      correctInputs.forEach((input, index) => { input.required = isMcq && index === 0; });
      tfInput.required = isTrueFalse;
    };
    type.addEventListener('change', updateType);
    updateType();
  }
})();
