<section class="section page-section">
  <span class="eyebrow">إدارة المنصة المدرسية</span>
  <h1 class="page-title">الهيكل الدراسي والمنهج</h1>
  <p class="page-intro">إدارة المراحل التعليمية والصفوف وربط المواد الدراسية وفق منهج وزارة التربية والتعليم السودانية.</p>

  <!-- تبويبات إدارة الهيكل الدراسي -->
  <div class="tabs-container">
    <nav class="tabs-nav" role="tablist" aria-label="خيارات إدارة الهيكل الدراسي">
      <button class="tab-button is-active" type="button" role="tab" aria-selected="true" data-tab-target="tab-subject">
        <span class="tab-icon">📚</span>
        <span>إضافة مادة لصف (الأكثر استخداماً)</span>
      </button>
      <button class="tab-button" type="button" role="tab" aria-selected="false" data-tab-target="tab-class">
        <span class="tab-icon">🏫</span>
        <span>إضافة صف دراسي</span>
      </button>
      <button class="tab-button" type="button" role="tab" aria-selected="false" data-tab-target="tab-stage">
        <span class="tab-icon">🏛️</span>
        <span>إضافة مرحلة تعليمية</span>
      </button>
    </nav>

    <!-- تبويب 1: إضافة مادة لصف -->
    <div class="tab-content is-active" id="tab-subject" role="tabpanel">
      <form class="detail-card admin-form" method="post" action="<?= e(url('academics')) ?>">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="academics_add_subject">
        <h2>إضافة مادة دراسية جديدة لصف</h2>
        <p class="form-desc">اختر الصف واكتب اسم المادة لربطها تلقائيًا بجدول المواد المقررة.</p>

        <div class="form-field">
          <label for="subject_class">الصف والمرحلة الدراسية</label>
          <select id="subject_class" name="class_id" required>
            <option value="">-- اضغط هنا لاختيار الصف الدراسي --</option>
            <?php foreach ($data['stages'] as $stage): foreach ($stage['classes'] as $class): if (!(bool) $class['is_active']) continue; ?>
              <option value="<?= (int) $class['id'] ?>"><?= e($stage['name'] . ' · ' . $class['name']) ?></option>
            <?php endforeach; endforeach; ?>
          </select>
          <small class="field-hint">الصف الذي ستُتاح فيه هذه المادة لطلابه وأساتذته.</small>
        </div>

        <div class="form-field">
          <label for="subject_name">اسم المادة الدراسية</label>
          <input id="subject_name" name="name" maxlength="120" placeholder="مثال: الرياضيات، الفيزياء، اللغة العربية" required>
          <small class="field-hint">اكتب اسم المادة بالعربية، وسيتكفل النظام تلقائيًا بتوليد الرمز الداخلي وربطها بالصف.</small>
        </div>

        <button class="button button-primary button-large" type="submit">
          <span>✓ حفظ وإسناد المادة للصف</span>
        </button>
      </form>
    </div>

    <!-- تبويب 2: إضافة صف دراسي -->
    <div class="tab-content" id="tab-class" role="tabpanel" style="display: none;">
      <form class="detail-card admin-form" method="post" action="<?= e(url('academics')) ?>">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="academics_add_class">
        <h2>إضافة صف دراسي جديد</h2>
        <p class="form-desc">أضف صفاً جديداً إلى إحدى المراحل التعليمية النشطة (الرمز الداخلي يُولّد تلقائيًا).</p>

        <div class="form-field">
          <label for="class_stage">المرحلة التعليمية التابع لها</label>
          <select id="class_stage" name="stage_id" required>
            <option value="">-- اضغط لاختيار المرحلة التعليمية --</option>
            <?php foreach ($data['stages'] as $stage): if (!(bool) $stage['is_active']) continue; ?>
              <option value="<?= (int) $stage['id'] ?>"><?= e($stage['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-field">
          <label for="class_name">اسم الصف الدراسي</label>
          <input id="class_name" name="name" maxlength="100" placeholder="مثال: الصف السادس، الثالث الثانوي" required>
        </div>

        <!-- خانة ترتيب الظهور المطورة والمشروحة بوضوح -->
        <div class="form-field sort-order-field-card">
          <div class="sort-order-header">
            <label for="class_order">ترتيب الظهور في القوائم المدرسية</label>
            <span class="sort-tag-explain">الأرقام الأصغر تظهر أولاً</span>
          </div>
          <div class="sort-order-input-wrap">
            <input id="class_order" name="sort_order" type="number" min="0" max="32767" value="1" required class="sort-number-input">
            <div class="sort-quick-badges" aria-label="أزرار الترتيب السريع">
              <button type="button" class="sort-pill" onclick="document.getElementById('class_order').value=1">الأول (1)</button>
              <button type="button" class="sort-pill" onclick="document.getElementById('class_order').value=2">الثاني (2)</button>
              <button type="button" class="sort-pill" onclick="document.getElementById('class_order').value=3">الثالث (3)</button>
              <button type="button" class="sort-pill" onclick="document.getElementById('class_order').value=10">نهاية القائمة (10)</button>
            </div>
          </div>
          <small class="field-hint">💡 <strong>كيف تعمل؟</strong> الصف ذو الرقم (1) سيظهر في أعلى قائمة الصفوف للطلاب وأولياء الأمور، يليه (2) ثم (3).</small>
        </div>

        <button class="button button-primary button-large" type="submit">
          <span>✓ حفظ الصف الدراسي</span>
        </button>
      </form>
    </div>

    <!-- تبويب 3: إضافة مرحلة تعليمية -->
    <div class="tab-content" id="tab-stage" role="tabpanel" style="display: none;">
      <form class="detail-card admin-form" method="post" action="<?= e(url('academics')) ?>">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="academics_add_stage">
        <h2>إضافة مرحلة تعليمية جديدة</h2>
        <p class="form-desc">إضافة مرحلة رئيسية مثل الابتدائية أو المتوسطة أو الثانوية (الرمز يُولّد تلقائيًا).</p>

        <div class="form-field">
          <label for="stage_name">اسم المرحلة التعليمية</label>
          <input id="stage_name" name="name" maxlength="100" placeholder="مثال: المرحلة المتوسطة، المرحلة الابتدائية" required>
        </div>

        <!-- خانة ترتيب الظهور المطورة والمشروحة بوضوح للمرحلة -->
        <div class="form-field sort-order-field-card">
          <div class="sort-order-header">
            <label for="stage_order">ترتيب ظهور المرحلة في النظام</label>
            <span class="sort-tag-explain">الأرقام الأصغر تظهر أولاً</span>
          </div>
          <div class="sort-order-input-wrap">
            <input id="stage_order" name="sort_order" type="number" min="0" max="32767" value="1" required class="sort-number-input">
            <div class="sort-quick-badges" aria-label="أزرار الترتيب السريع">
              <button type="button" class="sort-pill" onclick="document.getElementById('stage_order').value=1">الأولى (1)</button>
              <button type="button" class="sort-pill" onclick="document.getElementById('stage_order').value=2">الثانية (2)</button>
              <button type="button" class="sort-pill" onclick="document.getElementById('stage_order').value=3">الثالثة (3)</button>
            </div>
          </div>
          <small class="field-hint">💡 <strong>كيف تعمل؟</strong> المرحلة ذات الترتيب (1) تظهر كأول خيار في القوائم المدرسية وشجرة المراحل التعليمية.</small>
        </div>

        <button class="button button-primary button-large" type="submit">
          <span>✓ حفظ المرحلة التعليمية</span>
        </button>
      </form>
    </div>
  </div>
</section>

<!-- استعراض شجرة المنهج المسجلة -->
<section class="section page-section structure-list">
  <div class="section-head">
    <div>
      <span class="eyebrow">البيانات المسجلة</span>
      <h2>شجرة المنهج والمواد الحالية</h2>
    </div>
  </div>

  <?php if (!$data['stages']): ?>
    <div class="empty-state">
      <h3>لم تُضف مراحل بعد</h3>
      <p>استخدم النماذج أعلاه لإدخال المراحل والصفوف والمواد.</p>
    </div>
  <?php endif; ?>

  <div class="stages-accordion">
    <?php foreach ($data['stages'] as $stage): ?>
      <article class="detail-card structure-stage-card">
        <div class="stage-header">
          <div class="stage-title-wrap">
            <span class="stage-badge" aria-hidden="true">🏛️</span>
            <h3><?= e($stage['name']) ?></h3>
            <span class="status-pill"><?= e($stage['code']) ?></span>
            <?php if (!(bool) $stage['is_active']): ?><span class="status-pill status-pill-muted">غير نشطة</span><?php endif; ?>
          </div>
          <span class="stage-classes-count"><?= count($stage['classes']) ?> صف مسجل</span>
        </div>

        <?php if (!$stage['classes']): ?>
          <p class="muted empty-sub-note">لا توجد صفوف مسجلة لهذه المرحلة حتى الآن.</p>
        <?php else: ?>
          <div class="classes-grid">
            <?php foreach ($stage['classes'] as $class): ?>
              <div class="class-card">
                <div class="class-card-head">
                  <strong><?= e($class['name']) ?></strong>
                  <span class="muted small-code"><?= e($class['code']) ?></span>
                  <?php if (!(bool) $class['is_active']): ?><span class="muted"> (غير نشط)</span><?php endif; ?>
                </div>

                <?php if (!$class['subjects']): ?>
                  <p class="muted no-subjects-note">لا توجد مواد مضافة لهذا الصف بعد.</p>
                <?php else: ?>
                  <ul class="subjects-tags-list">
                    <?php foreach ($class['subjects'] as $subject): ?>
                      <li class="subject-tag">
                        <span>📚 <?= e($subject['subject_name']) ?></span>
                        <?php if ($subject['subject_code']): ?><small><?= e($subject['subject_code']) ?></small><?php endif; ?>
                        <?php if (!(bool) $subject['is_active'] || !(bool) $subject['subject_active']): ?>
                          <span class="muted">(غير نشطة)</span>
                        <?php endif; ?>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
</section>
