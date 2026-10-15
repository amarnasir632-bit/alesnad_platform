/**
 * منصة الإسناد الأكاديمي - السكربت العام للواجهة والتفاعل
 * يدعم:
 * 1. القائمة الجانبية (Sidebar) وشاشات الهاتف (Drawer) مع إمكانية اللمس والسحب
 * 2. النماذج متعددة الخطوات (Multi-step Wizard) مع شريط تقدم وتحقق فوري
 * 3. تحسينات إمكانية الوصول ولمس كبار السن
 */

(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    initSidebar();
    initMultiStepForms();
    initTouchEnhancements();
    initTabs();
    initWhatsAppChat();
  });

  /* -------------------------------------------------------------
   * 1. القائمة الجانبية وشاشات الهاتف (Sidebar & Mobile Drawer)
   * ------------------------------------------------------------- */
  function initSidebar() {
    const sidebar = document.getElementById('app-sidebar');
    const toggleBtn = document.getElementById('sidebar-toggle-btn');
    const closeBtn = document.getElementById('sidebar-close-btn');
    const backdrop = document.getElementById('sidebar-backdrop');

    if (!sidebar) return;

    function openSidebar() {
      sidebar.classList.add('is-open');
      if (backdrop) backdrop.classList.add('is-active');
      if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'true');
      document.body.classList.add('sidebar-locked');
    }

    function closeSidebar() {
      sidebar.classList.remove('is-open');
      if (backdrop) backdrop.classList.remove('is-active');
      if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'false');
      document.body.classList.remove('sidebar-locked');
    }

    if (toggleBtn) {
      toggleBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        if (sidebar.classList.contains('is-open')) {
          closeSidebar();
        } else {
          openSidebar();
        }
      });
    }

    if (closeBtn) {
      closeBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        closeSidebar();
      });
    }

    if (backdrop) {
      backdrop.addEventListener('click', closeSidebar);
    }

    // إغلاق القائمة عند الضغط على مفتاح Escape
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && sidebar.classList.contains('is-open')) {
        closeSidebar();
      }
    });

    // إغلاق القائمة الجانبية في الهاتف عند النقر على رابط التنقل
    const navLinks = sidebar.querySelectorAll('.nav-item-link');
    navLinks.forEach(function (link) {
      link.addEventListener('click', function () {
        if (window.innerWidth <= 992) {
          closeSidebar();
        }
      });
    });

    // دعم إيماءات السحب باللمس (Swipe to close) لليمين
    let touchStartX = 0;
    sidebar.addEventListener('touchstart', function (e) {
      touchStartX = e.touches[0].clientX;
    }, { passive: true });

    sidebar.addEventListener('touchend', function (e) {
      const touchEndX = e.changedTouches[0].clientX;
      // في اللغة العربية (RTL)، السحب إلى اليمين (touchEndX > touchStartX) يعني إرجاع القائمة لليمين لإغلاقها
      if (touchEndX - touchStartX > 60) {
        closeSidebar();
      }
    }, { passive: true });
  }

  /* -------------------------------------------------------------
   * 2. معالج النماذج متعددة الخطوات (Multi-step Form Wizard)
   * ------------------------------------------------------------- */
  function initMultiStepForms() {
    const multiForms = document.querySelectorAll('.multi-step-form');

    multiForms.forEach(function (form) {
      setupFormWizard(form);
    });
  }

  function setupFormWizard(form) {
    const steps = Array.from(form.querySelectorAll('.form-step'));
    if (steps.length <= 1) return;

    let currentStepIndex = 0;

    // استخراج عناوين الخطوات
    const stepTitles = steps.map(function (step, idx) {
      return step.getAttribute('data-step-title') || ('الخطوة ' + (idx + 1));
    });

    // إنشاء عناصر شريط التقدم إذا لم تكن موجودة مسبقاً
    let progressContainer = form.querySelector('.wizard-progress');
    if (!progressContainer) {
      progressContainer = document.createElement('div');
      progressContainer.className = 'wizard-progress';
      progressContainer.setAttribute('aria-label', 'شريط تقدم النموذج');
      form.insertBefore(progressContainer, steps[0]);
    }

    // بناء واجهة شريط التقدم
    progressContainer.innerHTML = `
      <div class="wizard-header">
        <span class="wizard-step-counter">الخطوة <strong class="current-step-num">1</strong> من <strong>${steps.length}</strong></span>
        <span class="wizard-current-title">${escapeHtml(stepTitles[0])}</span>
      </div>
      <div class="wizard-track" role="progressbar" aria-valuenow="1" aria-valuemin="1" aria-valuemax="${steps.length}">
        <div class="wizard-fill" style="width: ${(1 / steps.length) * 100}%;"></div>
      </div>
      <div class="wizard-badges">
        ${stepTitles.map(function (title, idx) {
          return `
            <div class="wizard-badge ${idx === 0 ? 'is-active' : ''}" data-step-target="${idx}">
              <div class="badge-circle">${idx + 1}</div>
              <span class="badge-title">${escapeHtml(title)}</span>
            </div>
          `;
        }).join('')}
      </div>
    `;

    const fillBar = progressContainer.querySelector('.wizard-fill');
    const counterNum = progressContainer.querySelector('.current-step-num');
    const currentTitleSpan = progressContainer.querySelector('.wizard-current-title');
    const badges = progressContainer.querySelectorAll('.wizard-badge');

    // إعداد أزرار التنقل لكل خطوة
    steps.forEach(function (step, idx) {
      let actionsContainer = step.querySelector('.step-actions');
      if (!actionsContainer) {
        actionsContainer = document.createElement('div');
        actionsContainer.className = 'step-actions';
        step.appendChild(actionsContainer);
      }

      // إضافة زر "السابق" إذا لم نكن في الخطوة الأولى
      if (idx > 0 && !actionsContainer.querySelector('.btn-step-prev')) {
        const prevBtn = document.createElement('button');
        prevBtn.type = 'button';
        prevBtn.className = 'button button-secondary btn-step-prev';
        prevBtn.innerHTML = '<span>→ الخطوة السابقة</span>';
        prevBtn.addEventListener('click', function () {
          goToStep(currentStepIndex - 1);
        });
        actionsContainer.appendChild(prevBtn);
      }

      // إضافة زر "التالي" إذا لم نكن في الخطوة الأخيرة
      if (idx < steps.length - 1 && !actionsContainer.querySelector('.btn-step-next')) {
        const nextBtn = document.createElement('button');
        nextBtn.type = 'button';
        nextBtn.className = 'button button-primary btn-step-next';
        nextBtn.innerHTML = '<span>المتابعة للخطوة التالية ←</span>';
        nextBtn.addEventListener('click', function () {
          if (validateStep(step)) {
            goToStep(currentStepIndex + 1);
          }
        });
        actionsContainer.appendChild(nextBtn);
      }
    });

    function goToStep(targetIndex) {
      if (targetIndex < 0 || targetIndex >= steps.length) return;

      // إخفاء الخطوة الحالية
      steps[currentStepIndex].classList.remove('is-active');
      steps[currentStepIndex].style.display = 'none';

      // إظهار الخطوة المستهدفة
      currentStepIndex = targetIndex;
      steps[currentStepIndex].classList.add('is-active');
      steps[currentStepIndex].style.display = 'block';

      // تحديث شريط التقدم
      const progressPercent = ((currentStepIndex + 1) / steps.length) * 100;
      if (fillBar) fillBar.style.width = progressPercent + '%';
      if (counterNum) counterNum.textContent = String(currentStepIndex + 1);
      if (currentTitleSpan) currentTitleSpan.textContent = stepTitles[currentStepIndex];

      badges.forEach(function (badge, bIdx) {
        badge.classList.remove('is-active', 'is-completed');
        if (bIdx < currentStepIndex) {
          badge.classList.add('is-completed');
        } else if (bIdx === currentStepIndex) {
          badge.classList.add('is-active');
        }
      });

      // تمرير ناعم لأعلى النموذج في شاشات الهاتف لراحة المستخدم
      const formTop = form.getBoundingClientRect().top + window.pageYOffset - 80;
      if (window.pageYOffset > formTop) {
        window.scrollTo({ top: Math.max(0, formTop), behavior: 'smooth' });
      }

      // التركيز على أول حقل في الخطوة
      const firstInput = steps[currentStepIndex].querySelector('input:not([type="hidden"]), select, textarea');
      if (firstInput) {
        firstInput.focus();
      }
    }

    function validateStep(step) {
      const inputs = step.querySelectorAll('input:not([type="hidden"]), select, textarea');
      let isValid = true;
      let firstInvalidInput = null;

      // إزالة رسائل الخطأ السابقة
      step.querySelectorAll('.step-field-error').forEach(function (el) {
        el.remove();
      });

      inputs.forEach(function (input) {
        input.classList.remove('input-has-error');

        if (!input.checkValidity()) {
          isValid = false;
          input.classList.add('input-has-error');
          if (!firstInvalidInput) firstInvalidInput = input;

          const errorMsg = document.createElement('span');
          errorMsg.className = 'step-field-error';
          errorMsg.textContent = input.validationMessage || 'يرجى ملء هذا الحقل بصورة صحيحة.';
          const parentField = input.closest('.form-field') || input.parentElement;
          if (parentField) {
            parentField.appendChild(errorMsg);
          }
        }
      });

      // التحقق المخصص لتطابق كلمات المرور في خطوات التسجيل
      const pass = step.querySelector('input[name="password"]');
      const passConfirm = step.querySelector('input[name="password_confirmation"]');
      if (pass && passConfirm && pass.value && passConfirm.value && pass.value !== passConfirm.value) {
        isValid = false;
        passConfirm.classList.add('input-has-error');
        if (!firstInvalidInput) firstInvalidInput = passConfirm;

        const errorMsg = document.createElement('span');
        errorMsg.className = 'step-field-error';
        errorMsg.textContent = 'تأكيد كلمة المرور غير مطابق.';
        const parentField = passConfirm.closest('.form-field') || passConfirm.parentElement;
        if (parentField) {
          parentField.appendChild(errorMsg);
        }
      }

      if (!isValid && firstInvalidInput) {
        firstInvalidInput.focus();
      }

      return isValid;
    }

    // تهيئة العرض المبدئي
    steps.forEach(function (step, idx) {
      if (idx === 0) {
        step.classList.add('is-active');
        step.style.display = 'block';
      } else {
        step.classList.remove('is-active');
        step.style.display = 'none';
      }
    });
  }

  /* -------------------------------------------------------------
   * 3. تحسينات إمكانية الوصول ولمس كبار السن
   * ------------------------------------------------------------- */
  function initTouchEnhancements() {
    // زيادة مساحة النقر لجميع حقول الاختيار والراديو
    const checkLabels = document.querySelectorAll('label input[type="radio"], label input[type="checkbox"]');
    checkLabels.forEach(function (input) {
      const parentLabel = input.closest('label');
      if (parentLabel) {
        parentLabel.classList.add('touch-label-enhanced');
      }
    });

    // مساعدة كبار السن في التنبيه على الحقول الإلزامية
    const requiredInputs = document.querySelectorAll('input[required], select[required], textarea[required]');
    requiredInputs.forEach(function (input) {
      const label = document.querySelector(`label[for="${input.id}"]`) || input.closest('label');
      if (label && !label.querySelector('.required-mark')) {
        const mark = document.createElement('span');
        mark.className = 'required-mark';
        mark.textContent = ' *';
        mark.setAttribute('aria-hidden', 'true');
        label.appendChild(mark);
      }
    });
  }

  /* -------------------------------------------------------------
   * 4. التبويبات التفاعلية (Tabs Switcher)
   * ------------------------------------------------------------- */
  function initTabs() {
    const tabContainers = document.querySelectorAll('.tabs-container');
    tabContainers.forEach(function (container) {
      const buttons = container.querySelectorAll('.tab-button');
      const contents = container.querySelectorAll('.tab-content');

      buttons.forEach(function (btn) {
        btn.addEventListener('click', function () {
          const targetId = btn.getAttribute('data-tab-target');
          if (!targetId) return;

          buttons.forEach(function (b) {
            b.classList.remove('is-active');
            b.setAttribute('aria-selected', 'false');
          });
          contents.forEach(function (c) {
            c.classList.remove('is-active');
            c.style.display = 'none';
          });

          btn.classList.add('is-active');
          btn.setAttribute('aria-selected', 'true');
          const targetContent = container.querySelector('#' + targetId);
          if (targetContent) {
            targetContent.classList.add('is-active');
            targetContent.style.display = 'block';
          }
        });
      });

      // تهيئة العرض المبدئي بحسب الصنف النشط
      contents.forEach(function (content) {
        if (content.classList.contains('is-active')) {
          content.style.display = 'block';
        } else {
          content.style.display = 'none';
        }
      });
    });
  }

  /* -------------------------------------------------------------
   * 5. محادثة الواتساب التفاعلية (WhatsApp Chat Interactivity)
   * ------------------------------------------------------------- */
  function initWhatsAppChat() {
    const scrollArea = document.getElementById('wa-messages-scroll');
    if (scrollArea) {
      scrollArea.scrollTop = scrollArea.scrollHeight;
    }

    const textarea = document.getElementById('message_body');
    const composeForm = textarea ? textarea.closest('form') : null;
    if (textarea && composeForm) {
      textarea.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          if (textarea.value.trim() !== '') {
            composeForm.submit();
          }
        }
      });
    }
  }

  function escapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }
})();
