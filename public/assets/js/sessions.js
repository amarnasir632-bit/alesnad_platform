(() => {
  const feedback = document.querySelector('#session-feedback');
  const formFeedback = document.querySelector('#session-form-feedback');
  const form = document.querySelector('#session-create-form');
  const submitBtn = document.querySelector('#session-submit-btn');
  let csrfToken = '';

  const classSubjectSelect = document.querySelector('#admin-class-subject');
  const teacherInput = document.querySelector('#session_teacher_id');
  const assignmentMap = {};

  if (classSubjectSelect) {
    let teacherHint = teacherInput?.parentElement?.querySelector('.teacher-hint-msg');
    if (!teacherHint && teacherInput?.parentElement) {
      teacherHint = document.createElement('small');
      teacherHint.className = 'teacher-hint-msg';
      teacherHint.style.display = 'block';
      teacherHint.style.marginTop = '6px';
      teacherHint.style.fontSize = '0.85rem';
      teacherInput.parentElement.append(teacherHint);
    }

    api('/api/v1/admin/academics/class-subjects').then(({ class_subjects: assignments }) => {
      classSubjectSelect.replaceChildren(new Option('-- اختر الصف والمادة المعتمدة --', ''));
      assignments.forEach((assignment) => {
        assignmentMap[assignment.class_subject_id] = assignment;
        const teacherText = assignment.default_teacher_name ? ` (أ. ${assignment.default_teacher_name})` : ' (لا يوجد أستاذ مسند)';
        classSubjectSelect.add(new Option(
          `${assignment.stage_name} · ${assignment.class_name} · ${assignment.subject_name}${teacherText}`,
          assignment.class_subject_id,
        ));
      });
    }).catch((error) => {
      classSubjectSelect.replaceChildren(new Option('تعذر تحميل الصفوف والمواد', ''));
      showMessage(formFeedback || feedback, error.message, true);
    });

    classSubjectSelect.addEventListener('change', () => {
      const selected = assignmentMap[classSubjectSelect.value];
      if (selected && teacherInput) {
        if (selected.default_teacher_id) {
          teacherInput.value = selected.default_teacher_id;
          if (teacherHint) {
            teacherHint.textContent = `✓ تم تحديد الأستاذ المسند تلقائياً: ${selected.default_teacher_name} (رقم الملف: ${selected.default_teacher_id})`;
            teacherHint.style.color = '#15803d';
          }
        } else if (teacherHint) {
          teacherHint.textContent = '⚠️ لم يتم إسناد أستاذ لهذه المادة بعد؛ أدخل رقم ملف الأستاذ يدوياً.';
          teacherHint.style.color = '#b45309';
        }
      }
    });
  }

  async function csrf() {
    if (csrfToken) return csrfToken;
    const response = await fetch('/api/v1/csrf', { credentials: 'same-origin' });
    const result = await response.json();
    if (!response.ok) throw new Error(result?.error?.message || 'تعذر تهيئة الطلب الآمن.');
    csrfToken = result?.data?.csrf_token || result?.csrf_token || '';
    if (!csrfToken) throw new Error('تعذر تهيئة الطلب الآمن.');
    return csrfToken;
  }

  async function api(path, method = 'GET', body = undefined) {
    const options = { method, credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (body !== undefined) {
      options.headers['Content-Type'] = 'application/json';
      options.headers['X-CSRF-Token'] = await csrf();
      options.body = JSON.stringify(body);
    }
    const response = await fetch(path, options);
    const result = await response.json();
    if (!response.ok) throw new Error(result?.error?.message || 'تعذر تنفيذ الطلب.');
    return result.data || {};
  }

  function showMessage(target, message, isError = false) {
    if (!target) return;
    target.replaceChildren();
    const p = document.createElement('p');
    p.className = `message ${isError ? 'error' : 'success'}`;
    p.textContent = message;
    target.append(p);
  }

  form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const targetFeedback = formFeedback || feedback;
    targetFeedback.replaceChildren();

    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.innerHTML = '<span>⏳ جارٍ اعتماد وجدولة الحصة…</span>';
    }

    try {
      const values = Object.fromEntries(new FormData(form));
      values.class_subject_id = Number(values.class_subject_id);
      values.duration_minutes = Number(values.duration_minutes);
      if (values.teacher_id) values.teacher_id = Number(values.teacher_id);
      if (values.lesson_id) values.lesson_id = Number(values.lesson_id);
      else delete values.lesson_id;

      if (!values.scheduled_at) {
        throw new Error('يرجى تحديد موعد وتاريخ بداية الحصة.');
      }
      const parsedDate = new Date(values.scheduled_at);
      if (isNaN(parsedDate.getTime())) {
        throw new Error('تاريخ ووقت الحصة غير صالح.');
      }
      values.scheduled_at = parsedDate.toISOString();

      await api('/api/v1/teacher/sessions', 'POST', values);
      showMessage(targetFeedback, '✅ تمت جدولة الحصة بنجاح! جارٍ تحديث الجدول الآن…', false);
      if (feedback && feedback !== targetFeedback) {
        showMessage(feedback, '✅ تمت جدولة الحصة بنجاح!', false);
      }
      setTimeout(() => {
        window.location.reload();
      }, 900);
    } catch (error) {
      showMessage(targetFeedback, '❌ ' + (error.message || 'تعذر جدولة الحصة.'), true);
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<span>🎥 اعتماد وجدولة الحصة الآن</span>';
      }
    }
  });

  let activePeerConnection = null;
  let activeMediaStream = null;
  let activeWhipResourceUrl = null;

  async function startWhipBroadcast(whipUrl, videoPreviewEl, statusEl, stopBtn, startBtn) {
    try {
      statusEl.textContent = 'جارٍ طلب الإذن باستخدام الكاميرا والمايكروفون…';
      activeMediaStream = await navigator.mediaDevices.getUserMedia({
        video: { width: { ideal: 1280 }, height: { ideal: 720 }, frameRate: { ideal: 30 } },
        audio: true
      });
      videoPreviewEl.srcObject = activeMediaStream;
      videoPreviewEl.hidden = false;

      statusEl.textContent = 'جارٍ إنشاء اتصال WebRTC المباشر مع Cloudflare Stream…';
      activePeerConnection = new RTCPeerConnection({
        bundlePolicy: 'max-bundle'
      });

      activeMediaStream.getTracks().forEach((track) => {
        activePeerConnection.addTrack(track, activeMediaStream);
      });

      const offer = await activePeerConnection.createOffer();
      await activePeerConnection.setLocalDescription(offer);

      const whipResponse = await fetch(whipUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/sdp' },
        body: offer.sdp
      });

      if (!whipResponse.ok) {
        throw new Error(`تعذر بدء البث من المتصفح (رمز الاستجابة ${whipResponse.status})`);
      }

      const answerSdp = await whipResponse.text();
      await activePeerConnection.setRemoteDescription({
        type: 'answer',
        sdp: answerSdp
      });

      activeWhipResourceUrl = whipResponse.headers.get('Location');
      statusEl.className = 'message success';
      statusEl.textContent = '🔴 البث المباشر نشط الآن من المتصفح عبر Cloudflare Stream!';
      startBtn.disabled = true;
      stopBtn.disabled = false;
    } catch (err) {
      statusEl.className = 'message error';
      statusEl.textContent = 'فشل بدء بث المتصفح: ' + (err.message || 'تحقق من أذونات الكاميرا.');
      stopWhipBroadcast(videoPreviewEl, statusEl, stopBtn, startBtn);
    }
  }

  async function stopWhipBroadcast(videoPreviewEl, statusEl, stopBtn, startBtn) {
    if (activePeerConnection) {
      activePeerConnection.close();
      activePeerConnection = null;
    }
    if (activeMediaStream) {
      activeMediaStream.getTracks().forEach((track) => track.stop());
      activeMediaStream = null;
    }
    if (videoPreviewEl) {
      videoPreviewEl.srcObject = null;
      videoPreviewEl.hidden = true;
    }
    if (activeWhipResourceUrl) {
      try { await fetch(activeWhipResourceUrl, { method: 'DELETE' }); }
      catch (_) {}
      activeWhipResourceUrl = null;
    }
    if (statusEl) {
      statusEl.className = 'message';
      statusEl.textContent = 'تم إيقاف بث المتصفح.';
    }
    if (startBtn) startBtn.disabled = false;
    if (stopBtn) stopBtn.disabled = true;
  }

  document.querySelector('#session-list')?.addEventListener('click', async (event) => {
    const button = event.target.closest('button[data-action]');
    if (!button) return;
    const card = button.closest('[data-session-id]');
    const id = card.dataset.sessionId;
    const output = card.querySelector('.session-output');
    const action = button.dataset.action;
    button.disabled = true;
    try {
      if (action === 'provision') {
        const { stream } = await api(`/api/v1/teacher/sessions/${id}/stream`, 'POST', {});
        const box = document.createElement('div');
        box.className = 'stream-credentials';

        if (stream.whip_url) {
          const browserBroadcastWrap = document.createElement('div');
          browserBroadcastWrap.className = 'browser-broadcast-panel';
          browserBroadcastWrap.style.marginBottom = '14px';
          browserBroadcastWrap.style.padding = '12px';
          browserBroadcastWrap.style.background = 'var(--surface-variant, #f8fafc)';
          browserBroadcastWrap.style.borderRadius = '8px';

          const title = document.createElement('h4');
          title.style.margin = '0 0 8px 0';
          title.textContent = '🎥 البث المباشر من المتصفح (كاميرا ومايكروفون)';

          const preview = document.createElement('video');
          preview.autoplay = true;
          preview.muted = true;
          preview.playsInline = true;
          preview.hidden = true;
          preview.style.width = '100%';
          preview.style.maxHeight = '280px';
          preview.style.borderRadius = '8px';
          preview.style.background = '#000';
          preview.style.marginBottom = '8px';

          const bStatus = document.createElement('p');
          bStatus.className = 'message';
          bStatus.textContent = 'يمكنك بدء البث فوراً بالكاميرا والمايك من داخل المتصفح بدون أي برنامج خارجي.';

          const bActions = document.createElement('div');
          bActions.style.display = 'flex';
          bActions.style.gap = '8px';
          bActions.style.marginTop = '8px';

          const startBBtn = document.createElement('button');
          startBBtn.className = 'button button-primary button-small';
          startBBtn.type = 'button';
          startBBtn.textContent = '🔴 بدء بث الكاميرا الآن';

          const stopBBtn = document.createElement('button');
          stopBBtn.className = 'button button-danger button-small';
          stopBBtn.type = 'button';
          stopBBtn.disabled = true;
          stopBBtn.textContent = '⏹ إنهاء بث الكاميرا';

          startBBtn.addEventListener('click', () => startWhipBroadcast(stream.whip_url, preview, bStatus, stopBBtn, startBBtn));
          stopBBtn.addEventListener('click', () => stopWhipBroadcast(preview, bStatus, stopBBtn, startBBtn));

          bActions.append(startBBtn, stopBBtn);
          browserBroadcastWrap.append(title, preview, bStatus, bActions);
          box.append(browserBroadcastWrap);
        }

        const voiceBtn = document.createElement('button');
        voiceBtn.className = 'button button-secondary button-small';
        voiceBtn.style.margin = '8px 0';
        voiceBtn.textContent = '🎙️ إدارة طلبات التحدث للطلاب';
        const voiceListContainer = document.createElement('div');
        voiceListContainer.className = 'voice-requests-list';
        voiceBtn.addEventListener('click', async () => {
          voiceBtn.disabled = true;
          try {
            const { voice_requests } = await api(`/api/v1/teacher/sessions/${id}/voice-requests`);
            voiceListContainer.replaceChildren();
            if (!voice_requests || !voice_requests.length) {
              const emptyP = document.createElement('p');
              emptyP.className = 'message';
              emptyP.textContent = 'لا توجد طلبات مشاركة صوتية حالياً.';
              voiceListContainer.append(emptyP);
            } else {
              const ul = document.createElement('ul');
              ul.style.listStyle = 'none';
              ul.style.padding = '0';
              ul.style.margin = '8px 0';
              voice_requests.forEach((req) => {
                const li = document.createElement('li');
                li.style.display = 'flex';
                li.style.justifyContent = 'space-between';
                li.style.alignItems = 'center';
                li.style.padding = '6px 8px';
                li.style.margin = '4px 0';
                li.style.background = '#fff';
                li.style.borderRadius = '6px';
                li.style.border = '1px solid #e2e8f0';

                const sName = document.createElement('span');
                sName.textContent = `طالب: ${req.student_name} (${req.status})`;

                const actDiv = document.createElement('div');
                actDiv.style.display = 'flex';
                actDiv.style.gap = '6px';

                if (req.status === 'PENDING') {
                  const approveBtn = document.createElement('button');
                  approveBtn.className = 'button button-small button-primary';
                  approveBtn.textContent = '✓ سماح بالتحدث';
                  approveBtn.addEventListener('click', async () => {
                    await api(`/api/v1/teacher/sessions/${id}/voice-requests/${req.id}`, 'PATCH', { status: 'APPROVED' });
                    voiceBtn.click();
                  });
                  actDiv.append(approveBtn);
                }
                if (req.status === 'APPROVED') {
                  const revokeBtn = document.createElement('button');
                  revokeBtn.className = 'button button-small button-danger';
                  revokeBtn.textContent = '🔇 كتم / إنهاء';
                  revokeBtn.addEventListener('click', async () => {
                    await api(`/api/v1/teacher/sessions/${id}/voice-requests/${req.id}`, 'PATCH', { status: 'REVOKED' });
                    voiceBtn.click();
                  });
                  actDiv.append(revokeBtn);
                }
                li.append(sName, actDiv);
                ul.append(li);
              });
              voiceListContainer.append(ul);
            }
          } catch (e) { showMessage(voiceListContainer, e.message, true); }
          finally { voiceBtn.disabled = false; }
        });
        box.append(voiceBtn, voiceListContainer);

        const obsDetails = document.createElement('details');
        obsDetails.style.marginTop = '12px';
        obsDetails.style.padding = '8px';
        obsDetails.style.border = '1px dashed #cbd5e1';
        obsDetails.style.borderRadius = '6px';
        const obsSummary = document.createElement('summary');
        obsSummary.style.cursor = 'pointer';
        obsSummary.textContent = '⚙️ خيار بديل: بيانات البث الخارجي (OBS أو vMix)';
        const note = document.createElement('p'); note.textContent = 'بيانات البث سرية. انسخها الآن إلى إعدادات البث في OBS أو البرنامج المعتمد.';
        const endpoint = document.createElement('p'); endpoint.textContent = `عنوان البث: ${stream.ingest_url}`;
        const key = document.createElement('p'); key.textContent = `مفتاح البث: ${stream.stream_key}`;
        obsDetails.append(obsSummary, note, endpoint, key);
        box.append(obsDetails);

        output.replaceChildren(box);
      } else if (action === 'sync') {
        const { sync } = await api(`/api/v1/teacher/sessions/${id}/recordings/sync`, 'POST', {});
        showMessage(output, `تم فحص التسجيلات الجاهزة. أضيف ${sync.added_count} تسجيلًا جديدًا.`);
      } else if (action === 'complete') {
        await api(`/api/v1/teacher/sessions/${id}`, 'PATCH', { status: 'COMPLETED' });
        showMessage(output, 'تم إنهاء الحصة. حدّث الصفحة لمشاهدة التسجيلات بعد جاهزيتها.');
      } else if (action === 'recordings') {
        const { recordings } = await api(`/api/v1/sessions/${id}/recordings`);
        if (!recordings.length) { showMessage(output, 'لا توجد تسجيلات جاهزة لهذه الحصة بعد.'); return; }
        const list = document.createElement('ul');
        recordings.forEach((recording) => {
          const item = document.createElement('li');
          const link = document.createElement('a');
          link.href = `/api/v1/session-recordings/${recording.id}/download`;
          link.textContent = `${recording.title} — تشغيل التسجيل`;
          link.target = '_blank'; link.rel = 'noopener';
          item.append(link); list.append(item);
        });
        output.replaceChildren(list);
      } else if (action === 'join') {
        const { join } = await api(`/api/v1/sessions/${id}/join`, 'POST', {});
        const wrap = document.createElement('div');
        const frame = document.createElement('iframe');
        frame.src = join.meeting_url; frame.title = 'بث الحصة'; frame.allow = 'autoplay; fullscreen; picture-in-picture'; frame.allowFullscreen = true;
        frame.style.width = '100%';
        frame.style.height = '420px';
        frame.style.border = 'none';
        frame.style.borderRadius = '8px';

        const voiceBar = document.createElement('div');
        voiceBar.className = 'student-voice-bar';
        voiceBar.style.marginTop = '10px';
        voiceBar.style.padding = '10px 14px';
        voiceBar.style.background = 'var(--surface-variant, #f1f5f9)';
        voiceBar.style.borderRadius = '8px';
        voiceBar.style.display = 'flex';
        voiceBar.style.alignItems = 'center';
        voiceBar.style.justifyContent = 'space-between';
        voiceBar.style.flexWrap = 'wrap';
        voiceBar.style.gap = '8px';

        const voiceInfo = document.createElement('span');
        voiceInfo.textContent = '🔇 المايك مغلق افتراضياً (مشاركة صوتية فقط عند إذن الأستاذ)';

        const reqVoiceBtn = document.createElement('button');
        reqVoiceBtn.className = 'button button-small button-secondary';
        reqVoiceBtn.type = 'button';
        reqVoiceBtn.textContent = '🎙️ طلب المشاركة الصوتية';

        reqVoiceBtn.addEventListener('click', async () => {
          reqVoiceBtn.disabled = true;
          try {
            await api(`/api/v1/sessions/${id}/voice-requests`, 'POST', {});
            voiceInfo.textContent = '⏳ تم إرسال طلب التحدث؛ بانتظار موافقة الأستاذ…';
            const pollInterval = setInterval(async () => {
              try {
                const { voice_status } = await api(`/api/v1/sessions/${id}/voice-requests/me`);
                if (voice_status && voice_status.status === 'APPROVED') {
                  voiceInfo.textContent = '🟢 وافق الأستاذ على مشاركتك الصوتية!';
                  voiceInfo.style.color = '#15803d';
                  clearInterval(pollInterval);
                } else if (voice_status && (voice_status.status === 'REVOKED' || voice_status.status === 'REJECTED')) {
                  voiceInfo.textContent = '🔇 تم كتم/إنهاء المشاركة الصوتية من قِبل الأستاذ.';
                  voiceInfo.style.color = '';
                  reqVoiceBtn.disabled = false;
                  clearInterval(pollInterval);
                }
              } catch (_) { clearInterval(pollInterval); }
            }, 5000);
          } catch (err) {
            showMessage(voiceBar, err.message, true);
            reqVoiceBtn.disabled = false;
          }
        });

        voiceBar.append(voiceInfo, reqVoiceBtn);
        wrap.append(frame, voiceBar);
        output.replaceChildren(wrap);
      }
    } catch (error) {
      if (error.message && (error.message.includes('Cloudflare Stream') || error.message.includes('مزود البث'))) {
        output.innerHTML = `
          <div class="message error" style="padding: 14px; border-radius: 8px; line-height: 1.7; text-align: right;">
            <strong style="display:block; margin-bottom: 6px;">⚠️ لم يتم تفعيل خدمة البث المباشر (Cloudflare Stream) في الخادم:</strong>
            لتشغيل استوديو البث بالكاميرا والمايكروفون فعلياً، يلزم توفير مفاتيح حساب Cloudflare Stream في ملف <code>.env</code> أو في إعدادات البيئة (Render Environment):<br>
            • <code>STREAM_PROVIDER=cloudflare_stream</code><br>
            • <code>CLOUDFLARE_ACCOUNT_ID</code><br>
            • <code>CLOUDFLARE_API_TOKEN</code><br>
            • <code>CLOUDFLARE_STREAM_CUSTOMER_CODE</code><br>
            <span style="font-size: 0.88rem; color: #64748b; margin-top: 6px; display: inline-block;">
              يمكنك متابعة تجربة باقي وظائف المنصة (المقررات، بنك الأسئلة، الامتحانات، التسجيل) بشكل كامل.
            </span>
          </div>`;
      } else {
        showMessage(output, '⚠️ ' + error.message, true);
      }
    }
    finally { button.disabled = false; }
  });
})();

