'use strict';

const $app = document.getElementById('app');
const state = { me: null, refs: null, phone: '' };

// ---------- утилиты ----------

function h(tag, attrs, ...kids) {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v == null || v === false) continue;
    if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
    else if (k === 'class') el.className = v;
    else if (k === 'html') el.innerHTML = v;
    else el.setAttribute(k, v === true ? '' : v);
  }
  for (const kid of kids.flat(Infinity)) if (kid != null && kid !== false) el.append(kid.nodeType ? kid : String(kid));
  return el;
}

async function api(path, body) {
  const opts = { credentials: 'same-origin', headers: { 'X-App': '1' } };
  if (body !== undefined) {
    opts.method = 'POST';
    opts.headers['Content-Type'] = 'application/json';
    opts.body = JSON.stringify(body);
  }
  let res;
  try {
    res = await fetch('/api' + path, opts);
  } catch (e) {
    throw new Error('Нет связи с сервером. Проверьте интернет.');
  }
  const data = await res.json().catch(() => ({}));
  if (res.status === 401) { state.me = null; showLogin(); throw new Error(''); }
  if (!res.ok) throw new Error(data.error || 'Ошибка ' + res.status);
  return data;
}

function toast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.hidden = false;
  clearTimeout(toast.timer);
  toast.timer = setTimeout(() => (t.hidden = true), 3500);
}

const store = {
  get(k) { try { return JSON.parse(localStorage.getItem(k)); } catch (e) { return null; } },
  set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} },
  del(k) { try { localStorage.removeItem(k); } catch (e) {} },
};

function uuid() {
  if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
  const b = crypto.getRandomValues(new Uint8Array(16));
  b[6] = (b[6] & 15) | 64; b[8] = (b[8] & 63) | 128;
  const x = [...b].map(v => v.toString(16).padStart(2, '0')).join('');
  return `${x.slice(0, 8)}-${x.slice(8, 12)}-${x.slice(12, 16)}-${x.slice(16, 20)}-${x.slice(20)}`;
}

const pad = n => String(n).padStart(2, '0');
const isoDate = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const ruDate = iso => iso.split('-').reverse().join('.');

function formatPhone(v) {
  let d = v.replace(/\D/g, '');
  if (d.startsWith('8')) d = '7' + d.slice(1);
  if (d && !d.startsWith('7')) d = '7' + d;
  d = d.slice(0, 11);
  const p = [d.slice(1, 4), d.slice(4, 7), d.slice(7, 9), d.slice(9, 11)];
  let s = '+7';
  if (p[0]) s += ' (' + p[0];
  if (p[0].length === 3) s += ')';
  if (p[1]) s += ' ' + p[1];
  if (p[2]) s += '-' + p[2];
  if (p[3]) s += '-' + p[3];
  return s;
}

// ---------- навигация ----------

function screen(title, back, ...content) {
  const top = document.getElementById('top');
  top.hidden = !state.me;
  document.getElementById('title').textContent = title;
  const b = document.getElementById('back');
  b.hidden = !back;
  b.onclick = back || null;
  document.getElementById('who').textContent = state.me ? state.me.user.name : '';
  $app.replaceChildren(...content.flat(Infinity).filter(x => x != null && x !== false));
  window.scrollTo(0, 0);
}

function go(name) {
  if (location.hash !== '#' + name) history.pushState(null, '', '#' + name);
  route();
}

window.addEventListener('popstate', route);

function route() {
  if (!state.me) return showLogin();
  const name = location.hash.slice(1);
  if (name === 'report') return showReport();
  if (name === 'reports') return showReports();
  if (name === 'booking') return showBooking();
  if (name === 'repair') return showRepair();
  if (name === 'photo') return showPhoto();
  if (name === 'ref') return showRef();
  if (name === 'admin' && state.me.user.role === 'admin') return showAdmin();
  if (name === 'ref-stats' && state.me.user.role === 'admin') return showRefStats();
  showHome();
}

// ---------- приведи друга (данные берём у сервиса рефералки через наш же сервер) ----------

const REF_STATUS = {
  lead:   { t: 'Оставил заявку', cls: '', hint: 'Ждём оплату обучения' },
  paid:   { t: 'Оплатил обучение', cls: 'ok', hint: 'Выплата после регистрации в ГИБДД' },
  gibdd:  { t: 'Зарегистрирован в ГИБДД', cls: 'ok', hint: 'Можно получить вознаграждение' },
  payout: { t: 'Заявка на выплату', cls: 'warn', hint: 'Выплатим в течение 30 дней' },
  done:   { t: 'Выплачено', cls: 'ok', hint: '' },
  lost:   { t: 'Не дошёл', cls: 'bad', hint: '' },
};
const rub = n => Number(n || 0).toLocaleString('ru-RU') + ' ₽';

const refPushSupported = () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

/** Разрешение на уведомления по рефералам: спрашиваем прямо в разделе */
// Ключ сервера мог смениться — старая подписка тогда молча перестаёт работать (403 от Google).
// Поэтому перед отправкой сверяем ключ подписки с текущим и при расхождении подписываемся заново.
function pushKeyBytes(key) {
  return Uint8Array.from(atob(key.replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0));
}

function subFitsKey(sub, key) {
  try {
    const a = new Uint8Array(sub.options.applicationServerKey);
    const b = pushKeyBytes(key);
    return a.length === b.length && a.every((v, i) => v === b[i]);
  } catch (e) { return true; }   // не смогли проверить — не трогаем рабочую подписку
}

async function freshSubscription(reg, key) {
  let sub = await reg.pushManager.getSubscription();
  if (sub && !subFitsKey(sub, key)) {
    try { await sub.unsubscribe(); } catch (e) {}
    sub = null;
  }
  return sub || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: pushKeyBytes(key) });
}

async function refEnablePush(key) {
  const perm = await Notification.requestPermission();
  if (perm !== 'granted') return false;
  const reg = await navigator.serviceWorker.ready;
  const sub = await freshSubscription(reg, key);
  await api('/ref/push-subscribe', { subscription: sub.toJSON() });
  return true;
}

// Разрешение уже дано: тихо проверяем, что подписка жива и сделана текущим ключом
async function refEnsurePush(key) {
  if (!refPushSupported() || !key || Notification.permission !== 'granted') return;
  try {
    const reg = await navigator.serviceWorker.ready;
    const sub = await freshSubscription(reg, key);
    await api('/ref/push-subscribe', { subscription: sub.toJSON() });
  } catch (e) {}
}

function refPushBanner(key) {
  if (!refPushSupported() || !key) return null;
  if (Notification.permission === 'granted') {
    refEnablePush(key).catch(() => {});   // уже разрешено — просто держим подписку свежей
    return null;
  }
  if (Notification.permission === 'denied') return null;
  if (Number(store.get('ref_push_snooze') || 0) > Date.now()) return null;

  return h('div', { class: 'card push-ask' },
    h('b', {}, '🔔 Присылать уведомления по рефералам?'),
    h('p', { class: 'sub' }, 'Сообщим, когда друг оставит заявку, оплатит обучение, зарегистрируется в ГИБДД и когда пройдёт выплата.'),
    h('div', { class: 'row2' },
      h('button', { class: 'btn', onclick: async e => {
        e.target.disabled = true;
        try { (await refEnablePush(key)) ? toast('Уведомления включены') : toast('Уведомления не разрешены'); }
        catch (ex) { toast('Не получилось включить'); }
        showRef();
      } }, 'Разрешить'),
      h('button', { class: 'btn secondary', onclick: () => { store.set('ref_push_snooze', Date.now() + 7 * 864e5); showRef(); } }, 'Не сейчас')));
}

async function showRef() {
  screen('Приведи друга', () => history.back(), h('div', { class: 'loading' }, 'Загрузка…'));
  let d;
  try { d = await api('/ref'); } catch (ex) { if (ex.message) toast(ex.message); return; }

  const t = d.terms;
  const invited = d.invited || [];
  const byDeal = Object.fromEntries((d.payouts || []).map(p => [p.ref_deal_id, p]));
  invited.forEach(r => (r.payout = byDeal[r.deal_id] || null));

  const linkBox = h('div', { class: 'input', style: 'word-break:break-all; font-size:14px' }, d.link);
  const copy = async () => {
    try { await navigator.clipboard.writeText(d.link); toast('Ссылка скопирована'); }
    catch (e) {
      const range = document.createRange();
      range.selectNodeContents(linkBox);
      const sel = getSelection(); sel.removeAllRanges(); sel.addRange(range);
      toast('Нажмите «Копировать» в меню выделения');
    }
  };
  const share = async () => {
    const text = `Учись водить в автошколе — по моей ссылке тебе скидка ${rub(t.discount)}: ${d.link}`;
    if (navigator.share) { try { await navigator.share({ text }); return; } catch (e) {} }
    copy();
  };

  refEnsurePush(d.push_key);

  screen('Приведи друга', () => history.back(),
    refPushBanner(d.push_key),
    h('div', { class: 'card' },
      h('h2', { style: 'margin:0 0 6px' }, rub(t.reward) + ' за каждого друга'),
      h('p', { class: 'sub', style: 'margin:0' },
        `Другу — скидка ${rub(t.discount)}. Деньги платим, когда друг заключит договор, оплатит и зарегистрируется в ГИБДД.`),
      h('a', { class: 'btn ghost', style: 'margin-top:8px', href: t.offer, target: '_blank', rel: 'noopener' }, 'Условия программы и оферта')),

    h('div', { class: 'card' },
      h('div', { class: 'label' }, 'Ваша ссылка'),
      linkBox,
      h('div', { class: 'row2', style: 'margin-top:10px' },
        h('button', { class: 'btn', onclick: share }, 'Поделиться'),
        h('button', { class: 'btn secondary', onclick: copy }, 'Копировать')),
      h('div', { class: 'hint' }, 'Переходов по ссылке: ' + d.clicks)),

    h('div', { class: 'stats' },
      h('div', { class: 'stat' }, h('b', {}, invited.length), h('span', {}, 'друзей пришло')),
      h('div', { class: 'stat' }, h('b', {}, invited.filter(r => ['paid', 'gibdd', 'payout', 'done'].includes(r.status)).length), h('span', {}, 'оплатили')),
      h('div', { class: 'stat' }, h('b', {}, rub(d.earned)), h('span', {}, 'получено'))),

    h('h2', {}, 'Мои друзья'),
    invited.length ? invited.map(r => {
      const s = REF_STATUS[r.status] || REF_STATUS.lead;
      const left = r.payout && !r.payout.paid_at
        ? Math.ceil((new Date(r.payout.created_at).getTime() + t.edit_minutes * 60000 - Date.now()) / 60000) : 0;
      return h('div', { class: 'card report' },
        h('div', { class: 'head' }, h('b', {}, r.name || 'Без имени'), h('span', { class: 'st ' + s.cls }, s.t)),
        r.phone ? h('div', { class: 'meta' }, r.phone) : null,
        s.hint ? h('div', { class: 'meta' }, s.hint) : null,
        r.status === 'gibdd' ? h('button', { class: 'btn', style: 'margin-top:10px', onclick: () => showRefPayout(r, t) }, 'Получить деньги') : null,
        r.payout && !r.payout.paid_at ? [
          h('div', { class: 'meta' }, (r.payout.method === 'debt' ? 'Выбрано: списать в счёт долга' : 'Выбрано: деньгами на карту')
            + (r.payout.updated_at ? ' · заявка изменена' : '')),
          left > 0
            ? [h('button', { class: 'btn secondary', style: 'margin-top:8px', onclick: () => showRefPayout(r, t, r.payout) }, 'Изменить заявку'),
               h('div', { class: 'hint' }, 'Изменить можно ещё ' + left + ' мин')]
            : h('div', { class: 'hint' }, 'Заявку уже не изменить. Если нужно поправить данные, свяжитесь с администратором.'),
        ] : null);
    }) : h('p', { class: 'sub' }, 'Пока никого. Отправьте ссылку друзьям, которые собираются учиться на права.'),

    d.is_admin ? h('button', {
      class: 'btn secondary', style: 'margin-top:16px',
      onclick: async () => {
        try {
          const r = await api('/ref-link?to=admin');
          location.href = r.url;
        } catch (ex) { if (ex.message) toast(ex.message); }
      },
    }, '⚙ Все участники программы') : null,

    d.claim_on ? h('div', { class: 'card' },
      h('div', { class: 'label' }, 'Друг записался без вашей ссылки?'),
      h('p', { class: 'sub', style: 'margin:0 0 10px' },
        'Добавьте его номер — найдём заявку в базе автошколы и закрепим друга за вами.'),
      h('button', { class: 'btn secondary', onclick: () => showRefClaim() }, 'Добавить друга по номеру'),
      (d.claims || []).map(c => h('div', { class: 'report', style: 'margin-top:12px' },
        h('div', { class: 'head' },
          h('b', {}, c.friend_name || c.phone),
          h('span', { class: 'st ' + (c.status === 'linked' ? 'ok' : c.status === 'rejected' ? 'bad' : '') },
            c.status === 'linked' ? 'Привязан' : c.status === 'rejected' ? 'Не вышло' : 'Ищем')),
        h('div', { class: 'meta' }, c.text),
        c.status === 'wait' ? h('button', {
          class: 'btn ghost', style: 'margin-top:6px',
          onclick: async () => {
            try { await api('/ref/claim-cancel', { id: c.id }); showRef(); }
            catch (ex) { if (ex.message) toast(ex.message); }
          },
        }, 'Убрать') : null)),
    ) : null,
  );
}

// Друг записался сам, без ссылки: берём его номер и ищем заявку в базе автошколы
function showRefClaim() {
  const name = h('input', { type: 'text', placeholder: 'Имя и фамилия друга', autocapitalize: 'words' });
  const phone = h('input', {
    type: 'tel', inputmode: 'tel', placeholder: '+7 (___) ___-__-__',
    oninput: e => (e.target.value = formatPhone(e.target.value)),
  });
  const err = h('div', { class: 'alert err', hidden: true });
  const btn = h('button', { class: 'btn', type: 'submit' }, 'Найти друга');

  screen('Друг без ссылки', showRef,
    h('h1', {}, 'Друг записался без ссылки'),
    h('p', { class: 'sub' }, 'Найдём его заявку в базе автошколы и закрепим за вами. Если он ещё не обращался — подождём, пока обратится.'),
    h('div', { class: 'card' }, h('form', {
      onsubmit: async e => {
        e.preventDefault();
        err.hidden = true;
        if (phone.value.replace(/[^0-9]/g, '').length !== 11) {
          err.textContent = 'Введите номер друга полностью';
          err.hidden = false;
          return;
        }
        btn.disabled = true;
        try {
          const r = await api('/ref/claim', { phone: phone.value, name: name.value });
          const last = (r.claims || [])[0];
          toast(last && last.status === 'linked' ? 'Друг привязан к вам' : 'Добавили. Ищем его заявку');
          showRef();
        } catch (ex) {
          if (ex.message) { err.textContent = ex.message; err.hidden = false; }
        } finally { btn.disabled = false; }
      },
    },
      h('div', { class: 'field' }, h('label', {}, 'Номер друга'), phone),
      h('div', { class: 'field' }, h('label', {}, 'Имя друга'), name),
      err, btn)),
    h('div', { class: 'card' },
      h('div', { class: 'label' }, 'Когда не получится'),
      h('p', { class: 'sub', style: 'margin:0' },
        'Если друг уже закреплён за другим участником или записался больше двух месяцев назад — привязку сделает только администратор.')),
  );
  setTimeout(() => phone.focus(), 50);
}

function showRefPayout(ref, t, existing) {
  const prev = (existing && existing.data) || {};
  let method = existing ? existing.method : '';
  const err = h('div', { class: 'alert err', hidden: true });
  const fields = h('div');
  const agree = h('input', { type: 'checkbox', id: 'ref-agree' });
  const f = {};

  const build = () => {
    fields.replaceChildren();
    for (const k in f) delete f[k];
    if (!method) return;
    // при списании долга реквизиты не нужны, но ФИО и дату рождения спрашиваем: по ним проверяем, учился ли человек
    [['fio', 'ФИО как в паспорте', { autocapitalize: 'words' }],
     ['birth', 'Дата рождения', { inputmode: 'numeric', placeholder: '01.02.2000' }],
     ['pass_serie', 'Серия паспорта', { inputmode: 'numeric', maxlength: 5 }],
     ['pass_num', 'Номер паспорта', { inputmode: 'numeric', maxlength: 7 }],
     ['pass_by', 'Кем выдан'], ['pass_date', 'Дата выдачи', { inputmode: 'numeric', placeholder: '01.02.2020' }],
     ['pass_code', 'Код подразделения', { inputmode: 'numeric', maxlength: 7, placeholder: '160-001' }],
     ['reg_addr', 'Адрес прописки'], ['citizenship', 'Гражданство', { placeholder: 'РФ' }],
     ['inn', 'ИНН', { inputmode: 'numeric', maxlength: 12 }], ['snils', 'СНИЛС', { inputmode: 'numeric', maxlength: 14 }],
     ['bank', 'Банк'], ['rs', 'Номер счёта (20 цифр)', { inputmode: 'numeric', maxlength: 24 }],
     ['ks', 'Корреспондентский счёт (20 цифр)', { inputmode: 'numeric', maxlength: 24 }],
     ['bik', 'БИК (9 цифр)', { inputmode: 'numeric', maxlength: 9 }],
    ].slice(0, method === 'debt' ? 2 : undefined).forEach(([id, label, extra]) => {
      const inp = h('input', Object.assign({ type: 'text', id: 'rf-' + id }, extra || {}));
      if (prev[id]) inp.value = prev[id];
      f[id] = inp;
      fields.append(h('div', { class: 'field' }, h('label', { for: 'rf-' + id }, label), inp));
    });
    agree.checked = !!existing;
    fields.append(h('label', { class: 'check' }, agree,
      h('span', {}, 'Согласен на обработку персональных данных и получение вознаграждения указанным способом')));
  };

  const seg = h('div', { class: 'picks' });
  const renderSeg = () => seg.replaceChildren(
    h('button', { type: 'button', class: 'pick' + (method === 'debt' ? ' on' : ''), onclick: () => { method = 'debt'; renderSeg(); build(); } },
      h('span', {}, 'Списать в счёт долга за обучение'), h('small', {}, rub(t.reward))),
    h('button', { type: 'button', class: 'pick' + (method === 'money' ? ' on' : ''), onclick: () => { method = 'money'; renderSeg(); build(); } },
      h('span', {}, 'Деньгами на карту'), h('small', {}, rub(t.reward_card) + ' · НДФЛ ' + rub(t.ndfl))));
  renderSeg();
  if (method) build();

  const submit = h('button', { class: 'btn', type: 'submit' }, existing ? 'Сохранить изменения' : 'Отправить заявку');
  const form = h('form', {
    onsubmit: async e => {
      e.preventDefault();
      err.hidden = true;
      if (!method) { err.textContent = 'Выберите способ получения'; err.hidden = false; return; }
      const body = { deal_id: ref.deal_id, method, agree: agree.checked };
      Object.keys(f).forEach(k => (body[k] = f[k].value.trim()));
      submit.disabled = true;
      try {
        const r = await api(existing ? '/ref/payout-update' : '/ref/payout', body);
        screen('Заявка отправлена', () => go('ref'),
          h('div', { class: 'success' }, h('div', { class: 'big' }, '✅'),
            h('h1', {}, r.updated ? 'Заявка обновлена' : 'Заявка принята'),
            h('p', { class: 'sub' }, r.method === 'debt'
              ? `${rub(r.amount)} спишем с вашей задолженности за обучение.`
              : `${rub(r.amount)} поступят на счёт в течение ${r.days} дней.`)),
          h('button', { class: 'btn', onclick: () => go('ref') }, 'К списку друзей'));
      } catch (ex) {
        if (ex.message) { err.textContent = ex.message; err.hidden = false; }
      } finally { submit.disabled = false; }
    },
  },
    h('div', { class: 'card' }, h('div', { class: 'label' }, 'Способ получения'), seg),
    fields, err, submit);

  screen(existing ? 'Изменить заявку' : 'Вознаграждение', showRef,
    h('p', { class: 'sub' }, 'За друга: ' + (ref.name || 'без имени')),
    existing ? h('div', { class: 'alert warn' }, 'Заявка уже отправлена. Исправьте данные и сохраните — администратор увидит изменения.') : null,
    form);
}

// ---------- установка на главный экран ----------

const ua = navigator.userAgent;
const isIos = /iphone|ipad|ipod/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
const isAndroid = /android/i.test(ua);
// встроенные браузеры мессенджеров не умеют ставить приложения
const inAppBrowser = /Telegram|FBAN|FBAV|Instagram|WhatsApp|VKClient|YaApp|OKApp/i.test(ua) || (isIos && !/Safari/i.test(ua));
const isIosSafari = isIos && /Safari/i.test(ua) && !/CriOS|FxiOS|EdgiOS|YaBrowser/i.test(ua);
const isStandalone = () => window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
let installPrompt = null;
const banners = new Set();

window.addEventListener('beforeinstallprompt', e => {
  e.preventDefault();
  installPrompt = e;
  banners.forEach(renderBanner);
});
window.addEventListener('appinstalled', () => {
  installPrompt = null;
  store.set('install_done', 1);
  banners.forEach(renderBanner);
  toast('Готово! Иконка «Автошкола» появилась на экране телефона');
});

function installBanner() {
  const el = h('div');
  banners.add(el);
  renderBanner(el);
  return el;
}

function renderBanner(el) {
  if (!el.isConnected && el.childNodes.length) { banners.delete(el); return; }
  const show = !isStandalone() && !store.get('install_hidden') && !store.get('install_done') && (isIos || isAndroid || installPrompt);
  if (!show) { el.replaceChildren(); return; }
  el.replaceChildren(h('div', { class: 'install-banner' },
    h('img', { src: '/icon-192.png', alt: '' }),
    h('div', { class: 'ib-text' }, h('b', {}, 'Установите приложение'), h('span', {}, 'Иконка на экране телефона, открывается в одно касание')),
    h('button', { class: 'btn small', onclick: startInstall }, 'Установить'),
    h('button', { class: 'ib-close', 'aria-label': 'Скрыть', onclick: () => {
      store.set('install_hidden', 1);
      banners.forEach(renderBanner);
      toast('Установить можно позже — кнопка есть на главной');
    } }, '×'),
  ));
}

async function startInstall() {
  if (installPrompt) {
    installPrompt.prompt();
    const { outcome } = await installPrompt.userChoice.catch(() => ({}));
    installPrompt = null;
    if (outcome === 'accepted') store.set('install_done', 1);
    banners.forEach(renderBanner);
    return;
  }
  showInstallHelp();
}

const shareIcon = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="M7.5 7.5 12 3l4.5 4.5"/><path d="M6 11H5a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-8a1 1 0 0 0-1-1h-1"/></svg>';
const addIcon = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="4"/><path d="M12 8v8M8 12h8"/></svg>';
const dotsIcon = '<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg>';

function showInstallHelp() {
  const step = (n, icon, text) => h('li', {}, h('span', { class: 'step-n' }, n), icon ? h('span', { class: 'step-ico', html: icon }) : null, h('span', { html: text }));
  let steps;
  if (inAppBrowser) {
    steps = [
      step(1, dotsIcon, 'Нажмите меню <b>⋯</b> в углу этого окна'),
      step(2, null, 'Выберите <b>«Открыть в браузере»</b>' + (isIos ? ' (Safari)' : ' (Chrome)')),
      step(3, null, 'В браузере снова нажмите <b>«Установить»</b>'),
    ];
  } else if (isIos) {
    steps = [
      step(1, shareIcon, isIosSafari ? 'Нажмите <b>«Поделиться»</b> внизу экрана' : 'Нажмите <b>«Поделиться»</b> в адресной строке'),
      step(2, addIcon, 'Прокрутите вниз и выберите <b>«На экран «Домой»»</b>'),
      step(3, null, 'Нажмите <b>«Добавить»</b> в правом верхнем углу'),
      step(4, null, 'Откройте <b>«Автошкола»</b> с экрана телефона и войдите один раз'),
    ];
  } else {
    steps = [
      step(1, dotsIcon, 'Нажмите меню браузера <b>⋮</b> справа вверху'),
      step(2, addIcon, 'Выберите <b>«Установить приложение»</b> или <b>«Добавить на главный экран»</b>'),
      step(3, null, 'Подтвердите — иконка <b>«Автошкола»</b> появится на рабочем столе'),
    ];
  }
  const close = () => overlay.remove();
  const overlay = h('div', { class: 'sheet-bg', onclick: e => { if (e.target === overlay) close(); } },
    h('div', { class: 'sheet' },
      h('div', { class: 'sheet-head' },
        h('img', { src: '/icon-192.png', alt: '' }),
        h('div', {}, h('b', {}, 'Приложение на экран телефона'), h('span', {}, inAppBrowser ? 'Сначала откройте сайт в обычном браузере' : 'Это займёт 10 секунд'))),
      h('ol', { class: 'steps' }, steps),
      isIos && !inAppBrowser ? h('p', { class: 'hint' }, 'На iPhone установить можно только через «Поделиться» — отдельной кнопки Apple не даёт.') : null,
      h('button', { class: 'btn', onclick: close }, 'Понятно'),
    ));
  document.body.append(overlay);
}

// Второй способ: человек сам звонит на проверочный номер, мы ждём вызов
function showConfirmCall(mode) {
  const err = h('div', { class: 'alert err', hidden: true });
  const status = h('div', { class: 'alert warn' }, 'Готовим проверку…');
  const numbers = h('div', { class: 'picks' });
  let poll = null;
  const stop = () => clearInterval(poll);

  const start = async () => {
    try {
      const r = await api('/auth/confirm', { phone: state.phone });
      status.replaceChildren(
        h('b', {}, 'Позвоните на этот номер'),
        h('div', {}, 'Ответа ждать не нужно: как только пойдут гудки, номер подтвердится сам. Звонок бесплатный.'));
      numbers.replaceChildren(...r.numbers.map(n =>
        h('a', { class: 'pick', href: 'tel:' + n.replace(/[^\d+]/g, '') },
          h('span', {}, '📞 ' + formatPhone(n)), h('small', {}, 'нажмите, чтобы позвонить'))));
      poll = setInterval(async () => {
        try {
          const s = await api('/auth/confirm/status', { phone: state.phone });
          if (s.verified) { stop(); showSetPassword(mode === 'new'); }
        } catch (e) {}
      }, 3000);
    } catch (ex) {
      if (ex.message) { err.textContent = ex.message; err.hidden = false; }
      status.replaceChildren('Не получилось начать проверку');
    }
  };

  screen('Подтверждение номера', null,
    h('h1', {}, 'Позвоните нам'),
    h('p', { class: 'sub' }, 'С номера ' + formatPhone(state.phone)),
    h('div', { class: 'card' }, status, numbers, err),
    h('button', { class: 'btn ghost', onclick: () => { stop(); showCallCode(mode); } }, '← Лучше позвоните мне'),
    h('button', { class: 'btn ghost', onclick: () => { stop(); showLogin(); } }, 'Другой номер'),
  );
  start();
}

// ---------- вход ----------

function showLogin(errText) {
  const phone = h('input', {
    type: 'tel', inputmode: 'tel', autocomplete: 'tel', placeholder: '+7 (___) ___-__-__',
    value: state.phone ? formatPhone(state.phone) : '',
    oninput: e => (e.target.value = formatPhone(e.target.value)),
  });
  const err = h('div', { class: 'alert err', hidden: !errText }, errText || '');
  const btn = h('button', { class: 'btn', type: 'submit' }, 'Продолжить');
  const form = h('form', {
    onsubmit: async e => {
      e.preventDefault();
      const digits = phone.value.replace(/\D/g, '');
      if (digits.length !== 11) { err.textContent = 'Введите номер полностью'; err.hidden = false; return; }
      btn.disabled = true;
      try {
        state.phone = digits;
        const r = await api('/auth/check', { phone: digits });
        showAuthStep(r.state, r.call);
      } catch (ex) {
        if (ex.message) { err.textContent = ex.message; err.hidden = false; }
      } finally { btn.disabled = false; }
    },
  },
    h('div', { class: 'field' }, h('label', {}, 'Номер телефона'), phone),
    err, btn,
  );
  screen('Вход', null,
    installBanner(),
    h('div', { class: 'brand' }, h('img', { src: '/icon-192.png', alt: 'Автошкола' })),
    h('h1', { style: 'text-align:center' }, 'Отчёты инструкторов'),
    h('p', { class: 'sub', style: 'text-align:center' }, 'Автошкола · экзамены в ГИБДД'),
    h('div', { class: 'card' }, form),
  );
  setTimeout(() => phone.focus(), 50);
}

function showAuthStep(st, call) {
  const back = () => showLogin();
  const msg = {
    not_found: ['Нет доступа', 'Этого номера нет в списке инструкторов. Обратитесь к администратору автошколы.'],
    blocked: ['Доступ закрыт', 'Ваш доступ заблокирован. Обратитесь к администратору.'],
    pending: ['Ждём подтверждения', 'Заявка отправлена. Администратор подтвердит доступ, после этого войдите с паролем, который вы придумали.'],
  }[st];
  if (msg) {
    return screen('Вход', null,
      h('h1', {}, msg[0]),
      h('div', { class: st === 'pending' ? 'alert warn' : 'alert err' }, msg[1]),
      h('button', { class: 'btn secondary', onclick: back }, 'Другой номер'),
      st === 'pending' ? h('button', { class: 'btn', onclick: () => showLoginPassword() }, 'Уже подтвердили — войти') : null,
    );
  }
  if (st === 'password') return showLoginPassword();
  showCallCode(st === 'new' ? 'new' : 'reset', call);
}

// Подтверждение номера звонком: код — последние 4 цифры номера; если номер скрыт маркировкой, робот диктует код в трубку
function showCallCode(mode, already) {
  const code = h('input', {
    type: 'tel', inputmode: 'numeric', maxlength: 4, autocomplete: 'one-time-code',
    placeholder: '0000', style: 'text-align:center;font-size:30px;letter-spacing:10px',
  });
  const err = h('div', { class: 'alert err', hidden: true });
  const codeHint = () => [
    h('b', {}, '📞 Звоним…'),
    h('div', {}, 'Код — последние 4 цифры номера, с которого идёт звонок. Отвечать не нужно.'),
    h('div', {}, 'Если вместо номера видно название, возьмите трубку — робот продиктует код.'),
    h('div', { class: 'hint' }, 'Звонок приходит за 10–30 секунд.'),
  ];
  const info = h('div', { class: 'alert warn' }, ...codeHint());
  const setMode = () => info.replaceChildren(...codeHint());
  const btn = h('button', { class: 'btn', type: 'submit' }, 'Подтвердить');
  const again = h('button', { class: 'btn ghost', type: 'button', disabled: true }, 'Позвонить ещё раз');
  const fail = t => { err.textContent = t; err.hidden = false; };

  let timer = null;
  const startTimer = sec => {
    clearInterval(timer);
    again.disabled = true;
    const tick = () => {
      again.textContent = sec > 0 ? `Позвонить ещё раз через ${sec} с` : 'Позвонить ещё раз';
      if (sec-- <= 0) { clearInterval(timer); again.disabled = false; }
    };
    tick();
    timer = setInterval(tick, 1000);
  };
  const call = async () => {
    err.hidden = true;
    again.disabled = true;
    try {
      const r = await api('/auth/call', { phone: state.phone });
      setMode(r.mode);
      startTimer(r.wait || 60);
    } catch (ex) {
      if (ex.message) fail(ex.message);
      again.disabled = false;
      again.textContent = 'Позвонить ещё раз';
    }
  };
  again.onclick = call;

  screen('Подтверждение номера', null,
    h('h1', {}, 'Подтвердите номер'),
    h('p', { class: 'sub' }, formatPhone(state.phone)),
    h('div', { class: 'card' }, h('form', {
      onsubmit: async e => {
        e.preventDefault();
        if (!/^\d{4}$/.test(code.value)) return fail('Введите 4 цифры');
        btn.disabled = true;
        try {
          await api('/auth/call/check', { phone: state.phone, code: code.value });
          clearInterval(timer);
          showSetPassword(mode === 'new');
        } catch (ex) {
          if (ex.message) fail(ex.message);
        } finally { btn.disabled = false; }
      },
    },
      info,
      h('div', { class: 'field' }, h('label', {}, 'Код из звонка'), code),
      err, btn, again,
    )),
    h('button', { class: 'btn secondary', onclick: () => { clearInterval(timer); showConfirmCall(mode); } }, '📲 Позвонить самому'),
    h('p', { class: 'hint', style: 'text-align:center' }, 'Быстрее и надёжнее, если звонок к вам не доходит'),
    h('button', { class: 'btn ghost', onclick: () => { clearInterval(timer); showLogin(); } }, 'Другой номер'),
  );
  // звонок мог уже уйти на шаге ввода номера — тогда второй раз не заказываем
  if (already && already.sent) { setMode(already.mode); startTimer(already.wait || 40); } else call();
  setTimeout(() => code.focus(), 50);
}

function passwordInput(autocomplete) {
  const inp = h('input', { type: 'password', autocomplete, minlength: 6, placeholder: 'Не меньше 6 символов' });
  const toggle = h('button', {
    type: 'button', class: 'btn ghost', style: 'padding:8px 0;min-height:0;text-align:left',
    onclick: () => { inp.type = inp.type === 'password' ? 'text' : 'password'; toggle.textContent = inp.type === 'password' ? 'Показать пароль' : 'Скрыть пароль'; },
  }, 'Показать пароль');
  return [inp, toggle];
}

function showLoginPassword() {
  const [pass, toggle] = passwordInput('current-password');
  const err = h('div', { class: 'alert err', hidden: true });
  const btn = h('button', { class: 'btn', type: 'submit' }, 'Войти');
  // скрытое поле с логином — чтобы телефон предложил сохранить пароль
  const user = h('input', { type: 'text', autocomplete: 'username', value: state.phone, hidden: true, readonly: true });
  screen('Вход', null,
    h('h1', {}, 'Введите пароль'),
    h('p', { class: 'sub' }, formatPhone(state.phone)),
    h('div', { class: 'card' }, h('form', {
      onsubmit: async e => {
        e.preventDefault();
        btn.disabled = true;
        try {
          await api('/auth/login', { phone: state.phone, password: pass.value });
          await loadMe();
          go('home');
        } catch (ex) {
          if (ex.message) { err.textContent = ex.message; err.hidden = false; }
        } finally { btn.disabled = false; }
      },
    }, user, h('div', { class: 'field' }, h('label', {}, 'Пароль'), pass, toggle), err, btn)),
    h('button', { class: 'btn ghost', onclick: () => showLogin() }, 'Другой номер'),
    h('button', { class: 'btn ghost', onclick: () => showCallCode('reset') }, 'Забыли пароль?'),
    h('p', { class: 'hint', style: 'text-align:center' }, 'Позвоним на ваш номер, и вы зададите новый пароль.'),
  );
  setTimeout(() => pass.focus(), 50);
}

function showSetPassword(isNew) {
  const [pass, toggle] = passwordInput('new-password');
  const pass2 = h('input', { type: 'password', autocomplete: 'new-password', placeholder: 'Ещё раз' });
  const err = h('div', { class: 'alert err', hidden: true });
  const btn = h('button', { class: 'btn', type: 'submit' }, isNew ? 'Зарегистрироваться' : 'Сохранить пароль');
  const user = h('input', { type: 'text', autocomplete: 'username', value: state.phone, hidden: true, readonly: true });
  screen('Вход', null,
    h('h1', {}, isNew ? 'Регистрация' : 'Новый пароль'),
    h('p', { class: 'sub' }, formatPhone(state.phone) + ' · номер подтверждён — придумайте пароль'),
    h('div', { class: 'card' }, h('form', {
      onsubmit: async e => {
        e.preventDefault();
        const fail = t => { err.textContent = t; err.hidden = false; };
        if (pass.value.length < 6) return fail('Пароль — минимум 6 символов');
        if (pass.value !== pass2.value) return fail('Пароли не совпадают');
        btn.disabled = true;
        try {
          const r = await api('/auth/register', { phone: state.phone, password: pass.value });
          if (r.state === 'pending') return showAuthStep('pending');
          await loadMe();
          go('home');
        } catch (ex) {
          if (ex.message) fail(ex.message);
        } finally { btn.disabled = false; }
      },
    },
      user,
      h('div', { class: 'field' }, h('label', {}, 'Пароль'), pass, toggle),
      h('div', { class: 'field' }, h('label', {}, 'Повторите пароль'), pass2),
      err, btn,
    )),
    h('button', { class: 'btn ghost', onclick: () => showLogin() }, 'Другой номер'),
  );
}

async function loadMe() {
  state.me = await api('/me');
  return state.me;
}

// ---------- главная ----------

function showHome() {
  const me = state.me;
  const isAdmin = me.user.role === 'admin';
  screen('Автошкола', null,
    installBanner(),
    h('h1', {}, 'Здравствуйте, ' + me.user.name.split(' ').slice(-1)[0] + '!'),
    h('div', { class: 'menu' },
      h('button', { class: 'menu-item', onclick: () => go('report') },
        h('div', { class: 'ico' }, '📝'), h('div', {}, h('b', {}, 'Отчёт об экзамене'), h('span', {}, 'Результат экзамена курсанта'))),
      h('button', { class: 'menu-item', onclick: () => go('booking') },
        h('div', { class: 'ico' }, '📅'), h('div', {}, h('b', {}, 'Бронь авто и мото'), h('span', {}, 'Забронировать машину или мотоцикл на дату'))),
      h('button', { class: 'menu-item', onclick: () => go('repair') },
        h('div', { class: 'ico' }, '🔧'), h('div', {}, h('b', {}, 'Заявка на ремонт'), h('span', {}, 'Сообщить о поломке или неисправности'))),
      h('button', { class: 'menu-item', onclick: () => go('photo') },
        h('div', { class: 'ico' }, '📸'), h('div', {}, h('b', {}, 'Фотоотчёт', me.photo_today ? null : h('span', { class: 'badge' }, 'сегодня нет')), h('span', {}, 'Внешний вид инструктора и автомобиля'))),
      h('button', { class: 'menu-item', onclick: () => go('reports') },
        h('div', { class: 'ico' }, '📋'), h('div', {}, h('b', {}, 'Мои отчёты'), h('span', {}, 'Всё, что вы отправили через приложение'))),
      h('button', { class: 'menu-item', onclick: () => go('ref') },
        h('div', { class: 'ico' }, '🎁'), h('div', {}, h('b', {}, 'Приведи друга'), h('span', {}, '3 000 ₽ за каждого, кто заключит договор'))),
      isAdmin ? h('button', { class: 'menu-item', onclick: () => go('admin') },
        h('div', { class: 'ico' }, '⚙️'), h('div', {}, h('b', {}, 'Доступ инструкторов', me.pending ? h('span', { class: 'badge' }, me.pending) : null),
          h('span', {}, 'Подтверждение, сброс пароля'))) : null,
      isAdmin ? h('button', { class: 'menu-item', onclick: () => go('ref-stats') },
        h('div', { class: 'ico' }, '📊'), h('div', {}, h('b', {}, 'Бюджет рефералки'), h('span', {}, 'Выплаты, воронка, кто приводит друзей'))) : null,
    ),
    // баннер скрыт или уже ставили — оставляем тихую кнопку
    isStandalone() || !(store.get('install_hidden') || store.get('install_done')) ? null : h('button', { class: 'btn secondary', style: 'margin-top:20px', onclick: startInstall }, '📲 Установить на экран телефона'),
    h('button', { class: 'btn ghost', style: 'margin-top:24px', onclick: async () => {
      if (!confirm('Выйти из аккаунта?')) return;
      await api('/auth/logout', {}).catch(() => {});
      state.me = null;
      history.replaceState(null, '', '#');
      showLogin();
    } }, 'Выйти'),
  );
}

// ---------- отчёт ----------

const DRAFT = 'report_draft';

function newDraft(keepExam) {
  const last = keepExam || {};
  return {
    id: uuid(),
    exam_date: last.exam_date || isoDate(new Date()),
    instructor: last.instructor || '',
    start_time: last.start_time || '',
    end_time: last.end_time || '',
    inspector: last.inspector || '',
    cadet: '', try: 1, status: '', error: '', comment: '',
  };
}

function showReport() {
  const me = state.me;
  const isAdmin = me.user.role === 'admin';
  const d = store.get(DRAFT) || newDraft();
  const save = () => store.set(DRAFT, d);
  const err = h('div', { class: 'alert err', hidden: true });

  // дата: сегодня и 4 предыдущих дня + календарь
  const dateInput = h('input', { type: 'date', value: d.exam_date, max: isoDate(new Date()) });
  const dateChips = h('div', { class: 'chips', style: 'margin-bottom:10px' });
  const renderDates = () => {
    dateChips.replaceChildren(...[0, 1, 2, 3, 4].map(i => {
      const dt = new Date(); dt.setDate(dt.getDate() - i);
      const iso = isoDate(dt);
      const label = i === 0 ? 'Сегодня' : i === 1 ? 'Вчера' : ruDate(iso).slice(0, 5);
      return h('button', { type: 'button', class: 'chip' + (d.exam_date === iso ? ' on' : ''),
        onclick: () => { d.exam_date = iso; dateInput.value = iso; save(); renderDates(); } }, label);
    }));
  };
  dateInput.addEventListener('change', () => { d.exam_date = dateInput.value; save(); renderDates(); });
  renderDates();

  let instructorField = null;
  if (isAdmin) {
    const sel = h('select', { onchange: e => { d.instructor = e.target.value; save(); } },
      h('option', { value: '' }, me.user.name + ' (я)'),
      me.instructors.filter(n => n !== me.user.name).map(n => h('option', { value: n, selected: d.instructor === n }, n)));
    instructorField = h('div', { class: 'field' }, h('label', {}, 'Инструктор'), sel);
  }

  const start = h('input', { type: 'time', value: d.start_time, oninput: e => { d.start_time = e.target.value; save(); } });
  const end = h('input', { type: 'time', value: d.end_time, oninput: e => { d.end_time = e.target.value; save(); } });

  // инспектор: обычный выпадающий список; если нужного нет — последний пункт открывает поле ввода
  const INSP_NEW = '__new__';
  const inspSel = h('select', {});
  const inspAdd = h('div', { hidden: true, style: 'margin-top:8px' });
  const inspName = h('input', { type: 'text', placeholder: 'Фамилия Имя Отчество', autocapitalize: 'words', autocomplete: 'off' });
  const fillInsp = () => inspSel.replaceChildren(
    h('option', { value: '' }, '— выберите —'),
    ...me.inspectors.map(n => h('option', { value: n, selected: d.inspector === n }, n)),
    h('option', { value: INSP_NEW }, '➕ Нет в списке'));
  inspSel.addEventListener('change', () => {
    if (inspSel.value === INSP_NEW) {
      d.inspector = '';
      inspAdd.hidden = false;
      inspName.focus();
    } else {
      d.inspector = inspSel.value;
      inspAdd.hidden = true;
    }
    save();
  });
  inspAdd.replaceChildren(
    h('div', { class: 'hint', style: 'margin:0 0 6px' }, 'Впишите инспектора — он добавится в общий список.'),
    inspName,
    h('button', { type: 'button', class: 'btn secondary small', style: 'margin-top:8px', onclick: async ev => {
      const v = inspName.value.trim();
      if (v.length < 5) return toast('Впишите фамилию, имя и отчество');
      ev.target.disabled = true;
      try {
        const r = await api('/inspector', { name: v });
        me.inspectors = r.inspectors;
        d.inspector = r.name;
        fillInsp();
        inspSel.value = r.name;
        inspAdd.hidden = true;
        inspName.value = '';
        save();
        toast('Инспектор добавлен: ' + r.name);
      } catch (ex) { if (ex.message) toast(ex.message); }
      ev.target.disabled = false;
    } }, 'Добавить в список'));
  fillInsp();

  const cadet = h('input', { type: 'text', value: d.cadet, placeholder: 'Фамилия Имя Отчество', autocapitalize: 'words',
    oninput: e => { d.cadet = e.target.value; save(); } });

  const tries = h('div', { class: 'chips' });
  const renderTries = () => tries.replaceChildren(...[1, 2, 3, 4, 5].map(n =>
    h('button', { type: 'button', class: 'chip num' + (d.try === n ? ' on' : ''), onclick: () => { d.try = n; save(); renderTries(); } }, n)));
  renderTries();

  const errorSel = h('select', { onchange: e => { d.error = e.target.value; save(); } },
    h('option', { value: '' }, '— выберите —'),
    me.reasons.map(r => h('option', { value: r, selected: d.error === r }, r)));
  const comment = h('textarea', { placeholder: 'Что именно произошло', oninput: e => { d.comment = e.target.value; save(); } });
  comment.value = d.comment;
  const errorBlock = h('div', {}, h('div', { class: 'field' }, h('label', {}, 'Ошибка'), errorSel));
  const commentLabel = h('label', {}, 'Комментарий');
  const commentBlock = h('div', { class: 'field' }, commentLabel, comment);

  const status = h('div', { class: 'seg' });
  const renderStatus = () => {
    status.replaceChildren(
      h('button', { type: 'button', class: 'chip ok' + (d.status === 'passed' ? ' on' : ''), onclick: () => { d.status = 'passed'; save(); renderStatus(); } }, '✅ Сдал'),
      h('button', { type: 'button', class: 'chip bad' + (d.status === 'failed' ? ' on' : ''), onclick: () => { d.status = 'failed'; save(); renderStatus(); } }, '❌ Не сдал'));
    errorBlock.hidden = d.status !== 'failed';
    commentLabel.textContent = d.status === 'failed' ? 'Комментарий об ошибке' : 'Комментарий (необязательно)';
  };
  renderStatus();

  const submit = h('button', { class: 'btn', type: 'submit' }, 'Отправить отчёт');
  const form = h('form', {
    onsubmit: async e => {
      e.preventDefault();
      err.hidden = true;
      const fail = t => { err.textContent = t; err.hidden = false; err.scrollIntoView({ behavior: 'smooth', block: 'center' }); };
      if (!d.exam_date) return fail('Укажите дату сдачи');
      if (!d.start_time || !d.end_time) return fail('Укажите время начала и окончания');
      if (d.end_time <= d.start_time) return fail('Время окончания должно быть позже начала');
      if (!me.inspectors.includes(d.inspector)) return fail('Выберите инспектора из списка или добавьте нового');
      if (d.cadet.trim().length < 3) return fail('Укажите ФИО курсанта');
      if (!d.status) return fail('Укажите, сдал ли курсант');
      if (d.status === 'failed' && !d.error) return fail('Выберите ошибку');
      if (d.status === 'failed' && d.comment.trim().length < 3) return fail('Опишите ошибку в комментарии');
      submit.disabled = true;
      submit.textContent = 'Отправляем…';
      try {
        const r = await api('/report', d);
        store.del(DRAFT);
        showDone(d, r);
      } catch (ex) {
        if (ex.message) fail(ex.message);
      } finally {
        submit.disabled = false;
        submit.textContent = 'Отправить отчёт';
      }
    },
  },
    h('div', { class: 'card' },
      h('div', { class: 'field' }, h('label', {}, 'Дата сдачи'), dateChips, dateInput),
      instructorField,
      h('div', { class: 'row2' },
        h('div', { class: 'field' }, h('label', {}, '▶️ Начало'), start),
        h('div', { class: 'field' }, h('label', {}, '⛔️ Окончание'), end)),
      h('div', { class: 'field', style: 'margin-bottom:0' }, h('label', {}, 'Инспектор'), inspSel, inspAdd),
    ),
    h('div', { class: 'card' },
      h('div', { class: 'field' }, h('label', {}, 'ФИО курсанта'), cadet),
      h('div', { class: 'field' }, h('div', { class: 'label' }, 'Попытка'), tries),
      h('div', { class: 'field' }, h('div', { class: 'label' }, 'Результат'), status),
      errorBlock,
      commentBlock,
    ),
    err, submit,
  );
  screen('Новый отчёт', () => history.back(), form);
}

function showDone(d, r) {
  screen('Отчёт отправлен', null,
    h('div', { class: 'success' },
      h('div', { class: 'big' }, '✅'),
      h('h1', {}, 'Отчёт сохранён'),
      h('p', { class: 'sub' }, d.cadet + ' — ' + (d.status === 'passed' ? 'сдал' : 'не сдал'))),
    r.synced ? null : h('div', { class: 'alert warn' }, 'Отчёт сохранён, но в таблицу ещё не записан — запишется автоматически.'),
    h('button', { class: 'btn', onclick: () => {
      // тот же экзамен: дата, время, инспектор остаются — меняется только курсант
      store.set(DRAFT, newDraft(d));
      history.replaceState(null, '', '#report');
      showReport();
    } }, 'Ещё курсант с этого экзамена'),
    h('button', { class: 'btn secondary', onclick: () => {
      store.set(DRAFT, newDraft());
      history.replaceState(null, '', '#report');
      showReport();
    } }, 'Новый экзамен'),
    h('button', { class: 'btn ghost', onclick: () => { history.replaceState(null, '', '#home'); showHome(); } }, 'На главную'),
  );
}

// ---------- бронь и ремонт ----------

// Список транспорта из «Автопарка» плюс кнопка «своей машины нет»: добавленная
// машина сразу попадает в общий список и в лист «Автопарк».
function vehiclePicker(list, selected, taken, onPick, kind) {
  const box = h('div', { class: 'picks' });
  const add = h('div', { hidden: true, style: 'margin-top:10px' });
  const name = h('input', { type: 'text', placeholder: 'Марка и госномер, например: Лада Гранта А123АА', autocomplete: 'off' });
  const addBtn = h('button', { type: 'button', class: 'btn secondary small', style: 'margin-top:8px' }, 'Добавить в список');
  addBtn.addEventListener('click', async () => {
    const v = name.value.trim();
    if (v.length < 2) return toast('Напишите марку и номер машины');
    addBtn.disabled = true;
    try {
      const r = await api('/vehicle', { name: v, kind: kind ? kind() : 'auto' });
      state.me.fleet = r.fleet;
      name.value = '';
      add.hidden = true;
      onPick(r.name);
      render();
      toast('Добавлено: ' + r.name);
    } catch (ex) { if (ex.message) toast(ex.message); }
    addBtn.disabled = false;
  });
  add.replaceChildren(
    h('div', { class: 'hint', style: 'margin:0 0 6px' }, 'Машина добавится в общий список для всех.'),
    name, addBtn);

  const render = () => {
    const items = list();
    box.replaceChildren(
      ...items.map(v => {
        const busy = taken() && taken()[v.name];
        return h('button', { type: 'button', class: 'pick' + (selected() === v.name ? ' on' : '') + (busy ? ' busy' : ''),
          onclick: () => { onPick(v.name); render(); } },
          h('span', {}, (v.kind === 'moto' ? '🏍 ' : '🚗 ') + v.name),
          busy ? h('small', {}, 'занято: ' + busy) : null);
      }),
      h('button', { type: 'button', class: 'pick', onclick: () => { add.hidden = !add.hidden; if (!add.hidden) name.focus(); } },
        h('span', {}, '➕ Своей машины нет')));
  };
  render();
  return { el: h('div', {}, box, add), render };
}

function formScreen(title, body, submitText, send) {
  const err = h('div', { class: 'alert err', hidden: true });
  const submit = h('button', { class: 'btn', type: 'submit' }, submitText);
  const fail = t => { err.textContent = t; err.hidden = false; err.scrollIntoView({ behavior: 'smooth', block: 'center' }); };
  screen(title, () => history.back(), h('form', {
    onsubmit: async e => {
      e.preventDefault();
      err.hidden = true;
      submit.disabled = true;
      try { await send(fail); } catch (ex) { if (ex.message) fail(ex.message); }
      finally { submit.disabled = false; }
    },
  }, body, err, submit));
}

function showSaved(title, text, r, againText, again, checkId) {
  // фотоотчёт проверяется моделью сразу — ждём вердикт здесь же
  const verdict = checkId ? h('div', { class: 'verdict wait' }, '🔍 Проверяем фото…') : null;
  if (checkId) {
    let tries = 0;
    const poll = setInterval(async () => {
      if (++tries > 20) { clearInterval(poll); verdict.textContent = 'Проверка идёт дольше обычного — результат появится в «Моих отчётах»'; return; }
      try {
        const s = await api('/report/status?id=' + checkId);
        if (!s.status) return;
        clearInterval(poll);
        const v = aiVerdict({ ai_status: s.status, ai_notes: s.notes });
        verdict.className = v.className;
        verdict.replaceChildren(...v.childNodes);
      } catch (e) { clearInterval(poll); }
    }, 2000);
  }
  screen(title, null,
    h('div', { class: 'success' }, h('div', { class: 'big' }, '✅'), h('h1', {}, title), h('p', { class: 'sub' }, text)),
    verdict,
    r.synced ? null : h('div', { class: 'alert warn' }, 'Сохранено, но в таблицу ещё не записано — запишется автоматически.'),
    h('button', { class: 'btn', onclick: again }, againText),
    h('button', { class: 'btn ghost', onclick: () => { history.replaceState(null, '', '#home'); showHome(); } }, 'На главную'),
  );
}

function showBooking() {
  const d = store.get('booking_draft') || { id: uuid(), date: isoDate(new Date()), kpp: '', auto: '' };
  const save = () => store.set('booking_draft', d);
  let taken = {};

  const dateInput = h('input', { type: 'date', value: d.date });
  const dateChips = h('div', { class: 'chips', style: 'margin-bottom:10px' });
  const loadTaken = async () => {
    taken = {};
    picker.render();
    try { taken = (await api('/bookings?date=' + d.date)).taken || {}; } catch (e) {}
    picker.render();
  };
  const setDate = iso => { d.date = iso; dateInput.value = iso; save(); renderDates(); loadTaken(); };
  const renderDates = () => dateChips.replaceChildren(...[0, 1, 2, 3, 4].map(i => {
    const dt = new Date(); dt.setDate(dt.getDate() + i);
    const iso = isoDate(dt);
    return h('button', { type: 'button', class: 'chip' + (d.date === iso ? ' on' : ''), onclick: () => setDate(iso) },
      i === 0 ? 'Сегодня' : i === 1 ? 'Завтра' : ruDate(iso).slice(0, 5));
  }));
  dateInput.addEventListener('change', () => setDate(dateInput.value));
  renderDates();

  // как в боте: КПП — просто вопрос, машины показываем все (мото — отдельно)
  const matches = v => d.kpp === 'Мотоциклы' ? v.kind === 'moto' : v.kind === 'auto';
  const picker = vehiclePicker(() => d.kpp ? (state.me.fleet || []).filter(matches) : [], () => d.auto, () => taken,
    name => { d.auto = name; save(); }, () => d.kpp === 'Мотоциклы' ? 'moto' : 'auto');
  const kppSeg = h('div', { class: 'seg3' });
  const renderKpp = () => kppSeg.replaceChildren(...['АКПП', 'МКПП', 'Мотоциклы'].map(k =>
    h('button', { type: 'button', class: 'chip' + (d.kpp === k ? ' on' : ''), onclick: () => {
      d.kpp = k;
      if (d.auto && !(state.me.fleet || []).some(v => v.name === d.auto && matches(v))) d.auto = '';
      save(); renderKpp(); picker.render();
    } }, k === 'Мотоциклы' ? '🏍 Мото' : k)));
  renderKpp();
  loadTaken();

  formScreen('Бронь авто и мото', h('div', { class: 'card' },
    h('div', { class: 'field' }, h('label', {}, 'Дата брони'), dateChips, dateInput),
    h('div', { class: 'field' }, h('div', { class: 'label' }, 'Тип'), kppSeg),
    h('div', { class: 'field', style: 'margin-bottom:0' }, h('div', { class: 'label' }, 'Транспорт'), picker.el),
  ), 'Забронировать', async fail => {
    if (!d.date) return fail('Укажите дату брони');
    if (!d.kpp) return fail('Выберите тип: АКПП, МКПП или мото');
    if (!d.auto) return fail('Выберите транспорт');
    let r = await api('/booking', d);
    if (r.conflict) {
      if (!confirm(d.auto + ' на ' + ruDate(d.date) + ' уже забронировал(а) ' + r.conflict + '. Всё равно забронировать?')) return;
      r = await api('/booking', Object.assign({}, d, { force: true }));
    }
    store.del('booking_draft');
    showSaved('Бронь сохранена', d.auto + ' — ' + ruDate(d.date), r, 'Ещё одна бронь', () => {
      store.set('booking_draft', { id: uuid(), date: d.date, kpp: d.kpp, auto: '' });
      history.replaceState(null, '', '#booking');
      showBooking();
    });
  });
}

function showRepair() {
  const d = store.get('repair_draft') || { id: uuid(), auto: '', urgency: '', problem: '' };
  const save = () => store.set('repair_draft', d);
  const picker = vehiclePicker(() => state.me.fleet || [], () => d.auto, () => null, name => { d.auto = name; save(); }, () => 'auto');
  const urg = h('div', { class: 'seg' });
  const renderUrg = () => urg.replaceChildren(
    h('button', { type: 'button', class: 'chip ok' + (d.urgency === 'Планово' ? ' on' : ''), onclick: () => { d.urgency = 'Планово'; save(); renderUrg(); } }, '🗓 Планово'),
    h('button', { type: 'button', class: 'chip bad' + (d.urgency === 'Срочно' ? ' on' : ''), onclick: () => { d.urgency = 'Срочно'; save(); renderUrg(); } }, '🚨 Срочно'));
  renderUrg();
  const problem = h('textarea', { placeholder: 'Что случилось: стук, ошибка на панели, не горит фара…', oninput: e => { d.problem = e.target.value; save(); } });
  problem.value = d.problem;

  formScreen('Заявка на ремонт', h('div', { class: 'card' },
    h('div', { class: 'field' }, h('div', { class: 'label' }, 'Транспорт'), picker.el),
    h('div', { class: 'field' }, h('div', { class: 'label' }, 'Срочность'), urg),
    h('div', { class: 'field', style: 'margin-bottom:0' }, h('label', {}, 'Опишите проблему'), problem),
  ), 'Отправить заявку', async fail => {
    if (!d.auto) return fail('Выберите транспорт');
    if (!d.urgency) return fail('Укажите срочность');
    if (d.problem.trim().length < 3) return fail('Опишите проблему');
    const r = await api('/repair', d);
    store.del('repair_draft');
    showSaved('Заявка отправлена', d.auto + ' — ' + d.urgency.toLowerCase(), r, 'Ещё одна заявка', () => {
      history.replaceState(null, '', '#repair');
      showRepair();
    });
  });
}

// ---------- фотоотчёт ----------

// Сжимаем в телефоне: 1280 px по длинной стороне, JPEG. Иначе фото по 5 МБ едут минутами
function shrink(file, max = 1280, quality = 0.8) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    const url = URL.createObjectURL(file);
    img.onload = () => {
      URL.revokeObjectURL(url);
      const k = Math.min(1, max / Math.max(img.width, img.height));
      const c = document.createElement('canvas');
      c.width = Math.round(img.width * k);
      c.height = Math.round(img.height * k);
      c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
      c.toBlob(b => b ? resolve(b) : reject(new Error('Не удалось обработать фото')), 'image/jpeg', quality);
    };
    img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Не удалось прочитать фото')); };
    img.src = url;
  });
}

function photoSlot(label, hint, facing, onReady, already) {
  const input = h('input', { type: 'file', accept: 'image/*', capture: facing, hidden: true });
  const preview = h('div', { class: 'shot-preview' }, h('span', {}, '📷'));
  const state = h('div', { class: 'shot-text' }, h('b', {}, label), h('span', {}, hint));
  const box = h('button', { type: 'button', class: 'shot', onclick: () => input.click() }, preview, state, input);
  // кадр уже сделан — показываем его при перерисовке списка
  if (already) {
    preview.replaceChildren(h('img', { src: URL.createObjectURL(already), alt: '' }));
    state.replaceChildren(h('b', {}, label), h('span', {}, 'Готово · нажмите, чтобы переснять'));
    box.classList.add('done');
  }
  input.addEventListener('change', async () => {
    const file = input.files && input.files[0];
    if (!file) return;
    state.replaceChildren(h('b', {}, label), h('span', {}, 'Обрабатываем…'));
    try {
      const blob = await shrink(file, 1080, 0.72);
      preview.replaceChildren(h('img', { src: URL.createObjectURL(blob), alt: '' }));
      state.replaceChildren(h('b', {}, label), h('span', {}, 'Готово · ' + Math.round(blob.size / 1024) + ' КБ · нажмите, чтобы переснять'));
      box.classList.add('done');
      onReady(blob);
    } catch (ex) {
      state.replaceChildren(h('b', {}, label), h('span', {}, ex.message));
      onReady(null);
    }
  });
  return box;
}

// Обход машины по кругу: угловой кадр показывает сразу две стороны
const PHOTO_SLOTS = [
  { key: 'selfie', label: 'Внешний вид инструктора', hint: 'Селфи по пояс, видно одежду', facing: 'user' },
  { key: 'fl', label: 'Перед и левый борт', hint: 'Встаньте по диагонали у левой фары: видно капот, левый бок и госномер' },
  { key: 'rr', label: 'Зад и правый борт', hint: 'По диагонали с другого угла: видно багажник и правый бок' },
  { key: 'cabin', label: 'Салон через открытые двери', hint: 'Откройте переднюю и заднюю двери с одной стороны и снимите сбоку: сиденья, пол, коврики' },
];
const PHOTO_EXAM = ['selfie', 'fl', 'rr', 'cabin'];

// Памятка «Как снимать» — выезжает снизу, картинки те же, что в рассылке
function showPhotoHelp() {
  const close = () => overlay.remove();
  const tabs = h('div', { class: 'seg' });
  const img = h('img', { class: 'help-img', alt: 'Как снимать' });
  const show = which => {
    img.src = which === 'car' ? '/help-car.png' : '/help-selfie.png';
    tabs.replaceChildren(
      h('button', { type: 'button', class: 'chip' + (which === 'selfie' ? ' on' : ''), onclick: () => show('selfie') }, '🙋 Селфи'),
      h('button', { type: 'button', class: 'chip' + (which === 'car' ? ' on' : ''), onclick: () => show('car') }, '🚗 Машина'));
  };
  show('selfie');
  const overlay = h('div', { class: 'sheet-bg', onclick: e => { if (e.target === overlay) close(); } },
    h('div', { class: 'sheet', style: 'max-height:92vh;overflow:auto' },
      h('div', { class: 'sheet-head' },
        h('img', { src: '/icon-192.png', alt: '' }),
        h('div', {}, h('b', {}, 'Как снимать'), h('span', {}, 'Нажмите на картинку, чтобы увеличить'))),
      tabs,
      h('a', { href: img.src, target: '_blank', onclick: e => { e.currentTarget.href = img.src; } }, img),
      h('button', { class: 'btn', onclick: close }, 'Понятно'),
    ));
  document.body.append(overlay);
}

function showPhoto() {
  const d = { id: uuid(), type: 'Будни', comment: '' };
  const shots = {};
  const needed = () => PHOTO_SLOTS.filter(s => d.type === 'Будни' || PHOTO_EXAM.includes(s.key));

  const slotsBox = h('div');
  const renderSlots = () => slotsBox.replaceChildren(...needed().map(s =>
    photoSlot(s.label, s.hint, s.facing || 'environment', b => (shots[s.key] = b), shots[s.key])));

  const typeSeg = h('div', { class: 'seg' });
  const renderType = () => typeSeg.replaceChildren(...['Будни', 'Экзамен'].map(t =>
    h('button', { type: 'button', class: 'chip' + (d.type === t ? ' on' : ''), onclick: () => { d.type = t; renderType(); renderSlots(); } },
      (t === 'Будни' ? '🗓 ' : '🚦 ') + t)));
  renderType();
  renderSlots();

  const comment = h('textarea', { placeholder: 'Необязательно', style: 'min-height:64px', oninput: e => { d.comment = e.target.value; } });

  formScreen('Фотоотчёт', h('div', {},
    h('div', { class: 'card' },
      h('div', { class: 'field', style: 'margin-bottom:0' }, h('div', { class: 'label' }, 'Когда'), typeSeg)),
    h('div', { class: 'card' },
      h('div', { class: 'field' },
        h('div', { class: 'label', style: 'display:flex;justify-content:space-between;align-items:center' },
          h('span', {}, 'Фотографии'),
          h('button', { type: 'button', class: 'btn small secondary', onclick: showPhotoHelp }, '❓ Как снимать')),
        h('p', { class: 'hint', style: 'margin:-4px 0 10px' }, 'Кузов снимайте по диагонали с двух противоположных углов — так видно все четыре стороны. Машина должна влезать в кадр целиком, госномер читаться.'),
        slotsBox),
      h('div', { class: 'field', style: 'margin-bottom:0' }, h('label', {}, 'Комментарий'), comment)),
  ), 'Отправить фотоотчёт', async fail => {
    const missing = needed().find(s => !shots[s.key]);
    if (missing) return fail('Сделайте фото: ' + missing.label.toLowerCase());
    const fd = new FormData();
    fd.append('id', d.id);
    fd.append('type', d.type);
    fd.append('comment', d.comment);
    for (const s of PHOTO_SLOTS) if (shots[s.key]) fd.append(s.key, shots[s.key], s.key + '.jpg');
    const res = await fetch('/api/photo', { method: 'POST', credentials: 'same-origin', headers: { 'X-App': '1' }, body: fd })
      .catch(() => { throw new Error('Не удалось отправить. Проверьте интернет и попробуйте ещё раз'); });
    const r = await res.json().catch(() => ({}));
    if (!res.ok) return fail(r.error || 'Ошибка ' + res.status);
    if (state.me) state.me.photo_today = true;
    showSaved('Фотоотчёт отправлен', d.type + ' · ' + new Date().toLocaleDateString('ru-RU'), r, 'Ещё фотоотчёт', () => {
      history.replaceState(null, '', '#photo');
      showPhoto();
    }, d.id);
  });
}

// Итог автоматической проверки фото
function aiVerdict(r) {
  if (!r.ai_status) return h('div', { class: 'verdict wait' }, '⏳ Проверяется…');
  const cls = r.ai_status === 'принято' ? 'ok' : r.ai_status === 'переснять' ? 'bad' : 'warn';
  const icon = cls === 'ok' ? '✅' : cls === 'bad' ? '❌' : '⚠️';
  const title = { 'принято': 'Отчёт принят', 'переснять': 'Нужно переснять', 'нет фото': 'Фото не найдены' }[r.ai_status] || 'На проверке у администратора';
  return h('div', { class: 'verdict ' + cls },
    h('b', {}, icon + ' ' + title),
    r.ai_notes ? h('div', {}, r.ai_notes) : null);
}

// ---------- мои отчёты ----------

async function showReports() {
  screen('Мои отчёты', () => history.back(), h('div', { class: 'loading' }, 'Загрузка…'));
  try {
    const { reports } = await api('/reports');
    const sent = r => h('div', { class: 'meta' }, 'Отправлен ' + r.created + (r.synced ? '' : ' · ждёт записи в таблицу'));
    const card = r => {
      if (r.kind === 'booking') return h('div', { class: 'card report' },
        h('div', { class: 'head' }, h('b', {}, '📅 ' + r.auto), h('span', { class: 'st' }, r.date)),
        h('div', { class: 'meta' }, 'Бронь · ' + r.kpp), sent(r));
      if (r.kind === 'photo') return h('div', { class: 'card report' },
        h('div', { class: 'head' }, h('b', {}, '📸 Фотоотчёт'), h('span', { class: 'st' }, r.type)),
        aiVerdict(r),
        r.comment ? h('div', {}, r.comment) : null,
        h('div', { class: 'thumbs' }, (r.photos || []).map(p =>
          h('a', { href: p.url, target: '_blank', title: p.label }, h('img', { src: p.url + (p.url.includes('?') ? '&' : '?') + 's=1', alt: p.label, loading: 'lazy' })))),
        sent(r));
      if (r.kind === 'repair') return h('div', { class: 'card report' },
        h('div', { class: 'head' }, h('b', {}, '🔧 ' + r.auto), h('span', { class: 'st ' + (r.urgency === 'Срочно' ? 'bad' : '') }, r.urgency)),
        h('div', {}, r.problem), sent(r));
      return h('div', { class: 'card report' },
        h('div', { class: 'head' },
          h('b', {}, r.cadet),
          h('span', { class: 'st ' + (r.status === 'Сдал' ? 'ok' : 'bad') }, r.status)),
        h('div', {}, r.date + ', ' + r.start + '–' + r.end + ' · попытка ' + r.try),
        h('div', { class: 'meta' }, 'Инспектор: ' + r.inspector),
        r.error !== '-' ? h('div', { class: 'meta' }, 'Ошибка: ' + r.error) : null,
        r.comment ? h('div', { class: 'meta' }, '«' + r.comment + '»') : null,
        sent(r));
    };
    screen('Мои отчёты', () => history.back(),
      reports.length ? reports.map(card) : h('p', { class: 'sub' }, 'Здесь появятся отчёты, отправленные через приложение.'));
  } catch (ex) { if (ex.message) toast(ex.message); }
}

// ---------- админка ----------

// ---------- бюджет и аналитика рефералки (только админы) ----------

const MONTHS = ['январь', 'февраль', 'март', 'апрель', 'май', 'июнь', 'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь'];
const monthName = ym => MONTHS[Number(ym.slice(5, 7)) - 1] || ym;
const pct = (a, b) => b ? Math.round(a / b * 100) + '%' : '—';
// 1 друг, 2 друга, 5 друзей
const plural = (n, one, few, many) => n + ' ' + (n % 10 === 1 && n % 100 !== 11 ? one : n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 10 || n % 100 >= 20) ? few : many);
const rubShort = n => n >= 1e6 ? (n / 1e6).toFixed(1).replace('.', ',') + ' млн ₽' : rub(n);

async function showRefStats(fresh) {
  screen('Бюджет рефералки', () => history.back(), h('div', { class: 'loading' }, 'Считаем по Битриксу и «Отчёту РК»…'));
  let d;
  try { d = await api('/ref/stats' + (fresh ? '?fresh=1' : '')); } catch (ex) { if (ex.message) toast(ex.message); return; }

  const f = d.funnel, b = d.budget, t = d.terms;
  const built = new Date(d.built_at);
  const line = (label, value, note, cls) => h('div', { class: 'kv' + (cls ? ' ' + cls : '') },
    h('div', {}, h('div', {}, label), note ? h('div', { class: 'hint', style: 'margin:2px 0 0' }, note) : null),
    h('b', {}, value));
  // полоса: подпись, значение справа и заполнение по доле 0…1
  const bar = (label, value, ratio, cls) => h('div', { class: 'bar-row' },
    h('div', { class: 'bar-head' }, h('span', {}, label), h('b', {}, value)),
    h('div', { class: 'bar' }, h('i', { class: cls || '', style: `width:${ratio > 0 ? Math.max(2, Math.min(1, ratio) * 100) : 0}%` })));
  const part = (label, n, of, cls) => bar(label, [n, h('span', { class: 'hint' }, ' · ' + pct(n, of))], of ? n / of : 0, cls);

  // месячный лимит, если он задан в настройках рефералки
  const thisMonth = isoDate(new Date()).slice(0, 7);
  const cur = d.months.find(m => m.month === thisMonth) || { spent: 0 };
  const limit = t.budget_month ? h('div', { class: 'card' },
    h('div', { class: 'label' }, `Лимит на ${monthName(thisMonth)}`),
    bar(`${rub(cur.spent)} из ${rub(t.budget_month)}`, pct(cur.spent, t.budget_month), cur.spent / t.budget_month, cur.spent > t.budget_month ? 'bad' : '')) : null;

  // по месяцам — только те, где что-то происходило
  const months = d.months.filter(m => m.invited || m.paid || m.spent).slice(-6).reverse();
  const share = m => m.marketing > m.spent ? pct(m.spent, m.marketing) : '—';

  // каналы — за последний месяц, где расписаны не только мы
  const chMonth = Object.keys(d.channels).sort().reverse().find(k => Object.keys(d.channels[k]).length > 1);
  const ch = chMonth ? Object.entries(d.channels[chMonth]).sort((x, y) => y[1] - x[1]) : [];
  const chMax = ch.length ? ch[0][1] : 0;

  const ch70 = d.choice;
  const chosen = ch70.money + ch70.debt;

  // заявки на выплату со ссылками в Битрикс
  const pays = d.payouts || [];
  // сначала то, что реально ждёт денег, тесты — в конец
  const order = p => p.test ? 2 : p.task_done ? 1 : 0;
  const open = pays.filter(p => p.open).sort((a, b) => order(a) - order(b));
  const waiting = open.filter(p => !p.test && !p.task_done);
  const stuck = open.filter(p => !p.test && p.task_done);
  const url = (type, id, user) => d.b24_url + (type === 'task' ? `/company/personal/user/${user || 0}/tasks/task/view/${id}/` : `/crm/${type}/details/${id}/`);
  const ext = (href, text) => h('a', { href, target: '_blank', rel: 'noopener' }, text);
  const FRIEND = { lead: 'думает', paid: 'оплатил', gibdd: 'ГИБДД', lost: 'не дошёл' };
  const payRow = p => h('div', { class: 'pay-row' },
    h('div', { class: 'pay-head' },
      h('b', {}, p.name),
      h('span', { class: 'hint' }, ruDate(p.created) + (p.method ? ' · ' + (p.method === 'debt' ? 'списание долга' : 'на карту') : ''))),
    h('div', { class: 'pay-tags' },
      p.test ? h('span', { class: 'tag none' }, 'тест') : null,
      p.dup ? h('span', { class: 'tag blocked' }, 'повтор заявки') : null,
      p.over ? h('span', { class: 'tag blocked' }, 'заявок больше, чем оплативших друзей') : null,
      p.open && p.task_done && !p.test ? h('span', { class: 'tag pending' }, 'задача закрыта, стадия не сдвинута') : null,
      p.open && !p.task_done && p.tasks.length ? h('span', { class: 'tag' }, 'задача в работе') : null,
      !p.tasks.length ? h('span', { class: 'tag pending' }, 'нет задачи') : null),
    h('div', { class: 'pay-links' },
      ext(url('deal', p.id), 'Сделка ' + p.id),
      p.tasks.map(t => ext(url('task', t.id, t.user), (t.done ? '✓ ' : '') + 'Задача ' + t.id)),
      p.friends.map(f => ext(url(f.type, f.id), `${f.type === 'lead' ? 'Лид' : 'Друг'} ${f.id} · ${FRIEND[f.status] || f.status}`))));
  const payList = (title, list, opened) => list.length ? h('details', { class: 'card pay-list', open: opened || null },
    h('summary', {}, h('span', {}, title), h('b', {}, list.length)),
    list.map(payRow)) : null;

  const fc = d.forecast;
  const ahead = fc.months.reduce((a, m) => a + m.friends, 0);

  const summary = [
    limit,

    h('h2', {}, 'Бюджет'),
    h('div', { class: 'card' },
      line('Выплачено', rub(b.spent), 'по «выгрузке расходов» в «Отчёте РК», переводы на карту — за вычетом НДФЛ'),
      line('Ждут выплаты', rub(b.to_pay), `${waiting.length} заявок с открытой задачей «Выплата»` + (stuck.length ? `; ещё ${stuck.length} — задача закрыта, а сделка не переведена в «Оплачено»` : ''), 'warn'),
      line('Впереди', rub(b.forecast), `${plural(ahead, 'друг оплатил', 'друга оплатили', 'друзей оплатили')} и ждут ГИБДД — по ${rub(t.reward)}, разбивка во вкладке «Прогноз»`),
      line('Скидки друзьям', rub(b.discounts), `${f.paid} оплативших × ${rub(t.discount)} — недополученная выручка`),
      line('Вся программа', rub(b.full), `по ${rub(t.reward + t.discount)} на каждого оплатившего`, 'total')),
    h('div', { class: 'stats' },
      h('div', { class: 'stat' }, h('b', {}, rubShort(b.revenue)), h('span', {}, 'договоры приведённых')),
      h('div', { class: 'stat' }, h('b', {}, String(b.per_rub).replace('.', ',') + ' ₽'), h('span', {}, 'договоров на 1 ₽ программы')),
      h('div', { class: 'stat' }, h('b', {}, rub(t.reward + t.discount)), h('span', {}, 'стоит один ученик'))),

    h('h2', {}, 'Заявки на выплату'),
    payList('На стадии «Выплата»', open, true),
    payList('Оплачено', pays.filter(p => !p.open)),

    h('h2', {}, 'Воронка'),
    h('div', { class: 'card' },
      part('Пришло друзей', f.invited, f.invited),
      part('Оплатили обучение', f.paid, f.invited, 'ok'),
      part('Зарегистрированы в ГИБДД', f.gibdd, f.invited, 'ok'),
      part('Выплата прошла', d.c70.won, f.invited, 'ok'),
      h('p', { class: 'hint' }, `Ещё думают: ${f.active}, не дошли: ${f.lost}. Друзей привели ${f.referrers} человек.`)),

    months.length ? [h('h2', {}, 'По месяцам'),
      h('div', { class: 'card tbl-wrap' }, h('table', { class: 'tbl' },
        h('thead', {}, h('tr', {}, h('th', {}, 'Месяц'), h('th', {}, 'Пришло'), h('th', {}, 'Оплатили'), h('th', {}, 'Выплачено'), h('th', {}, 'Доля в рекламе'))),
        h('tbody', {}, months.map(m => h('tr', {},
          h('td', {}, monthName(m.month)), h('td', {}, m.invited), h('td', {}, m.paid),
          h('td', {}, m.spent ? rub(m.spent) : '—'), h('td', {}, share(m)))))),
        h('p', { class: 'hint' }, 'Оплатили — по дате сделки в воронке «Обучение». Доля — выплаты рефералки от всех расходов на маркетинг за месяц; прочерк — расходы за месяц ещё не разнесены.'))] : null,

    h('h2', {}, 'Что выбирают при выплате'),
    h('div', { class: 'card' },
      chosen ? [part('Деньги на карту', ch70.money, chosen), part('Списать в счёт долга', ch70.debt, chosen, 'ok')]
        : h('p', { class: 'sub', style: 'margin:0' }, 'Пока нет данных.'),
      h('p', { class: 'hint' }, `Поле «Тип выплаты» заполнено в ${chosen} из ${chosen + ch70.none} сделок воронки «Рефералка». Портал заполняет его сам; по старым заявкам из чат-бота его нужно проставить вручную.`)),

    ch.length ? [h('h2', {}, `Расходы по каналам: ${monthName(chMonth)}`),
      h('div', { class: 'card' }, ch.map(([name, sum]) => bar(name, rub(sum), sum / chMax, name === 'Рефералка' ? 'ok' : 'muted')))] : null,

    d.top.length ? [h('h2', {}, 'Кто приводит больше всех'),
      h('div', { class: 'card' }, d.top.map(o => h('div', { class: 'kv' },
        h('div', {}, h('div', {}, o.name || 'Без имени'), h('div', { class: 'hint', style: 'margin:2px 0 0' }, o.phone)),
        h('b', {}, `${o.paid} из ${o.invited}`, o.gibdd ? h('span', { class: 'hint' }, ` · ГИБДД ${o.gibdd}`) : null))))] : null,

    h('p', { class: 'hint' }, `В портале рефералки ${Object.values(d.portal.users).reduce((a, n) => a + Number(n), 0)} участников, переходов по их ссылкам: ${d.portal.clicks}.`),
  ];

  // ---------- когорты: друзья по месяцу, когда пришли, и где они сейчас ----------
  const ST = {
    lead:  { t: 'думает', cls: '' },
    paid:  { t: 'оплатил, ждёт ГИБДД', cls: 'ok' },
    gibdd: { t: 'в ГИБДД', cls: 'ok' },
    stale: { t: 'оплатил давно, отметки ГИБДД нет', cls: 'pending' },
    lost:  { t: 'не дошёл', cls: 'blocked' },
  };
  const ST_ORDER = ['gibdd', 'paid', 'stale', 'lead', 'lost'];
  const byMonth = {};
  d.people.forEach(p => (byMonth[p.month] = byMonth[p.month] || []).push(p));
  const cnt = (list, s) => list.filter(p => p.status === s).length;
  const shortDate = iso => iso ? iso.slice(8, 10) + '.' + iso.slice(5, 7) : '';
  const personRow = p => h('div', { class: 'kv' },
    h('div', {},
      p.link ? ext(url(p.link[0], p.link[1]), p.name || (p.link[0] === 'lead' ? 'Лид ' : 'Сделка ') + p.link[1]) : (p.name || 'Без имени'),
      h('div', { class: 'hint', style: 'margin:2px 0 0' },
        [p.sale ? 'оплата ' + shortDate(p.sale) : null,
         p.gibdd ? 'ГИБДД ' + shortDate(p.gibdd) : null,
         p.expect ? 'ГИБДД ожидаем ' + shortDate(p.expect) : null].filter(Boolean).join(' · '))),
    h('span', { class: 'tag ' + ST[p.status].cls }, ST[p.status].t));
  const cohortCard = (m, list) => {
    const segs = ST_ORDER.map(s => [s, cnt(list, s)]).filter(([, n]) => n);
    return h('details', { class: 'card pay-list' },
      h('summary', {}, h('span', {}, monthName(m)), h('span', { class: 'hint', style: 'margin:0;flex:none' }, plural(list.length, 'друг', 'друга', 'друзей'))),
      h('div', { style: 'padding:0 16px 12px' },
        h('div', { class: 'stack' }, segs.map(([s, n]) => h('i', { class: 'st-' + s, style: `flex:${n}`, title: ST[s].t + ': ' + n }))),
        h('div', { class: 'stack-legend' }, segs.map(([s, n]) => h('span', {}, h('i', { class: 'st-' + s }), `${ST[s].t}: ${n}`))),
        h('div', { class: 'hint' }, `Начислено пригласившим: ${rub(cnt(list, 'gibdd') * t.reward)}; впереди: ${rub(cnt(list, 'paid') * t.reward)}`)),
      list.slice().sort((a, b2) => ST_ORDER.indexOf(a.status) - ST_ORDER.indexOf(b2.status)).map(p => h('div', { class: 'pay-row', style: 'padding-top:0;padding-bottom:0' }, personRow(p))));
  };
  const cohortMonths = Object.keys(byMonth).sort().reverse();
  const cohorts = [
    h('p', { class: 'sub' }, 'Друзья сгруппированы по месяцу, когда пришли по ссылке. Видно, сколько из каждого месяца уже оплатили и дошли до ГИБДД, а сколько ещё в пути. Нажмите на месяц — откроется список со ссылками в Битрикс.'),
    h('div', { class: 'card tbl-wrap' }, h('table', { class: 'tbl' },
      // колонки складываются: всего = думают + не дошли + оплатили; «в ГИБДД» — часть оплативших
      h('thead', {}, h('tr', {}, h('th', {}, 'Пришли'), h('th', {}, 'Всего'), h('th', {}, 'Думают'), h('th', {}, 'Не дошли'),
        h('th', { class: 'sep' }, 'Оплатили'), h('th', {}, 'из них в ГИБДД'))),
      h('tbody', {}, cohortMonths.map(m => {
        const list = byMonth[m];
        const paid = cnt(list, 'paid') + cnt(list, 'gibdd') + cnt(list, 'stale');
        return h('tr', {}, h('td', {}, monthName(m)), h('td', {}, list.length), h('td', {}, cnt(list, 'lead')), h('td', {}, cnt(list, 'lost')),
          h('td', { class: 'sep' }, h('b', {}, paid), h('span', { class: 'hint' }, ' ' + pct(paid, list.length))), h('td', {}, cnt(list, 'gibdd')));
      })))),
    h('p', { class: 'hint' }, 'Всего = думают + не дошли + оплатили. «Оплатили» — есть сделка в «Обучении». «Из них в ГИБДД» — часть оплативших, у кого уже стоит отметка; остальные ещё учатся до регистрации, обычно это около месяца. «Не дошли» — все сделки друга провалены или лид некачественный.'),
    cohortMonths.map(m => cohortCard(m, byMonth[m])),
  ];

  // ---------- прогноз выплат по месяцам ----------
  const curF = fc.months.find(m => m.month === thisMonth) || { friends: 0, sum: 0, overdue: 0 };
  const forecast = [
    h('div', { class: 'card' },
      h('div', { class: 'label' }, `Заложить на ${monthName(thisMonth)}`),
      h('div', { class: 'big-sum' }, rub(b.to_pay + curF.sum)),
      line('Уже должны', rub(b.to_pay), plural(waiting.length, 'заявка ждёт', 'заявки ждут', 'заявок ждут') + ' выплаты'),
      line('Дойдут до ГИБДД в этом месяце', rub(curF.sum), plural(curF.friends, 'друг', 'друга', 'друзей') + (curF.overdue ? `, из них ${curF.overdue} уже по срокам должны были пройти` : ''))),
    h('h2', {}, 'По месяцам'),
    h('div', { class: 'card' },
      fc.months.length ? fc.months.map(m => line(monthName(m.month) + ' ' + m.month.slice(0, 4), rub(m.sum),
        plural(m.friends, 'друг', 'друга', 'друзей') + (m.overdue ? ` (${m.overdue} с просрочкой отметки)` : ''), m.month === thisMonth ? 'warn' : ''))
        : h('p', { class: 'sub', style: 'margin:0' }, 'Оплативших без отметки ГИБДД нет.'),
      fc.pipeline.leads ? line('Позже, из тех, кто ещё думает', '≈ ' + rub(fc.pipeline.sum),
        `${plural(fc.pipeline.leads, 'заявка', 'заявки', 'заявок')} × ${Math.round(fc.pipeline.rate * 100)}% — столько обычно доходит до оплаты`) : null),
    h('p', { class: 'hint' },
      `Как считаем: у оплативших друзей берём «Дату продажи» и прибавляем ${plural(fc.lag, 'день', 'дня', 'дней')} — столько по нашим данным в среднем проходит до отметки «Зарегистрирован в ГИБДД». ` +
      `Если срок уже прошёл, а отметки нет, ставим выплату в текущий месяц. Оплатившие больше ${plural(fc.stale_after, 'дня', 'дней', 'дней')} назад без отметки (${fc.stale}) в прогноз не входят: ` +
      'отметку стали ставить не сразу, по ним выплаты, скорее всего, уже прошли через чат-бот. Выплата — в течение 30 дней после регистрации, поэтому часть суммы может уйти на следующий месяц.'),
  ];

  // ---------- вкладки ----------
  const TABS = [['summary', 'Сводка', summary], ['cohorts', 'Когорты', cohorts], ['forecast', 'Прогноз', forecast]];
  let tab = store.get('ref_stats_tab') || 'summary';
  const body = h('div', {});
  const tabsEl = h('div', { class: 'seg seg3 tabs' });
  const render = () => {
    tabsEl.replaceChildren(...TABS.map(([k, label]) => h('button', { class: 'chip' + (k === tab ? ' on' : ''), onclick: () => { tab = k; store.set('ref_stats_tab', k); render(); } }, label)));
    body.replaceChildren(...TABS.find(x => x[0] === tab)[2].flat(Infinity).filter(Boolean));
  };
  render();

  screen('Бюджет рефералки', () => history.back(),
    h('p', { class: 'sub' }, `Вся программа, включая чат-бот, без тестовых номеров. Обновлено ${pad(built.getHours())}:${pad(built.getMinutes())}, данные кэшируются на 10 минут.`),
    tabsEl,
    body,
    h('button', { class: 'btn secondary', style: 'margin-top:12px', onclick: () => showRefStats(true) }, 'Пересчитать сейчас'),
  );
}

async function showAdmin() {
  screen('Доступ инструкторов', () => history.back(), h('div', { class: 'loading' }, 'Загрузка…'));
  let data;
  try { data = await api('/admin/users'); } catch (ex) { if (ex.message) toast(ex.message); return; }

  const act = (u, action, label, cls) => h('button', { class: 'btn small ' + (cls || 'secondary'), onclick: async () => {
    const q = { reset: 'Сбросить пароль? Человек задаст новый при следующем входе.', block: 'Заблокировать доступ?', delete: 'Удалить регистрацию? Человек сможет зарегистрироваться заново.' }[action];
    if (q && !confirm(u.name + ': ' + q)) return;
    try {
      await api('/admin/user', { phone: u.phone, action });
      toast('Готово');
      state.me = await api('/me');
      showAdmin();
    } catch (ex) { if (ex.message) toast(ex.message); }
  } }, label);

  const statusTag = { none: ['не регистрировался', 'none'], pending: ['ждёт подтверждения', 'pending'], active: ['активен', ''], reset: ['пароль сброшен', 'pending'], blocked: ['заблокирован', 'blocked'] };
  const row = u => h('div', { class: 'user-row' },
    h('div', {},
      h('div', { class: 'nm' }, u.name, u.role === 'admin' ? h('span', { class: 'tag', style: 'margin-left:6px' }, 'админ') : null),
      h('div', { class: 'ph' }, formatPhone(u.phone)),
      h('span', { class: 'tag ' + statusTag[u.status][1] }, statusTag[u.status][0])),
    h('div', { class: 'acts' },
      u.status === 'pending' ? [act(u, 'approve', 'Подтвердить', ''), act(u, 'delete', '✕', 'danger')] : null,
      u.status === 'active' ? act(u, 'reset', 'Сброс пароля') : null,
      u.status === 'blocked' ? act(u, 'unblock', 'Разблокировать') : null,
      (u.status === 'active' || u.status === 'reset') && u.phone !== state.me.user.phone ? act(u, 'block', '⛔', 'danger') : null,
    ));

  const order = { pending: 0, reset: 1, active: 2, blocked: 3, none: 4 };
  const users = data.users.sort((a, b) => order[a.status] - order[b.status] || a.name.localeCompare(b.name, 'ru'));
  const pending = users.filter(u => u.status === 'pending');
  const search = h('input', { type: 'search', placeholder: 'Поиск по фамилии или телефону' });
  const list = h('div', { class: 'card' });
  const renderList = () => {
    const s = search.value.trim().toLowerCase();
    const rest = users.filter(u => u.status !== 'pending' && (!s || u.name.toLowerCase().includes(s) || u.phone.includes(s.replace(/\D/g, '') || '~')));
    list.replaceChildren(...(rest.length ? rest.map(row) : [h('p', { class: 'sub', style: 'margin:0' }, 'Никого не найдено')]));
  };
  search.addEventListener('input', renderList);
  renderList();

  const registered = users.filter(u => u.status !== 'none').length;
  screen('Доступ инструкторов', () => history.back(),
    data.unsynced ? h('div', { class: 'alert err' }, `Не записано в таблицу: ${data.unsynced}. ${data.sync_error || ''}`) : null,
    h('p', { class: 'sub' }, `В «Верификации» ${users.length} чел., зарегистрировались ${registered}. Список берётся из Google-таблицы — чтобы дать доступ, добавьте человека туда.`),
    pending.length ? [h('h2', {}, 'Ждут подтверждения'), h('div', { class: 'card' }, pending.map(row))] : null,
    h('h2', {}, 'Все'),
    h('div', { class: 'field' }, search),
    list,
    h('button', { class: 'btn secondary', onclick: async ev => {
      ev.target.disabled = true;
      try { await api('/admin/refresh', {}); state.me = await api('/me'); toast('Списки обновлены из таблицы'); showAdmin(); }
      catch (ex) { if (ex.message) toast(ex.message); ev.target.disabled = false; }
    } }, 'Обновить списки из таблицы'),
  );
}

// ---------- старт ----------

(async function init() {
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js').catch(() => {});
  try {
    await loadMe();
    route();
  } catch (e) {
    if (!state.me) showLogin();
  }
})();
