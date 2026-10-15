<section class="section page-section messages-container-page">
  <div class="messages-header-wrap">
    <div>
      <span class="eyebrow"><?= !empty($data['is_admin']) ? 'الرقابة والإشراف الإداري' : 'التواصل التعليمي المباشر' ?></span>
      <h1 class="page-title"><?= !empty($data['is_admin']) ? 'مركز الرقابة على مراسلات المنصة' : 'المراسلة الأكاديمية الفردية' ?></h1>
      <p class="page-intro">
        <?= !empty($data['is_admin']) 
          ? 'استعراض كافة المحادثات وطلبات المراسلة بين الأساتذة والطلاب بصفة مشاهد مع إمكانية تجميد المحادثات فردياً أو جماعياً.' 
          : 'مراسلة مباشرة بين الطالب والأستاذ المسند مع الاحتفاظ الكامل بسجل المحادثات والرسائل.' ?>
      </p>
    </div>

    <?php if (!empty($data['is_admin'])): ?>
      <!-- شريط الإجراءات الشاملة للمدير -->
      <div class="admin-global-freeze-toolbar">
        <form method="post" action="<?= e(url('messages')) ?>" class="inline-form" onsubmit="return confirm('تأكيد: هل ترغب حقاً في تجميد كافة المحادثات عبر كامل المنصة؟');">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="admin_freeze_all">
          <button type="submit" class="button button-small button-danger">
            <span>❄️ تجميد كافة المحادثات في المنصة</span>
          </button>
        </form>

        <form method="post" action="<?= e(url('messages')) ?>" class="inline-form">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="admin_unfreeze_all">
          <button type="submit" class="button button-small button-secondary">
            <span>✓ فك تجميد الكل</span>
          </button>
        </form>
      </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($data['is_admin'])): ?>
    <!-- =========================================================================
         واجهة مدير النظام: الشجرة الهرمية للأساتذة والطلاب والتحكم بالتجميد
         ========================================================================= -->
    <div class="wa-chat-layout admin-oversight-layout">
      <!-- الشريط الجانبي: قائمة الأساتذة والطلاب -->
      <aside class="wa-sidebar admin-sidebar">
        <div class="wa-sidebar-header">
          <h2>قائمة الأساتذة (<?= count($data['admin_overview']) ?>)</h2>
        </div>

        <form method="post" action="<?= e(url('messages')) ?>" id="admin-batch-form">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <div class="admin-batch-bar">
            <span>تحديد متعدد:</span>
            <button type="submit" name="action" value="admin_freeze_selected" class="button button-small button-danger">❄️ تجميد المحدد</button>
            <button type="submit" name="action" value="admin_unfreeze_selected" class="button button-small button-secondary">فك تجميد المحدد</button>
          </div>

          <div class="admin-teachers-tree">
            <?php if (empty($data['admin_overview'])): ?>
              <div class="wa-empty-conversations">
                <p class="muted">لا يوجد أساتذة مسجلون في المنصة حالياً.</p>
              </div>
            <?php else: ?>
              <?php foreach ($data['admin_overview'] as $t): 
                $isSelectedTeacher = (int) ($data['selected_teacher_id'] ?? 0) === (int) $t['id'];
              ?>
                <details class="admin-teacher-group" <?= $isSelectedTeacher || count($t['students']) > 0 ? 'open' : '' ?>>
                  <summary class="admin-teacher-summary <?= $isSelectedTeacher ? 'is-selected-teacher' : '' ?>">
                    <div class="teacher-sum-info">
                      <span class="teacher-sum-avatar" aria-hidden="true">👨‍🏫</span>
                      <div>
                        <strong><?= e($t['full_name']) ?></strong>
                        <small class="muted"><?= e($t['email']) ?></small>
                      </div>
                    </div>

                    <div class="teacher-sum-badges">
                      <span class="count-pill active-pill" title="محادثات نشطة"><?= (int) $t['active_conversations'] ?> نشطة</span>
                      <?php if ((int) $t['frozen_conversations'] > 0): ?>
                        <span class="count-pill frozen-pill" title="محادثات مجمدة">❄️ <?= (int) $t['frozen_conversations'] ?></span>
                      <?php endif; ?>
                    </div>
                  </summary>

                  <!-- خيارات سريعة للمعلم -->
                  <div class="admin-teacher-actions-row">
                    <button type="submit" formaction="<?= e(url('messages')) ?>" name="action" value="admin_freeze_teacher" onclick="document.getElementById('action_teacher_id').value='<?= (int) $t['id'] ?>';" class="btn-micro-freeze">❄️ تجميد كل محادثات المعلم</button>
                    <button type="submit" formaction="<?= e(url('messages')) ?>" name="action" value="admin_unfreeze_teacher" onclick="document.getElementById('action_teacher_id').value='<?= (int) $t['id'] ?>';" class="btn-micro-unfreeze">فك تجميد الكل</button>
                  </div>

                  <!-- قائمة الطلاب المرتبطين بالمعلم -->
                  <ul class="admin-students-sublist">
                    <?php if (empty($t['students'])): ?>
                      <li class="admin-no-students muted">لا توجد محادثات أو طلبات مسجلة بعد</li>
                    <?php else: ?>
                      <?php foreach ($t['students'] as $s): 
                        $isSelected = $isSelectedTeacher && (int) ($data['selected_student_id'] ?? 0) === (int) $s['student_id'];
                        $isFrozen = (bool) $s['is_frozen'];
                        $pairVal = (int) $t['id'] . ':' . (int) $s['student_id'];
                      ?>
                        <li class="admin-student-row <?= $isSelected ? 'is-active-student' : '' ?> <?= $isFrozen ? 'is-frozen-row' : '' ?>">
                          <label class="admin-checkbox-label">
                            <input type="checkbox" name="selected_conversations[]" value="<?= e($pairVal) ?>">
                          </label>

                          <a class="admin-student-link" href="<?= e(url('messages', ['teacher_id' => (int) $t['id'], 'student_id' => (int) $s['student_id']])) ?>">
                            <div class="student-name-meta">
                              <strong><?= e($s['student_name']) ?></strong>
                              <span class="class-sub"><?= e($s['class_name'] ?: 'طالب') ?></span>
                              <?php if (!empty($s['latest_message'])): ?>
                                <span class="admin-last-msg"><?= e(message_preview($s['latest_message'], 28)) ?></span>
                              <?php endif; ?>
                            </div>

                            <div class="student-badges">
                              <?php if ($isFrozen): ?>
                                <span class="status-pill status-pill-danger">❄️ مجمّدة</span>
                              <?php elseif ($s['request_status'] === 'REJECTED'): ?>
                                <span class="status-pill status-pill-muted">معتذر عنها</span>
                              <?php elseif ($s['request_status'] === 'PENDING'): ?>
                                <span class="status-pill status-pill-warning">طلب معلق</span>
                              <?php else: ?>
                                <span class="status-pill status-pill-success">نشطة (<?= (int)$s['messages_total'] ?>)</span>
                              <?php endif; ?>
                            </div>
                          </a>
                        </li>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </ul>
                </details>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <input type="hidden" id="action_teacher_id" name="teacher_id" value="">
        </form>
      </aside>

      <!-- نافذة المشاهدة والرقابة الإدارية (Read-Only Observer Mode) -->
      <section class="wa-chat-window" aria-label="نافذة المشاهدة الإدارية">
        <?php if (empty($data['admin_teacher']) || empty($data['admin_student'])): ?>
          <div class="wa-no-chat-selected">
            <div class="wa-placeholder-art" aria-hidden="true">👁️</div>
            <h2>مركز الرقابة والمشاهدة الإدارية</h2>
            <p>اختر أحد الأساتذة ثم اضغط على اسم الطالب لاستعراض المحادثة المتبادلة بينهما والتحكم في تجميدها.</p>
          </div>
        <?php else: 
          $req = $data['request'];
          $isFrozen = !empty($req['is_frozen']);
        ?>
          <!-- ترويسة الرقابة الإدارية -->
          <header class="wa-chat-header admin-oversight-header">
            <div class="wa-header-peer">
              <span class="admin-oversight-tag">👁️ وضع المشاهدة والرقابة</span>
              <div>
                <h2 class="wa-peer-name">
                  الأستاذ: <strong><?= e($data['admin_teacher']['full_name']) ?></strong>
                  <span class="sep-arrow">↔</span>
                  الطالب: <strong><?= e($data['admin_student']['full_name']) ?></strong>
                </h2>
                <span class="wa-peer-role">سجل المحادثة الرسمية المتبادلة</span>
              </div>
            </div>

            <div class="admin-thread-actions">
              <?php if ($isFrozen): ?>
                <span class="status-pill status-pill-danger">❄️ محادثة مجمّدة</span>
                <form method="post" action="<?= e(url('messages')) ?>" class="inline-form">
                  <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="admin_unfreeze_conversation">
                  <input type="hidden" name="teacher_id" value="<?= (int) $data['admin_teacher']['id'] ?>">
                  <input type="hidden" name="student_id" value="<?= (int) $data['admin_student']['id'] ?>">
                  <button type="submit" class="button button-small button-success">✓ فك تجميد المحادثة</button>
                </form>
              <?php else: ?>
                <span class="status-pill status-pill-success">✓ محادثة نشطة</span>
                <form method="post" action="<?= e(url('messages')) ?>" class="inline-form" onsubmit="return confirm('تأكيد تجميد هذه المحادثة ومنع الطالب والمعلم من إرسال رسائل جديدة؟');">
                  <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="admin_freeze_conversation">
                  <input type="hidden" name="teacher_id" value="<?= (int) $data['admin_teacher']['id'] ?>">
                  <input type="hidden" name="student_id" value="<?= (int) $data['admin_student']['id'] ?>">
                  <button type="submit" class="button button-small button-danger">❄️ تجميد المحادثة</button>
                </form>
              <?php endif; ?>
            </div>
          </header>

          <?php if (!empty($req['rejection_reason'])): ?>
            <div class="wa-status-alert wa-alert-danger">
              <span class="wa-alert-icon">ℹ️</span>
              <div>
                <strong>اعتذر الأستاذ سابقاً عن هذا الطلب</strong>
                <p>السبب المسجل: «<?= e($req['rejection_reason']) ?>»</p>
              </div>
            </div>
          <?php endif; ?>

          <!-- سجل الرسائل المتبادلة -->
          <div class="wa-messages-body" id="wa-messages-scroll" aria-live="polite">
            <div class="wa-encryption-notice">
              <span class="lock-icon">🔒</span>
              <span>أنت تطّلع على هذه المحادثة بصلاحيات مدير النظام (سجل تدقيق وحماية).</span>
            </div>

            <?php if (empty($data['thread'])): ?>
              <div class="wa-first-message-hint">
                <p>لا توجد رسائل متبادلة بعد بين هذا الأستاذ وهذا الطالب.</p>
              </div>
            <?php endif; ?>

            <?php foreach ($data['thread'] as $message): 
              $isTeacherMsg = (int) $message['sender_id'] === (int) $data['admin_teacher']['id'];
            ?>
              <div class="wa-bubble-wrap <?= $isTeacherMsg ? 'is-sent' : 'is-received' ?>">
                <article class="wa-bubble">
                  <div class="wa-sender-tag">
                    <?= $isTeacherMsg ? '👨‍🏫 أ. ' . e($data['admin_teacher']['full_name']) : '🎓 الطالب: ' . e($data['admin_student']['full_name']) ?>
                  </div>
                  <p class="wa-bubble-text"><?= nl2br(e($message['message_body'])) ?></p>
                  <div class="wa-bubble-meta">
                    <time datetime="<?= e(date(DATE_ATOM, strtotime($message['sent_at']))) ?>">
                      <?= e(date('h:i A', strtotime($message['sent_at']))) ?>
                    </time>
                    <span class="wa-ticks">✓✓</span>
                  </div>
                </article>
              </div>
            <?php endforeach; ?>
          </div>

          <!-- شريط الرقابة للمدير (بدون حقل إرسال - قراءة فقط) -->
          <div class="wa-compose-locked admin-observer-note">
            <span>🛡️ وضع المشاهدة مخصص للرقابة الإدارية فقط — لا يمكن كتابة رسائل جديدة كمدير للحفاظ على خصوصية الحوار التعليمي.</span>
          </div>
        <?php endif; ?>
      </section>
    </div>

  <?php else: ?>
    <!-- =========================================================================
         واجهة الطالب والمدرس العادية
         ========================================================================= -->
    <div class="wa-chat-layout">
      <!-- الشريط الجانبي: المحادثات وجهات الاتصال المتاحة -->
      <aside class="wa-sidebar">
        <div class="wa-sidebar-header">
          <h2>المحادثات</h2>
          <span class="wa-badge-count"><?= count($data['conversations']) ?></span>
        </div>

        <!-- اختيار جهة اتصال جديدة -->
        <?php if (!empty($data['contacts'])): ?>
          <form method="get" action="/" class="wa-contact-select-form">
            <input type="hidden" name="page" value="messages">
            <label for="message_contact" class="wa-label-sm">بدء محادثة مع جهة اتصال</label>
            <div class="wa-select-row">
              <select id="message_contact" name="with" required>
                <option value="">-- اضغط لاختيار طالب أو أستاذ --</option>
                <?php foreach ($data['contacts'] as $contact): ?>
                  <option value="<?= (int) $contact['id'] ?>" <?= $data['peer'] && (int) $data['peer']['id'] === (int) $contact['id'] ? 'selected' : '' ?>>
                    <?= e($contact['full_name'] . ' · ' . $contact['role_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <button class="button button-small button-primary wa-open-btn" type="submit">فتح</button>
            </div>
          </form>
        <?php endif; ?>

        <!-- قائمة المحادثات القائمة -->
        <?php if (!$data['conversations']): ?>
          <div class="wa-empty-conversations">
            <span class="wa-empty-icon" aria-hidden="true">💬</span>
            <p class="muted">لا توجد محادثات سابقة بعد. اختر جهة اتصال من القائمة أعلاه لبدء التواصل.</p>
          </div>
        <?php else: ?>
          <ul class="wa-conversations-list">
            <?php foreach ($data['conversations'] as $conversation): 
              $isActive = $data['peer'] && (int) $data['peer']['id'] === (int) $conversation['peer_id'];
            ?>
              <li class="wa-conv-item <?= $isActive ? 'is-active' : '' ?>">
                <a class="wa-conv-link" href="<?= e(url('messages', ['with' => (int) $conversation['peer_id']])) ?>">
                  <div class="wa-avatar-circle" aria-hidden="true">
                    <?= mb_substr($conversation['full_name'], 0, 1) ?>
                  </div>
                  <div class="wa-conv-meta">
                    <div class="wa-conv-name-row">
                      <strong class="wa-conv-name"><?= e($conversation['full_name']) ?></strong>
                      <time class="wa-conv-time"><?= e(date('h:i A', strtotime($conversation['sent_at']))) ?></time>
                    </div>
                    <div class="wa-conv-preview-row">
                      <span class="wa-conv-preview"><?= e(message_preview($conversation['message_body'], 38)) ?></span>
                      <?php if ((int) $conversation['unread_count'] > 0): ?>
                        <span class="wa-unread-badge"><?= (int) $conversation['unread_count'] ?></span>
                      <?php endif; ?>
                    </div>
                  </div>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </aside>

      <!-- مساحة المحادثة الرئيسية بستايل واتساب -->
      <section class="wa-chat-window" aria-label="نافذة المحادثة">
        <?php if (!$data['peer']): ?>
          <div class="wa-no-chat-selected">
            <div class="wa-placeholder-art" aria-hidden="true">📱</div>
            <h2>منصة المحادثة والتواصل التعليمي</h2>
            <p>اختر أحد الأساتذة أو الطلاب من القائمة الجانبية لاستعراض الرسائل أو إرسال طلب استفسار أكاديمي جديد.</p>
          </div>
        <?php else: 
          $req = $data['request'];
          $isFrozen = !empty($req['is_frozen']);
          $isRejectedForStudent = !empty($data['is_student']) && !empty($req) && $req['status'] === 'REJECTED';
        ?>
          <!-- ترويسة المحادثة (Chat Header) -->
          <header class="wa-chat-header">
            <div class="wa-header-peer">
              <div class="wa-avatar-circle wa-header-avatar" aria-hidden="true">
                <?= mb_substr($data['peer']['full_name'], 0, 1) ?>
              </div>
              <div>
                <h2 class="wa-peer-name"><?= e($data['peer']['full_name']) ?></h2>
                <span class="wa-peer-role"><?= e($data['peer']['role_name']) ?></span>
              </div>
            </div>
            <div class="wa-header-status">
              <?php if ($isFrozen): ?>
                <span class="status-pill status-pill-danger">❄️ محادثة مجمّدة</span>
              <?php elseif (!empty($req) && $req['status'] === 'REJECTED'): ?>
                <span class="status-pill status-pill-danger">تم الاعتذار</span>
              <?php elseif (!empty($req) && $req['status'] === 'PENDING'): ?>
                <span class="status-pill status-pill-warning">بانتظار الموافقة</span>
              <?php else: ?>
                <span class="status-pill status-pill-success">محادثة نشطة</span>
              <?php endif; ?>
            </div>
          </header>

          <!-- تنبيه تجميد المحادثة -->
          <?php if ($isFrozen): ?>
            <div class="wa-status-alert wa-alert-danger" role="alert">
              <span class="wa-alert-icon">❄️</span>
              <div>
                <strong>تم تجميد هذه المحادثة مؤقتاً بقرار من إدارة المنصة</strong>
                <p>لا يمكن إرسال أو استقبال رسائل جديدة في هذه المحادثة حالياً حتى إشعار آخر من الإدارة.</p>
              </div>
            </div>
          <?php endif; ?>

          <!-- بطاقة إدارة طلب المراسلة للأستاذ -->
          <?php if (!$isFrozen && !empty($data['is_teacher']) && !empty($req) && in_array($req['status'], ['PENDING', 'IGNORED'], true)): ?>
            <div class="wa-request-decision-box">
              <div class="wa-decision-header">
                <span class="wa-decision-icon" aria-hidden="true">🔔</span>
                <div>
                  <strong>طلب مراسلة جديد من الطالب: <?= e($data['peer']['full_name']) ?></strong>
                  <p>يرغب الطالب في التواصل معك والاستفسار عن الدروس المقررة. حدد خيارك لإدارة هذا الطلب:</p>
                </div>
              </div>
              <div class="wa-decision-actions">
                <form method="post" action="<?= e(url('messages')) ?>" class="inline-form">
                  <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="message_request_decision">
                  <input type="hidden" name="student_id" value="<?= (int) $data['peer']['id'] ?>">
                  <input type="hidden" name="decision" value="accept">
                  <button type="submit" class="button button-small button-success">✓ موافقة وتحدث</button>
                </form>

                <form method="post" action="<?= e(url('messages')) ?>" class="inline-form">
                  <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="message_request_decision">
                  <input type="hidden" name="student_id" value="<?= (int) $data['peer']['id'] ?>">
                  <input type="hidden" name="decision" value="ignore">
                  <button type="submit" class="button button-small button-secondary">👁️ تجاهل الطلب</button>
                </form>

                <details class="wa-reject-details">
                  <summary class="button button-small button-danger">اعتذار ورفض مع ذكر السبب...</summary>
                  <form method="post" action="<?= e(url('messages')) ?>" class="wa-reject-form">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="message_request_decision">
                    <input type="hidden" name="student_id" value="<?= (int) $data['peer']['id'] ?>">
                    <input type="hidden" name="decision" value="reject">
                    <label for="rejection_reason">سبب الاعتذار (سيظهر للطالب بوضوح):</label>
                    <textarea id="rejection_reason" name="rejection_reason" rows="2" maxlength="500" required placeholder="مثال: يرجى طرح الأسئلة خلال موعد الحصة المباشرة القادمة."></textarea>
                    <button type="submit" class="button button-small button-danger">إرسال الاعتذار للطالب</button>
                  </form>
                </details>
              </div>
            </div>
          <?php endif; ?>

          <!-- بانر إشعار حالة الطلب للطالب -->
          <?php if (!$isFrozen && !empty($data['is_student']) && !empty($req)): ?>
            <?php if ($req['status'] === 'REJECTED'): ?>
              <div class="wa-status-alert wa-alert-danger" role="alert">
                <span class="wa-alert-icon">🚫</span>
                <div>
                  <strong>اعتذر الأستاذ عن قبول المراسلة حاليًا</strong>
                  <p>سبب الاعتذار: «<?= e($req['rejection_reason'] ?: 'لم يُحدد سبب معين') ?>»</p>
                </div>
              </div>
            <?php elseif ($req['status'] === 'PENDING'): ?>
              <div class="wa-status-alert wa-alert-info">
                <span class="wa-alert-icon">⏳</span>
                <div>
                  <strong>تم إرسال طلب المراسلة للأستاذ</strong>
                  <p>رسائلك محفوظة في المحادثة وتصل للأستاذ مباشرة لمراجعتها والرد عليك.</p>
                </div>
              </div>
            <?php endif; ?>
          <?php endif; ?>

          <!-- ساحة عرض الرسائل المتبادلة -->
          <div class="wa-messages-body" id="wa-messages-scroll" aria-live="polite">
            <div class="wa-encryption-notice">
              <span class="lock-icon">🔒</span>
              <span>المراسلة مشفرة ومخصصة للأغراض التعليمية بين الطالب والأستاذ المسند.</span>
            </div>

            <?php if (!$data['thread']): ?>
              <div class="wa-first-message-hint">
                <p>ابدأ التواصل برسالتك الأولى، وستظهر هنا ومحفوظة لكلا الطرفين.</p>
              </div>
            <?php endif; ?>

            <?php foreach ($data['thread'] as $message): 
              $isMe = (int) $message['sender_id'] === (int) current_user()['id'];
            ?>
              <div class="wa-bubble-wrap <?= $isMe ? 'is-sent' : 'is-received' ?>">
                <article class="wa-bubble">
                  <p class="wa-bubble-text"><?= nl2br(e($message['message_body'])) ?></p>
                  <div class="wa-bubble-meta">
                    <time datetime="<?= e(date(DATE_ATOM, strtotime($message['sent_at']))) ?>">
                      <?= e(date('h:i A', strtotime($message['sent_at']))) ?>
                    </time>
                    <?php if ($isMe): ?>
                      <span class="wa-ticks" aria-label="تم الإرسال">✓✓</span>
                    <?php endif; ?>
                  </div>
                </article>
              </div>
            <?php endforeach; ?>
          </div>

          <!-- شريط الإدخال والإرسال السفلي -->
          <?php if ($isFrozen): ?>
            <div class="wa-compose-locked wa-frozen-box">
              <span>❄️ المحادثة مجمّدة بقرار الإدارة — لا يمكن إرسال رسائل جديدة حالياً.</span>
            </div>
          <?php elseif ($isRejectedForStudent): ?>
            <div class="wa-compose-locked">
              <span>🔒 المحادثة مغلقة حاليًا بناءً على اعتذار الأستاذ.</span>
            </div>
          <?php else: ?>
            <form method="post" action="<?= e(url('messages')) ?>" class="wa-compose-form">
              <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="message_send">
              <input type="hidden" name="receiver_id" value="<?= (int) $data['peer']['id'] ?>">
              
              <label class="visually-hidden" for="message_body">اكتب رسالتك</label>
              <textarea 
                id="message_body" 
                name="message_body" 
                rows="1" 
                maxlength="5000" 
                required 
                placeholder="اكتب رسالتك هنا… (اضغط Enter للإرسال)"
              ></textarea>

              <button class="wa-send-button" type="submit" title="إرسال الرسالة">
                <span class="send-icon" aria-hidden="true">➤</span>
                <span class="visually-hidden">إرسال</span>
              </button>
            </form>
          <?php endif; ?>
        <?php endif; ?>
      </section>
    </div>
  <?php endif; ?>
</section>
