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

  function createBroadcastRoom({ title, subject, teacherMode, playbackUrl = '', whipUrl = '', sessionId }) {
    const room = document.createElement('section');
    room.className = 'broadcast-room';
    room.setAttribute('role', 'dialog');
    room.setAttribute('aria-modal', 'true');
    room.setAttribute('aria-labelledby', 'broadcast-room-title');

    const header = document.createElement('header');
    header.className = 'broadcast-room-header';
    const headingWrap = document.createElement('div');
    headingWrap.className = 'broadcast-room-heading';
    const eyebrow = document.createElement('span');
    eyebrow.className = 'broadcast-room-eyebrow';
    eyebrow.textContent = teacherMode ? 'استوديو البث المباشر' : 'الحصة المباشرة';
    const heading = document.createElement('h1');
    heading.id = 'broadcast-room-title';
    heading.textContent = title;
    const subjectLabel = document.createElement('span');
    subjectLabel.className = 'broadcast-room-subject';
    subjectLabel.textContent = subject;
    headingWrap.append(eyebrow, heading, subjectLabel);

    const closeButton = document.createElement('button');
    closeButton.className = 'broadcast-room-close';
    closeButton.type = 'button';
    closeButton.setAttribute('aria-label', teacherMode ? 'إيقاف البث والخروج من الغرفة' : 'الخروج من الحصة');
    closeButton.textContent = '×';
    header.append(headingWrap, closeButton);

    const content = document.createElement('div');
    content.className = 'broadcast-room-content';
    const sidebar = document.createElement('aside');
    sidebar.className = 'broadcast-room-sidebar';
    sidebar.setAttribute('aria-label', 'معلومات المشاركين');
    const sidebarTitle = document.createElement('h2');
    sidebarTitle.textContent = 'المشاركون';
    const attendanceNote = document.createElement('p');
    attendanceNote.className = 'broadcast-room-note';
    attendanceNote.textContent = 'قائمة الحضور المباشر غير متاحة حاليًا. ستظل صلاحيات مشاهدة الحصة مفروضة من الخادم.';
    sidebar.append(sidebarTitle, attendanceNote);

    const stageColumn = document.createElement('main');
    stageColumn.className = 'broadcast-room-main';
    const stage = document.createElement('div');
    stage.className = 'broadcast-room-stage';
    const video = document.createElement('video');
    video.className = 'broadcast-room-video';
    video.autoplay = true;
    video.muted = true;
    video.playsInline = true;
    video.hidden = true;
    const player = document.createElement('iframe');
    player.className = 'broadcast-room-player';
    player.title = 'بث الحصة';
    player.allow = 'autoplay; fullscreen; picture-in-picture';
    player.referrerPolicy = 'no-referrer';
    if (!teacherMode) player.src = playbackUrl;

    const stagePlaceholder = document.createElement('div');
    stagePlaceholder.className = 'broadcast-room-placeholder';
    const stageIcon = document.createElement('span');
    stageIcon.className = 'broadcast-room-placeholder-icon';
    stageIcon.setAttribute('aria-hidden', 'true');
    stageIcon.textContent = teacherMode ? '▣' : '◉';
    const stageMessage = document.createElement('p');
    stageMessage.textContent = teacherMode
      ? 'شارك الشاشة لعرض السبورة أو المادة التعليمية للطلاب.'
      : 'سيظهر بث المعلم هنا عند بدء الإرسال.';
    stagePlaceholder.append(stageIcon, stageMessage);
    stage.append(stagePlaceholder);
    if (teacherMode) stage.append(video);
    else stage.append(player);

    const status = document.createElement('p');
    status.className = 'broadcast-room-status';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    status.textContent = teacherMode
      ? 'تحقق من الكاميرا والميكروفون، ثم ابدأ البث عند الاستعداد.'
      : 'أنت في وضع المشاهدة. الميكروفون مغلق افتراضيًا.';

    const toolbar = document.createElement('div');
    toolbar.className = 'broadcast-room-toolbar';
    const actionButton = (label, className, disabled = false) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = `broadcast-room-control ${className}`;
      button.textContent = label;
      button.disabled = disabled;
      return button;
    };

    let startButton = null;
    let stopButton = null;
    let micButton = null;
    let cameraButton = null;
    let screenButton = null;
    let voicePoll = null;

    if (teacherMode) {
      startButton = actionButton('بدء البث', 'is-primary');
      stopButton = actionButton('إنهاء البث', 'is-danger', true);
      micButton = actionButton('كتم الميكروفون', 'is-muted', true);
      cameraButton = actionButton('إيقاف الكاميرا', 'is-muted', true);
      screenButton = actionButton('مشاركة الشاشة / السبورة', 'is-screen-share', true);
      toolbar.append(startButton, stopButton, micButton, cameraButton, screenButton);

      startButton.addEventListener('click', async () => {
        startButton.disabled = true;
        closeButton.disabled = true;
        status.textContent = 'جارٍ تهيئة الكاميرا والميكروفون؛ أكمل طلب الإذن في المتصفح.';
        await startWhipBroadcast(whipUrl, video, status, stopButton, startButton);
        closeButton.disabled = false;
        if (!activeMediaStream) return;
        micButton.disabled = false;
        cameraButton.disabled = false;
        screenButton.disabled = false;
      });
      stopButton.addEventListener('click', async () => {
        await stopWhipBroadcast(video, status, stopButton, startButton);
        micButton.disabled = true;
        cameraButton.disabled = true;
        screenButton.disabled = true;
        micButton.textContent = 'كتم الميكروفون';
        cameraButton.textContent = 'إيقاف الكاميرا';
        screenButton.textContent = 'مشاركة الشاشة / السبورة';
      });
      micButton.addEventListener('click', () => {
        const audioTracks = activeMediaStream?.getAudioTracks() ?? [];
        if (!audioTracks.length) {
          status.textContent = 'لم يتم العثور على مسار صوتي نشط.';
          return;
        }
        audioTracks.forEach((track) => { track.enabled = !track.enabled; });
        const isMuted = !audioTracks[0].enabled;
        micButton.textContent = isMuted ? 'تشغيل الميكروفون' : 'كتم الميكروفون';
        micButton.setAttribute('aria-pressed', String(isMuted));
      });
      cameraButton.addEventListener('click', () => {
        const videoTracks = activeMediaStream?.getVideoTracks() ?? [];
        if (!videoTracks.length) {
          status.textContent = 'لم يتم العثور على مسار كاميرا نشط.';
          return;
        }
        videoTracks.forEach((track) => { track.enabled = !track.enabled; });
        const isDisabled = !videoTracks[0].enabled;
        cameraButton.textContent = isDisabled ? 'تشغيل الكاميرا' : 'إيقاف الكاميرا';
        cameraButton.setAttribute('aria-pressed', String(isDisabled));
      });
      screenButton.addEventListener('click', async () => {
        let requestedScreen = null;
        try {
          if (activeScreenStream) {
            await restoreCameraTrack(video);
            screenButton.textContent = 'مشاركة الشاشة / السبورة';
            return;
          }
          if (!navigator.mediaDevices?.getDisplayMedia || !activeVideoSender) {
            throw new Error('مشاركة الشاشة غير مدعومة في هذا المتصفح.');
          }
          requestedScreen = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: false });
          if (!room.isConnected) {
            requestedScreen.getTracks().forEach((track) => track.stop());
            return;
          }
          activeScreenStream = requestedScreen;
          const screenTrack = activeScreenStream.getVideoTracks()[0];
          if (!screenTrack) throw new Error('لم يتم اختيار مصدر شاشة للمشاركة.');
          await activeVideoSender.replaceTrack(screenTrack);
          video.srcObject = activeScreenStream;
          screenTrack.addEventListener('ended', async () => {
            await restoreCameraTrack(video);
            screenButton.textContent = 'مشاركة الشاشة / السبورة';
          }, { once: true });
          screenButton.textContent = 'إيقاف مشاركة الشاشة';
          status.textContent = 'مشاركة الشاشة نشطة؛ اختر نافذة السبورة أو المادة التعليمية.';
        } catch (error) {
          requestedScreen?.getTracks().forEach((track) => track.stop());
          activeScreenStream = null;
          video.srcObject = activeMediaStream;
          status.textContent = error.message || 'تعذر بدء مشاركة الشاشة.';
          status.classList.add('is-error');
        }
      });
    } else {
      const voiceButton = actionButton('رفع اليد لطلب المداخلة', 'is-secondary');
      const voiceStatus = document.createElement('span');
      voiceStatus.className = 'broadcast-room-voice-status';
      voiceButton.addEventListener('click', async () => {
        voiceButton.disabled = true;
        try {
          await api(`/api/v1/sessions/${sessionId}/voice-requests`, 'POST', {});
          voiceStatus.textContent = 'تم إرسال الطلب؛ بانتظار موافقة الأستاذ.';
          voicePoll = window.setInterval(async () => {
            try {
              const { voice_status: voiceRequest } = await api(`/api/v1/sessions/${sessionId}/voice-requests/me`);
              if (!voiceRequest) return;
              if (voiceRequest.status === 'APPROVED') {
                voiceStatus.textContent = 'وافق الأستاذ على طلبك؛ لكن البث الحالي لا يتيح إرسال صوت الطلاب.';
                window.clearInterval(voicePoll);
              } else if (['REVOKED', 'REJECTED'].includes(voiceRequest.status)) {
                voiceStatus.textContent = 'لم يعد طلب المشاركة الصوتية نشطًا.';
                voiceButton.disabled = false;
                window.clearInterval(voicePoll);
              }
            } catch (error) {
              window.clearInterval(voicePoll);
              voiceStatus.textContent = error.message || 'تعذر التحقق من حالة الطلب.';
              voiceButton.disabled = false;
            }
          }, 5000);
        } catch (error) {
          voiceStatus.textContent = error.message || 'تعذر إرسال طلب المشاركة.';
          voiceButton.disabled = false;
        }
      });
      toolbar.append(voiceButton, voiceStatus);
    }

    stageColumn.append(stage, status, toolbar);
    content.append(sidebar, stageColumn);
    room.append(header, content);
    document.body.append(room);
    document.body.classList.add('broadcast-room-open');

    const closeRoom = async () => {
      if (voicePoll !== null) window.clearInterval(voicePoll);
      if (teacherMode && (activeMediaStream || activePeerConnection)) {
        const stopped = await stopWhipBroadcast(video, status, stopButton, startButton);
        if (!stopped) {
          closeButton.setAttribute('aria-label', 'الخروج رغم تعذر تأكيد إيقاف البث');
          return;
        }
      }
      room.remove();
      document.body.classList.remove('broadcast-room-open');
      document.removeEventListener('keydown', onEscape);
    };
    const onEscape = (event) => {
      if (event.key === 'Escape') void closeRoom();
    };
    closeButton.addEventListener('click', () => { void closeRoom(); });
    document.addEventListener('keydown', onEscape);
    closeButton.focus();
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
  let activeScreenStream = null;
  let activeVideoSender = null;
  let activeWhipResourceUrl = null;

  async function restoreCameraTrack(videoPreviewEl) {
    const cameraTrack = activeMediaStream?.getVideoTracks()[0];
    if (!cameraTrack || !activeVideoSender) {
      throw new Error('تعذر استعادة بث الكاميرا.');
    }
    await activeVideoSender.replaceTrack(cameraTrack);
    if (activeScreenStream) {
      activeScreenStream.getTracks().forEach((track) => {
        track.onended = null;
        track.stop();
      });
      activeScreenStream = null;
    }
    videoPreviewEl.srcObject = activeMediaStream;
  }

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
        const sender = activePeerConnection.addTrack(track, activeMediaStream);
        if (track.kind === 'video') activeVideoSender = sender;
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
      statusEl.classList.remove('is-error');
      statusEl.textContent = '🔴 البث المباشر نشط الآن من المتصفح عبر Cloudflare Stream!';
      startBtn.disabled = true;
      stopBtn.disabled = false;
    } catch (err) {
      await stopWhipBroadcast(videoPreviewEl, statusEl, stopBtn, startBtn);
      statusEl.classList.add('is-error');
      statusEl.textContent = 'فشل بدء بث المتصفح: ' + (err.message || 'تحقق من أذونات الكاميرا.');
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
    if (activeScreenStream) {
      activeScreenStream.getTracks().forEach((track) => {
        track.onended = null;
        track.stop();
      });
      activeScreenStream = null;
    }
    activeVideoSender = null;
    if (videoPreviewEl) {
      videoPreviewEl.srcObject = null;
      videoPreviewEl.hidden = true;
    }
    let remoteStopError = '';
    if (activeWhipResourceUrl) {
      try {
        const response = await fetch(activeWhipResourceUrl, { method: 'DELETE' });
        if (!response.ok) remoteStopError = `تعذر تأكيد إنهاء البث من المزود (HTTP ${response.status}).`;
      } catch (error) {
        remoteStopError = error.message || 'تعذر الاتصال بمزود البث لإنهائه.';
      }
      activeWhipResourceUrl = null;
    }
    if (statusEl) {
      statusEl.classList.remove('is-error');
      statusEl.textContent = remoteStopError
        ? `تم إيقاف الكاميرا والميكروفون محليًا، لكن ${remoteStopError}`
        : 'تم إيقاف بث المتصفح.';
      if (remoteStopError) statusEl.classList.add('is-error');
    }
    if (startBtn) startBtn.disabled = false;
    if (stopBtn) stopBtn.disabled = true;
    return remoteStopError === '';
  }

  document.querySelector('#session-list')?.addEventListener('click', async (event) => {
    const button = event.target.closest('button[data-action]');
    if (!button) return;
    const card = button.closest('[data-session-id]');
    const id = card.dataset.sessionId;
    const output = card.querySelector('.session-output');
    const action = button.dataset.action;
    if (document.querySelector('.broadcast-room')) {
      showMessage(output, 'أغلق غرفة الحصة الحالية قبل فتح غرفة أخرى.');
      return;
    }
    button.disabled = true;
    try {
      if (action === 'provision') {
        const { stream } = await api(`/api/v1/teacher/sessions/${id}/stream`, 'POST', {});
        const box = document.createElement('div');
        box.className = 'stream-credentials';

        if (stream.whip_url) {
          createBroadcastRoom({
            title: card.querySelector('.session-card-title')?.textContent.trim() || 'حصة مباشرة',
            subject: card.querySelector('.session-subject-eyebrow')?.textContent.trim() || 'مادة دراسية',
            teacherMode: true,
            whipUrl: stream.whip_url,
            sessionId: id,
          });
        } else {
          showMessage(output, 'لا يتوفر بث مباشر من المتصفح لهذه الحصة. يمكنك استخدام بيانات البث الخارجي عند توفرها.', true);
        }

        const voiceBtn = document.createElement('button');
        voiceBtn.className = 'button button-secondary button-small';
        voiceBtn.style.margin = '8px 0';
        voiceBtn.textContent = '📢 مراجعة طلبات المداخلات';
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
                  approveBtn.textContent = '✓ قبول الطلب';
                  approveBtn.addEventListener('click', async () => {
                    await api(`/api/v1/teacher/sessions/${id}/voice-requests/${req.id}`, 'PATCH', { status: 'APPROVED' });
                    voiceBtn.click();
                  });
                  actDiv.append(approveBtn);
                }
                if (req.status === 'APPROVED') {
                  const revokeBtn = document.createElement('button');
                  revokeBtn.className = 'button button-small button-danger';
                  revokeBtn.textContent = 'إلغاء القبول';
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
        createBroadcastRoom({
          title: card.querySelector('.session-card-title')?.textContent.trim() || 'حصة مباشرة',
          subject: card.querySelector('.session-subject-eyebrow')?.textContent.trim() || 'مادة دراسية',
          teacherMode: false,
          playbackUrl: join.meeting_url,
          sessionId: id,
        });
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
