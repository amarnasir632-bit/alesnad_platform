<section class="section page-section account-admin" aria-labelledby="accounts-title">
  <div class="section-head"><div><span class="eyebrow">إدارة المنصة</span><h1 id="accounts-title">حسابات المستخدمين</h1></div><a class="button button-small" href="<?= e(url('staff')) ?>">إدارة ودعوة الفريق</a></div>
  <p class="page-intro">عدّل بيانات الحسابات وحالة تفعيلها وأدوارها. يمكن للمدير أيضًا تصحيح صف الطالب المختار عند التسجيل.</p>
  <div id="accounts-feedback" class="messages" aria-live="polite"></div>
  <template id="account-class-options"><option value="">غير محدد</option><?php foreach ($data['classes'] as $class): ?><option value="<?= (int) $class['id'] ?>"><?= e($class['stage_name'] . ' · ' . $class['name']) ?></option><?php endforeach; ?></template>
  <div class="table-scroll"><table class="account-table"><thead><tr><th scope="col">الحساب</th><th scope="col">الصف</th><th scope="col">الحالة</th><th scope="col">الأدوار</th><th scope="col">إجراء</th></tr></thead><tbody id="account-list"><tr><td colspan="5">جارٍ تحميل الحسابات…</td></tr></tbody></table></div>
</section>
