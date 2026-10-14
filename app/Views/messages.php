<section class="section page-section">
  <span class="eyebrow">تواصل تعليمي</span>
  <h1 class="page-title">رسائل فردية</h1>
  <p class="page-intro">المراسلة متاحة بين الطالب والأستاذ المسند إلى صفه. تطبق سياسة الاحتفاظ عند اعتمادها.</p>
  <div class="messages-layout">
    <aside class="detail-card message-sidebar">
      <h2>محادثاتك</h2>
      <?php if (!$data['conversations']): ?><p class="muted">لا توجد محادثات بعد.</p><?php else: ?>
        <ul class="conversation-list">
          <?php foreach ($data['conversations'] as $conversation): ?>
            <li><a class="<?= $data['peer'] && (int) $data['peer']['id'] === (int) $conversation['peer_id'] ? 'active' : '' ?>" href="<?= e(url('messages', ['with' => (int) $conversation['peer_id']])) ?>">
              <strong><?= e($conversation['full_name']) ?></strong><span><?= e(message_preview($conversation['message_body'])) ?></span>
              <?php if ((int) $conversation['unread_count'] > 0): ?><small><?= (int) $conversation['unread_count'] ?> غير مقروءة</small><?php endif; ?>
            </a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <h3>ابدأ محادثة</h3>
      <?php if (!$data['contacts']): ?><p class="muted">لا يوجد طالب أو أستاذ مسند متاح للمراسلة حاليًا.</p>
      <?php else: ?>
        <form method="get" action="/" class="message-contact-form">
          <input type="hidden" name="page" value="messages">
          <label for="message_contact">جهة الاتصال</label>
          <select id="message_contact" name="with" required><option value="">اختر</option>
            <?php foreach ($data['contacts'] as $contact): ?><option value="<?= (int) $contact['id'] ?>" <?= $data['peer'] && (int) $data['peer']['id'] === (int) $contact['id'] ? 'selected' : '' ?>><?= e($contact['full_name'] . ' · ' . $contact['role_name']) ?></option><?php endforeach; ?>
          </select>
          <button class="button button-small" type="submit">فتح المحادثة</button>
        </form>
      <?php endif; ?>
    </aside>
    <section class="detail-card message-thread" aria-label="المحادثة">
      <?php if (!$data['peer']): ?><div class="empty-state"><p>اختر محادثة من القائمة أو ابدأ محادثة جديدة.</p></div>
      <?php else: ?>
        <h2><?= e($data['peer']['full_name']) ?></h2>
        <div class="message-history" aria-live="polite">
          <?php if (!$data['thread']): ?><p class="muted">ابدأ المحادثة برسالتك الأولى.</p><?php endif; ?>
          <?php foreach ($data['thread'] as $message): ?><article class="chat-message <?= (int) $message['sender_id'] === (int) current_user()['id'] ? 'sent' : 'received' ?>"><p><?= nl2br(e($message['message_body'])) ?></p><time datetime="<?= e(date(DATE_ATOM, strtotime($message['sent_at']))) ?>"><?= e(date('Y-m-d H:i', strtotime($message['sent_at']))) ?></time></article><?php endforeach; ?>
        </div>
        <form method="post" action="<?= e(url('messages')) ?>" class="message-compose">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="message_send"><input type="hidden" name="receiver_id" value="<?= (int) $data['peer']['id'] ?>">
          <label class="visually-hidden" for="message_body">نص الرسالة</label><textarea id="message_body" name="message_body" rows="3" maxlength="5000" required placeholder="اكتب رسالتك…"></textarea>
          <button class="button" type="submit">إرسال</button>
        </form>
      <?php endif; ?>
    </section>
  </div>
</section>
