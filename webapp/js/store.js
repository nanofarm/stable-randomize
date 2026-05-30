
export const API_URL = location.origin + '/api'

export const tg = window.Telegram ? Telegram.WebApp : null

export const store = Vue.reactive({
  user: null,
  myChannels: [],
  sourceChannelId: null,
  referredBy: null,
  botUsername: 'YourBotUsername',
  csrfToken: null,
})

export async function loadCsrfToken() {
  try {
    const r = await fetch(API_URL + '/csrf-token', {
      credentials: 'include',
      headers: tg?.initData ? { 'X-Telegram-Init-Data': tg.initData } : {}
    })
    const data = await r.json()
    if (data.ok && data.token) {
      store.csrfToken = data.token
      document.cookie = `XSRF-TOKEN=${data.token}; path=/; SameSite=Lax`
    }
  } catch (e) {
    console.error('CSRF load failed:', e)
  }
}

export const toastState = Vue.reactive({
  msg: '',
  type: 'ok',
  visible: false,
})

let _toastTimer = null
export function toast(msg, type = 'ok') {
  toastState.msg = msg
  toastState.type = type
  toastState.visible = true
  clearTimeout(_toastTimer)
  _toastTimer = setTimeout(() => toastState.visible = false, 3000)
}

export function initUser() {
  if (tg && tg.initDataUnsafe && tg.initDataUnsafe.user) {
    const u = tg.initDataUnsafe.user
    store.user = {
      id: u.id,
      first_name: u.first_name || '',
      last_name: u.last_name || '',
      username: u.username || '',
    }
  }
  if (!store.user) {
    store.user = null
    return
  }
}

export async function api(endpoint, options = {}) {
  try {
    const isFormData = options.body instanceof FormData
    const isMutating = !['GET', 'HEAD'].includes(options.method || 'GET')
    const headers = {
      ...(isFormData ? {} : { 'Content-Type': 'application/json' }),
      ...(options.headers || {}),
    }
    if (tg && tg.initData) {
      headers['X-Telegram-Init-Data'] = tg.initData
    }
    if (isMutating && store.csrfToken) {
      headers['X-CSRF-Token'] = store.csrfToken
    }
    const controller = new AbortController()
    const timeoutMs = options.timeoutMs || 15000
    const t = setTimeout(() => controller.abort(), timeoutMs)
    const resp = await fetch(API_URL + endpoint, {
      ...options,
      credentials: 'include',
      headers,
      signal: controller.signal,
    }).finally(() => clearTimeout(t))
    const text = await resp.text()
    let data
    try { data = JSON.parse(text) } catch { data = { ok: false, error: 'Сервер не отвечает' } }
    if (!resp.ok && data.ok === undefined) data.ok = false
    if (!resp.ok && !data.error) data.error = `Ошибка сервера (${resp.status})`
    if (data.error) data.error = friendlyError(data.error)
    return data
  } catch (e) {
    if (e && e.name === 'AbortError') return { ok: false, error: 'Запрос слишком долгий — попробуй ещё раз' }
    return { ok: false, error: 'Нет связи с сервером. Проверь интернет' }
  }
}

const ERROR_MAP = {
  'Unauthorized':            'Сессия истекла — перезайди в приложение',
  'unauthorized':            'Сессия истекла — перезайди в приложение',
  'Not found':               'Не найдено — возможно, удалено',
  'Forbidden':               'Нет доступа',
  'Only creator':            'Только создатель может это сделать',
  'Only creator can delete': 'Только создатель может удалить',
  'Already joined':          'Ты уже участвуешь',
  'Already attached':        'Канал уже привязан',
  'No participants':         'Нет участников',
  'Finished':                'Розыгрыш уже завершён',
  'Bad response':            'Сервер не отвечает',
  'Channel not yours':       'Это не твой канал',
  'Not a participant':       'Сначала прими участие',
  'No condition':            'Условие для ника не задано',
  'bot_not_admin':           'Бот не добавлен как админ в канал',
  'Only channels/supergroups': 'Можно добавить только канал или группу',
  'session_required':        'Перезайди в приложение',
  'csrf_mismatch':           'Перезайди в приложение',
  'Cannot get chat info':    'Не удалось получить данные канала — проверь, что бот добавлен',
  'Cannot get user info from Telegram': 'Не удалось проверить ник — попробуй позже',
  'user_id required':        'Ошибка авторизации — перезайди',
}
function friendlyError(err) {
  if (!err || typeof err !== 'string') return 'Что-то пошло не так'
  if (ERROR_MAP[err]) return ERROR_MAP[err]
  if (err.startsWith('HTTP 4')) return 'Ошибка запроса — попробуй перезайти'
  if (err.startsWith('HTTP 5')) return 'Сервер временно недоступен — попробуй позже'
  if (/[а-яА-Я]/.test(err)) return err
  return 'Что-то пошло не так — попробуй ещё раз'
}

export function haptic(type) {
  if (!tg || !tg.HapticFeedback) return
  try {
    if (type === 'sel') tg.HapticFeedback.selectionChanged()
    else if (type === 'ok') tg.HapticFeedback.notificationOccurred('success')
    else tg.HapticFeedback.impactOccurred('heavy')
  } catch (e) {}
}

export function fullName(user) {
  if (!user) user = store.user
  if (!user) return ''
  return (user.first_name + ' ' + (user.last_name || '')).trim()
}

export async function loadChannels() {
  if (!store.user) return
  const r = await api('/channels')
  if (r.ok) store.myChannels = r.channels || []
}

export function createParticles() {
  const container = document.getElementById('particles')
  if (!container) return
  const colors = ['var(--blue)', 'var(--purple)', 'var(--pink)', 'var(--green)']
  for (let i = 0; i < 15; i++) {
    const p = document.createElement('div')
    p.className = 'particle'
    p.style.cssText = `
      left: ${Math.random() * 100}%;
      background: ${colors[i % colors.length]};
      width: ${2 + Math.random() * 3}px;
      height: ${2 + Math.random() * 3}px;
      animation-duration: ${8 + Math.random() * 12}s;
      animation-delay: ${Math.random() * 10}s;
      box-shadow: 0 0 ${4 + Math.random() * 6}px currentColor;
    `
    container.appendChild(p)
  }
}
