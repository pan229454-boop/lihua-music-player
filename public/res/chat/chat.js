/* 留言板前台小组件（index 首页通用）
 * 由 app/controller/Index.php 统一注入到所有首页主题，主题文件不用改
 * 不依赖 jQuery / layui；所有文本都用 textContent 输出，避免 XSS
 */
(function () {
  'use strict';

  if (window.__lhyChatLoaded) { return; }
  window.__lhyChatLoaded = true;

  var API = '/ChatAjax';
  var POLL = 8000;
  var LIMIT = 30;
  var MAXLEN = 200;
  var K_SEEN = 'lhyc_seen_id';
  var K_NICK = 'lhyc_nick';
  var K_QQ = 'lhyc_qq';

  var st = { open: false, lastId: 0, timer: null, sending: false, enable: 1, login: null, booted: false };

  function byId(id) { return document.getElementById(id); }

  function intval(v) { var n = parseInt(v, 10); return isNaN(n) ? 0 : n; }

  function store(key, value) {
    try {
      if (typeof value === 'undefined') { return window.localStorage.getItem(key) || ''; }
      window.localStorage.setItem(key, value);
    } catch (e) { return ''; }
    return value;
  }

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (typeof text === 'string') { n.textContent = text; }
    return n;
  }

  function done(xhr, cb) {
    if (xhr.readyState !== 4) { return; }
    var res = null;
    try { res = JSON.parse(xhr.responseText); } catch (e) { res = null; }
    cb(res, xhr.status);
  }

  function apiGet(url, cb) {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onreadystatechange = function () { done(xhr, cb); };
    xhr.send(null);
  }

  function apiPost(url, data, cb) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', url, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onreadystatechange = function () { done(xhr, cb); };
    var body = '';
    for (var k in data) {
      if (Object.prototype.hasOwnProperty.call(data, k)) {
        body += encodeURIComponent(k) + '=' + encodeURIComponent(data[k] === null || typeof data[k] === 'undefined' ? '' : data[k]) + '&';
      }
    }
    xhr.send(body);
  }

  function svgIcon() {
    var NS = 'http://www.w3.org/2000/svg';
    var svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('width', '18');
    svg.setAttribute('height', '18');
    svg.setAttribute('aria-hidden', 'true');
    var path = document.createElementNS(NS, 'path');
    path.setAttribute('fill', 'currentColor');
    path.setAttribute('d', 'M4 3h16a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H9l-5 4V5a2 2 0 0 1 2-2z');
    svg.appendChild(path);
    return svg;
  }

  // ---------- 界面 ----------
  function build() {
    var launch = el('button', 'lhyc-launch');
    launch.type = 'button';
    launch.id = 'lhycLaunch';
    launch.setAttribute('aria-label', '打开留言板');
    launch.appendChild(svgIcon());
    launch.appendChild(el('span', 'lhyc-launch-text', '留言板'));
    var badge = el('em', 'lhyc-badge', '');
    badge.id = 'lhycBadge';
    badge.hidden = true;
    launch.appendChild(badge);

    var mask = el('div', 'lhyc-mask');
    mask.id = 'lhycMask';
    mask.hidden = true;

    var panel = el('aside', 'lhyc-panel');
    panel.id = 'lhycPanel';
    panel.hidden = true;
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', '留言板');

    var head = el('div', 'lhyc-head');
    var title = el('div', 'lhyc-title');
    title.appendChild(el('strong', 'lhyc-strong', '留言板'));
    title.appendChild(el('span', 'lhyc-sub', '友善发言 · 广告会被删除'));
    head.appendChild(title);
    var close = el('button', 'lhyc-close', '×');
    close.type = 'button';
    close.id = 'lhycClose';
    close.setAttribute('aria-label', '关闭留言板');
    head.appendChild(close);
    panel.appendChild(head);

    var list = el('div', 'lhyc-list');
    list.id = 'lhycList';
    list.appendChild(el('div', 'lhyc-empty', '加载中…'));
    panel.appendChild(list);

    var form = el('form', 'lhyc-form');
    form.id = 'lhycForm';
    form.setAttribute('autocomplete', 'off');

    var who = el('div', 'lhyc-who');
    who.id = 'lhycWho';
    who.hidden = true;
    form.appendChild(who);

    var guest = el('div', 'lhyc-guest');
    guest.id = 'lhycGuest';
    var nick = el('input', 'lhyc-input');
    nick.id = 'lhycNick';
    nick.type = 'text';
    nick.maxLength = 20;
    nick.placeholder = '昵称（必填）';
    var qq = el('input', 'lhyc-input');
    qq.id = 'lhycQq';
    qq.type = 'text';
    qq.maxLength = 15;
    qq.placeholder = 'QQ（选填）';
    guest.appendChild(nick);
    guest.appendChild(qq);
    form.appendChild(guest);

    var content = el('textarea', 'lhyc-textarea');
    content.id = 'lhycContent';
    content.rows = 3;
    content.maxLength = MAXLEN;
    content.placeholder = '说点什么吧～（最多 ' + MAXLEN + ' 个字）';
    form.appendChild(content);

    var foot = el('div', 'lhyc-foot');
    var count = el('span', 'lhyc-count', '0/' + MAXLEN);
    count.id = 'lhycCount';
    foot.appendChild(count);
    var hint = el('span', 'lhyc-hint', '');
    hint.id = 'lhycHint';
    foot.appendChild(hint);
    var sendBtn = el('button', 'lhyc-send', '发送');
    sendBtn.type = 'submit';
    sendBtn.id = 'lhycSend';
    foot.appendChild(sendBtn);
    form.appendChild(foot);
    panel.appendChild(form);

    document.body.appendChild(launch);
    document.body.appendChild(mask);
    document.body.appendChild(panel);
    bind();
  }

  function bind() {
    var launch = byId('lhycLaunch');
    var close = byId('lhycClose');
    var mask = byId('lhycMask');
    var form = byId('lhycForm');
    var content = byId('lhycContent');

    launch.addEventListener('click', openPanel);
    close.addEventListener('click', closePanel);
    mask.addEventListener('click', closePanel);
    form.addEventListener('submit', function (e) { e.preventDefault(); send(); });
    content.addEventListener('input', updateCount);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && st.open) { closePanel(); }
    });
  }

  function updateCount() {
    var content = byId('lhycContent');
    var count = byId('lhycCount');
    if (!content || !count) { return; }
    count.textContent = content.value.length + '/' + MAXLEN;
  }

  function hint(msg) {
    var h = byId('lhycHint');
    if (!h) { return; }
    h.textContent = msg || '';
    if (msg) {
      window.clearTimeout(hint._t);
      hint._t = window.setTimeout(function () { h.textContent = ''; }, 4000);
    }
  }

  function updateBadge(n) {
    var badge = byId('lhycBadge');
    if (!badge) { return; }
    if (!n) { badge.hidden = true; badge.textContent = ''; return; }
    badge.hidden = false;
    badge.textContent = n > 99 ? '99+' : String(n);
  }

  function applyLogin() {
    var guest = byId('lhycGuest');
    var who = byId('lhycWho');
    if (!guest || !who) { return; }
    if (st.login) {
      guest.hidden = true;
      who.hidden = false;
      who.textContent = '以「' + st.login.nickname + '」的身份留言';
    } else {
      guest.hidden = false;
      who.hidden = true;
      var nick = byId('lhycNick');
      var qq = byId('lhycQq');
      if (nick && !nick.value) { nick.value = store(K_NICK); }
      if (qq && !qq.value) { qq.value = store(K_QQ); }
    }
  }

  function setEnabled(on) {
    st.enable = on ? 1 : 0;
    var form = byId('lhycForm');
    if (form) { form.hidden = !on; }
    var sub = document.querySelector('.lhyc-sub');
    if (sub) { sub.textContent = on ? '友善发言 · 广告会被删除' : '留言板已关闭'; }
  }

  function renderMsg(m) {
    var item = el('div', 'lhyc-item');
    item.setAttribute('data-id', String(intval(m.id)));
    var head = el('div', 'lhyc-item-head');
    head.appendChild(el('span', 'lhyc-name', String(m.nickname || '匿名')));
    if (intval(m.is_admin) === 1) { head.appendChild(el('span', 'lhyc-tag', '站长')); }
    head.appendChild(el('span', 'lhyc-time', String(m.time_tran || m.time || '')));
    item.appendChild(head);
    item.appendChild(el('div', 'lhyc-text', String(m.content || '')));
    return item;
  }

  function appendRows(rows, replace) {
    var list = byId('lhycList');
    if (!list) { return; }
    if (replace) {
      while (list.firstChild) { list.removeChild(list.firstChild); }
    }
    if (!rows || !rows.length) {
      if (replace) { list.appendChild(el('div', 'lhyc-empty', '还没有人留言，来抢第一个吧～')); }
      return;
    }
    var empty = list.querySelector('.lhyc-empty');
    if (empty && empty.parentNode === list) { list.removeChild(empty); }
    for (var i = 0; i < rows.length; i++) {
      var row = rows[i];
      if (list.querySelector('.lhyc-item[data-id="' + intval(row.id) + '"]')) { continue; }
      list.appendChild(renderMsg(row));
      if (intval(row.id) > st.lastId) { st.lastId = intval(row.id); }
    }
    list.scrollTop = list.scrollHeight;
  }

  function onData(res) {
    if (!res || res.code !== 0) { return false; }
    st.login = (res.login && res.login.nickname) ? res.login : null;
    applyLogin();
    setEnabled(intval(res.enable) === 1);
    return true;
  }

  function load(replace, doneCb) {
    var url = API + '/lists?limit=' + LIMIT + (replace ? '' : '&id=' + st.lastId);
    apiGet(url, function (res, status) {
      if (!onData(res)) {
        if (replace) { appendRows([], true); }
        return;
      }
      appendRows(res.data || [], !!replace);
      store(K_SEEN, String(st.lastId));
      updateBadge(0);
      if (doneCb) { doneCb(res); }
    });
  }

  function openPanel() {
    if (st.open) { return; }
    st.open = true;
    var panel = byId('lhycPanel');
    var mask = byId('lhycMask');
    if (!panel || !mask) { return; }
    panel.hidden = false;
    mask.hidden = false;
    window.setTimeout(function () { panel.classList.add('lhyc-in'); mask.classList.add('lhyc-in'); }, 10);
    updateBadge(0);
    refresh();
    st.timer = window.setInterval(poll, POLL);
    var content = byId('lhycContent');
    if (content && window.matchMedia && window.matchMedia('(min-width: 760px)').matches) { content.focus(); }
  }

  function closePanel() {
    if (!st.open) { return; }
    st.open = false;
    var panel = byId('lhycPanel');
    var mask = byId('lhycMask');
    if (panel) { panel.classList.remove('lhyc-in'); }
    if (mask) { mask.classList.remove('lhyc-in'); }
    window.setTimeout(function () {
      if (panel && !st.open) { panel.hidden = true; }
      if (mask && !st.open) { mask.hidden = true; }
    }, 260);
    if (st.timer) { window.clearInterval(st.timer); st.timer = null; }
  }

  function refresh() {
    st.lastId = 0;
    load(true);
  }

  function poll() {
    if (!st.open) { return; }
    load(false);
  }

  function send() {
    if (st.sending) { return; }
    var contentEl = byId('lhycContent');
    var content = contentEl ? contentEl.value.replace(/^\s+|\s+$/g, '') : '';
    if (!content) { hint('先写点什么再发送吧'); return; }
    if (content.length > MAXLEN) { hint('最多 ' + MAXLEN + ' 个字'); return; }
    if (!st.enable) { hint('留言板已关闭'); return; }

    var data = { content: content };
    if (!st.login) {
      var nickEl = byId('lhycNick');
      var qqEl = byId('lhycQq');
      var nick = nickEl ? nickEl.value.replace(/^\s+|\s+$/g, '') : '';
      if (!nick) { hint('请先填写昵称'); if (nickEl) { nickEl.focus(); } return; }
      data.nickname = nick;
      data.qq = qqEl ? qqEl.value.replace(/^\s+|\s+$/g, '') : '';
      store(K_NICK, nick);
      store(K_QQ, data.qq);
    }

    st.sending = true;
    var btn = byId('lhycSend');
    if (btn) { btn.disabled = true; btn.textContent = '发送中…'; }
    apiPost(API + '/send', data, function (res) {
      st.sending = false;
      if (btn) { btn.disabled = false; btn.textContent = '发送'; }
      if (!res) { hint('网络不太顺，稍后再试'); return; }
      if (res.code !== 0) { hint(res.msg || '发送失败'); return; }
      hint(res.msg || '留言成功');
      if (contentEl) { contentEl.value = ''; }
      updateCount();
      if (res.data) { appendRows([res.data], false); }
      store(K_SEEN, String(st.lastId));
    });
  }

  function boot() {
    if (st.booted) { return; }
    st.booted = true;
    build();
    // 支持用 /?chat=1 或 #chat 直接展开留言板（分享链接时很有用）
    if (/(^|[?&#])chat=1/.test(window.location.search + window.location.hash)) {
      openPanel();
      return;
    }
    apiGet(API + '/lists?limit=1', function (res) {
      if (!onData(res)) { return; }
      var rows = res.data || [];
      var latest = rows.length ? intval(rows[rows.length - 1].id) : intval(res.total);
      st.lastId = latest;
      var seen = intval(store(K_SEEN));
      if (seen === 0) {
        // 第一次来的访客不显示红点，只是记住当前位置
        store(K_SEEN, String(latest));
      } else if (latest > seen) {
        updateBadge(latest - seen);
      } else {
        store(K_SEEN, String(latest));
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
