
import { store, api, toast, haptic, tg, fullName, loadChannels } from '../store.js'

const PRIZE_COLORS = [
  { bg:'#2A2218', color:'#F5C842', border:'#3D3020', icon:'🥇' },
  { bg:'#1E2228', color:'#A8B8CC', border:'#2A3040', icon:'🥈' },
  { bg:'#221A18', color:'#CD7F50', border:'#3A2820', icon:'🥉' },
  { bg:'#1C2224', color:'#7E9EAC', border:'#263238', icon:'🏅' },
  { bg:'#1E1E2A', color:'#8880CC', border:'#28284A', icon:'🏅' },
]

const ACCENT = '#6C5CE7'

export default {
  name: 'DetailPage',
  props: {
    giveawayId: { type: String, required: true },
  },
  emits: ['back', 'draw', 'reload', 'analytics'],
  data() {
    return {
      g: null,
      loading: true,
      subStatuses: {},
      endDate: '',
      endDateDisplay: '',
      nickChecking: false,
      nickResult: null,
      showPhonePrompt: false,
      verifying: false,
      verifyResult: null,
      showFairness: false,
    }
  },
  computed: {
    isOwner() {
      return this.g && String(this.g.creator_id) === String(store.user.id)
    },
    joined() {
      if (this.g && this.g.is_participant !== undefined) return !!this.g.is_participant
      if (!this.g || !this.g.participants) return false
      return this.g.participants.some(p => String(p.user_id) === String(store.user.id))
    },
    myParticipant() {
      if (this.g && this.g.is_participant !== undefined) {
        return this.g.is_participant ? { tickets: this.g.my_tickets || 1, nickname_bonus: this.g.my_nickname_bonus || false } : null
      }
      if (!this.g || !this.g.participants) return null
      return this.g.participants.find(p => String(p.user_id) === String(store.user.id))
    },
    myTickets() {
      if (this.g && this.g.my_tickets !== undefined) return this.g.my_tickets || 1
      return this.myParticipant ? (this.myParticipant.tickets || 1) : 1
    },
    myReferrals() {
      if (!this.g || !this.g.participants) return []
      return this.g.participants.filter(p => p.referred_by && String(p.referred_by) === String(store.user.id))
    },
    winnersCount() {
      if (this.g.prizes && this.g.prizes.length) return this.g.prizes.length
      return this.g.winners_count
    },
    startCountdown() {
      if (!this.g.start_date || this.g.status !== 'active') return null
      const diff = new Date(this.g.start_date) - new Date()
      if (diff <= 0) return null
      return Math.floor(diff / 864e5) + 'д ' + Math.floor((diff % 864e5) / 36e5) + 'ч'
    },
    endCountdown() {
      if (!this.g.end_date || this.g.status !== 'active') return null
      const diff = new Date(this.g.end_date) - new Date()
      if (diff <= 0) return null
      const d = Math.floor(diff / 864e5)
      const h = Math.floor((diff % 864e5) / 36e5)
      const m = Math.floor((diff % 36e5) / 6e4)
      return d + 'д ' + h + 'ч ' + m + 'мин'
    },
    myChannels() { return store.myChannels },
    attachedChannelIds() {
      if (!this.g || !this.g.channels) return []
      return this.g.channels.map(c => c.id)
    },
    otherChannels() {
      if (!this.g || !this.g.channels) return []
      const myIds = store.myChannels.map(c => c.id)
      return this.g.channels.filter(c => !myIds.includes(c.id))
    },
    prizeColors() { return PRIZE_COLORS },
    fmtParticipants() {
      const n = this.g ? (this.g.participant_count || 0) : 0
      return n >= 1000 ? (n / 1000).toFixed(1).replace('.0', '') + 'K' : n
    },
    adminTasks() {
      return (this.g && this.g.tasks) || []
    },
    mySubmissions() {
      if (this.g && this.g.my_submissions !== undefined) return this.g.my_submissions || {}
      if (!this.g || !this.g.task_submissions) return {}
      const map = {}
      this.g.task_submissions
        .filter(ts => String(ts.user_id) === String(store.user.id))
        .forEach(ts => { map[ts.task_id] = ts.status })
      return map
    },
    maxTickets() { return 30 },
    ticketProgress() {
      const t = this.myTickets
      return Math.min(100, Math.round(t / this.maxTickets * 100))
    },
    progressPercent() {
      if (!this.g || this.g.status !== 'active' || !this.g.end_date) return null
      const start = this.g.start_date
        ? new Date(this.g.start_date).getTime()
        : new Date(this.g.created_at || Date.now()).getTime()
      const end = new Date(this.g.end_date).getTime()
      const now = Date.now()
      if (now <= start) return 0
      if (now >= end) return 100
      return Math.round(((now - start) / (end - start)) * 100)
    },
  },
  async mounted() {
    await loadChannels()
    await this.load()
  },
  methods: {
    async load() {
      this.loading = true
      const r = await api('/giveaway/' + this.giveawayId)
      if (r.ok) {
        this.g = r.giveaway
        if (this.g.end_date) {
          this.endDate = this.g.end_date
          this.endDateDisplay = new Date(this.g.end_date).toLocaleString('ru-RU')
        }
        if (this.g.status === 'active' && this.g.channels && this.g.channels.length) {
          this.checkSubscriptions()
        }
      }
      this.loading = false
    },

    async checkSubscriptions() {
      const r = await api('/giveaway/check-subscription', {
        method: 'POST',
        body: JSON.stringify({ giveaway_id: this.giveawayId, user_id: store.user.id }),
      })
      if (r.ok && r.channels) {
        const map = {}
        r.channels.forEach(ch => { map[Math.abs(ch.chat_id)] = ch.is_subscribed })
        this.subStatuses = map
      }
    },

    async startJoin() {
      const check = await api(`/phone/check?user_id=${store.user.id}`)
      if (check.verified) {
        if (check.is_russian) {
          await this.doJoin()
        } else {
          haptic('err')
          toast('Участие доступно только с российским номером (+7)', 'err')
        }
        return
      }
      haptic('err')
      this.showPhonePrompt = true
    },

    openBotForPhone() {
      if (tg) {
        tg.openTelegramLink(`https://t.me/${store.botUsername}?start=verify`)
        tg.close()
      }
    },

    async doJoin() {
      if (!store.user || !store.user.id) {
        toast('Откройте розыгрыш внутри Telegram', 'err')
        return
      }

      const joinData = {
        giveaway_id: this.giveawayId,
        user_id: store.user.id,
        user_name: fullName(),
        language_code: tg && tg.initDataUnsafe && tg.initDataUnsafe.user ? tg.initDataUnsafe.user.language_code : null,
        is_premium: tg && tg.initDataUnsafe && tg.initDataUnsafe.user ? tg.initDataUnsafe.user.is_premium : false,
        source: 'webapp',
        username: store.user.username || null,
        source_channel_id: store.sourceChannelId,
      }
      if (store.referredBy) joinData.referred_by = store.referredBy

      const r = await api('/giveaway/join', { method: 'POST', body: JSON.stringify(joinData) })
      if (r.ok) {
        haptic('ok')
        toast('Вы участвуете!', 'ok')
        await this.load()
      } else if (r.error === 'not_subscribed') {
        toast('Сначала подпишись на все каналы', 'err')
      } else {
        toast(r.error || 'Что-то пошло не так', 'err')
      }
    },

    async launch() {
      if (this.endDate) {
        const ur = await api('/giveaway/update-date', {
          method: 'POST',
          body: JSON.stringify({ giveaway_id: this.giveawayId, creator_id: store.user.id, end_date: this.endDate }),
        })
        if (!ur.ok) {
          toast(ur.error || 'Не удалось сохранить дату', 'err')
          return
        }
      }
      const r = await api('/giveaway/launch', {
        method: 'POST',
        body: JSON.stringify({ giveaway_id: this.giveawayId, creator_id: store.user.id }),
      })
      if (r.ok) {
        haptic('ok')
        toast('Розыгрыш запущен!', 'ok')
        await this.load()
      } else {
        toast(r.error || 'Что-то пошло не так', 'err')
      }
    },

    async draw() {
      this.$emit('draw', this.giveawayId)
    },

    submitTask(taskId) {
      haptic('sel')
      const url = `https://t.me/${store.botUsername}?start=task_${this.giveawayId}_${taskId}`
      if (tg && tg.openTelegramLink) {
        tg.openTelegramLink(url)
        setTimeout(() => { try { tg.close() } catch (e) {} }, 200)
      } else {
        window.open(url, '_blank')
      }
    },

    async refreshPosts() {
      haptic('ok')
      const r = await api('/giveaway/refresh-posts', {
        method: 'POST',
        body: JSON.stringify({ giveaway_id: this.g.public_id, creator_id: store.user.id }),
      })
      if (r.ok) {
        toast('Посты обновлены ✓', 'ok')
        await this.load()
      } else {
        toast(r.error || 'Что-то пошло не так', 'err')
      }
    },

    async toggleDraftChannel(channelId) {
      const attached = this.attachedChannelIds.includes(channelId)
      if (attached) {
        const r = await api('/giveaway/detach-channel', {
          method: 'POST',
          body: JSON.stringify({ giveaway_id: this.giveawayId, channel_id: channelId, creator_id: store.user.id }),
        })
        if (r.ok) this.g.channels = this.g.channels.filter(c => c.id !== channelId)
      } else {
        const r = await api('/giveaway/attach-channel', {
          method: 'POST',
          body: JSON.stringify({ giveaway_id: this.giveawayId, channel_id: channelId, owner_id: store.user.id }),
        })
        if (r.ok && r.channel) {
          r.channel.publish = true
          this.g.channels.push(r.channel)
        }
      }
      haptic('sel')
    },

    async detachOtherChannel(channelId) {
      if (!confirm('Убрать канал?')) return
      const r = await api('/giveaway/detach-channel', {
        method: 'POST',
        body: JSON.stringify({ giveaway_id: this.giveawayId, channel_id: channelId, creator_id: store.user.id }),
      })
      if (r.ok) {
        toast('Канал убран', 'ok')
        await this.load()
      } else {
        toast(r.error || 'Что-то пошло не так', 'err')
      }
    },

    async addChannelViBot() {
      const r = await api('/giveaway/request-channel', {
        method: 'POST',
        body: JSON.stringify({ giveaway_id: this.giveawayId, creator_id: store.user.id }),
      })
      if (r.ok) {
        toast('Открой бота — там ждёт кнопка выбора канала', 'ok')
        if (tg) setTimeout(() => tg.openTelegramLink(`https://t.me/${store.botUsername}`), 1000)
      } else {
        toast(r.error || 'Что-то пошло не так', 'err')
      }
    },

    copyAdminLink() {
      const url = `https://t.me/${store.botUsername}?start=addch_${this.giveawayId}`
      if (navigator.clipboard) navigator.clipboard.writeText(url)
      toast('Ссылка скопирована! Отправь другому админу', 'ok')
      haptic('sel')
    },

    share() {
      const url = `https://t.me/${store.botUsername}?start=join_${this.giveawayId}`
      if (navigator.clipboard) navigator.clipboard.writeText(url)
      toast('Ссылка скопирована!', 'ok')
      haptic('sel')
    },

    async checkNickname() {
      this.nickChecking = true
      this.nickResult = null
      const r = await api('/giveaway/check-nickname', {
        method: 'POST',
        body: JSON.stringify({ giveaway_id: this.giveawayId, user_id: store.user.id }),
      })
      this.nickChecking = false
      if (r.ok && (r.matched || r.already)) {
        this.nickResult = 'ok'
        haptic('ok')
        toast('Бонус x' + (this.g.nickname_bonus_multiplier || 10) + ' получен!', 'ok')
        await this.load()
      } else if (r.ok && !r.matched) {
        this.nickResult = 'fail'
        haptic('err')
        toast('В твоём нике нет нужного слова — добавь и попробуй снова', 'err')
      } else {
        toast(r.error || 'Что-то пошло не так', 'err')
      }
    },

    copyRefLink() {
      const url = `https://t.me/${store.botUsername}?start=join_${this.giveawayId}_ref_${store.user.id}`
      if (navigator.clipboard) navigator.clipboard.writeText(url)
      toast('Реферальная ссылка скопирована!', 'ok')
      haptic('sel')
    },

    async exportCsv() {
      const r = await api('/analytics/' + this.giveawayId + '/csv-token')
      if (r.ok && r.url) {
        if (tg) tg.openLink(r.url)
        else window.open(r.url, '_blank')
        toast('Загружаем CSV...', 'ok')
      } else {
        toast(r.error || 'Что-то пошло не так', 'err')
      }
    },

    openAnalytics() {
      this.$emit('analytics', this.giveawayId)
      haptic('sel')
    },

    openChannel(ch) {
      const url = ch.link || (ch.username ? 'https://t.me/' + ch.username : null)
      if (!url) return
      if (tg && tg.openTelegramLink) tg.openTelegramLink(url)
      else window.open(url, '_blank')
    },

    channelUrl(ch) {
      return ch.link || (ch.username ? 'https://t.me/' + ch.username : null)
    },

    async uploadPhoto(event) {
      const file = event.target.files[0]
      if (!file) return
      if (file.size > 10 * 1024 * 1024) {
        toast('Файл слишком большой (макс. 10 МБ)', 'err')
        event.target.value = ''
        return
      }
      const fd = new FormData()
      fd.append('photo', file)
      fd.append('giveaway_id', this.giveawayId)
      fd.append('creator_id', store.user.id)
      toast('Загружаем фото...', 'ok')
      try {
        const r = await api('/giveaway/upload-photo', { method: 'POST', body: fd, timeoutMs: 60000 })
        if (r.ok) {
          haptic('ok')
          toast('Фото загружено!', 'ok')
          await this.load()
        } else {
          console.error('Upload error:', r)
          toast(r.error || 'Не удалось загрузить фото', 'err')
        }
      } catch (e) {
        console.error('Upload exception:', e)
        toast('Ошибка сети при загрузке', 'err')
      }
      event.target.value = ''
    },

    async verifyIntegrity() {
      if (this.verifying) return
      this.verifying = true
      this.verifyResult = null
      try {
        const r = await api('/giveaway/' + this.giveawayId + '/verify-integrity', { method: 'GET' })
        if (r.ok && r.verification) {
          this.verifyResult = r.verification
          haptic(r.verification.verified ? 'ok' : 'err')
        } else {
          toast('Данные проверки недоступны', 'err')
        }
      } catch (e) {
        toast('Не удалось проверить — попробуй позже', 'err')
      }
      this.verifying = false
    },

    async deleteGiveaway() {
      if (!confirm('Удалить розыгрыш? Это действие нельзя отменить.')) return
      const r = await api('/giveaway/delete', { method: 'POST', body: JSON.stringify({ giveaway_id: this.giveawayId }) })
      if (r.ok) {
        haptic('ok')
        toast('Розыгрыш удалён', 'ok')
        this.$emit('back')
      } else {
        toast(r.error || 'Не удалось удалить', 'err')
      }
    },

    openDatePicker(target) {
      this.$emit('open-date-picker', { target, value: target === 'end' ? this.endDate : null })
    },

    onDateSelected(data) {
      if (data.target === 'end') {
        this.endDate = data.value
        this.endDateDisplay = data.display || 'Без таймера — завершить вручную'
      }
    },

    subStatus(ch) {
      const key = Math.abs(ch.chat_id)
      if (this.subStatuses[key] === true) return 'ok'
      if (this.subStatuses[key] === false) return 'no'
      return 'loading'
    },
  },

  template: `
    <div style="min-height:100vh;background:#06060f;color:#F0F0F8;font-family:inherit;padding-bottom:40px">

      <!-- ══ Sticky Header ══ -->
      <div style="position:sticky;top:0;z-index:50;background:#06060f;padding:14px 20px 12px;box-shadow:0 2px 8px rgba(0,0,0,0.4)">
        <div style="display:flex;align-items:center;gap:10px">
          <button @click="$emit('back')"
            style="background:none;border:none;color:#6C5CE7;font-size:13px;font-weight:500;cursor:pointer;display:flex;align-items:center;gap:4px;font-family:inherit;padding:0;flex-shrink:0">
            <svg width="7" height="12" viewBox="0 0 7 12" fill="none"><path d="M6 1L1 6L6 11" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Назад
          </button>
          <div style="flex:1"></div>
        </div>
      </div>

      <!-- ══ Loading ══ -->
      <div v-if="loading" style="text-align:center;padding:80px 20px">
        <div style="font-size:36px;margin-bottom:12px">⏳</div>
        <div style="font-size:13px;color:#60608A">Загружаем...</div>
      </div>

      <!-- ══ Not found ══ -->
      <div v-else-if="!g" style="text-align:center;padding:80px 20px">
        <div style="font-size:48px;margin-bottom:12px">😕</div>
        <div style="font-size:16px;font-weight:600;color:#9090C0">Не найден</div>
      </div>

      <!-- ══ Content ══ -->
      <template v-else>
      <div style="padding:0 16px 0">

        <!-- ── Hero ── -->
        <div style="background:#13131C;border:1px solid #1E1E2C;border-radius:20px;padding:20px 20px 18px;margin-bottom:12px;position:relative;overflow:hidden">
          <div style="position:absolute;top:-30px;right:-30px;width:120px;height:120px;background:#6C5CE718;border-radius:60px;filter:blur(40px);pointer-events:none"></div>

          <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px">
            <div style="flex:1;margin-right:12px">
              <div style="font-size:17px;font-weight:700;letter-spacing:-0.02em;line-height:1.2;margin-bottom:4px">{{ g.public_id || g.id }}</div>
              <div style="font-size:12px;color:#60608A;line-height:1.5">{{ g.title }}</div>
            </div>
            <div v-if="g.status==='active'" style="display:flex;align-items:center;gap:5px;background:#0D2A1E;border:1px solid #1A4A30;border-radius:99px;padding:4px 10px;flex-shrink:0">
              <div style="width:6px;height:6px;border-radius:3px;background:#34D399;box-shadow:0 0 8px #34D399"></div>
              <span style="font-size:11px;font-weight:700;color:#34D399;letter-spacing:0.05em">LIVE</span>
            </div>
            <div v-else-if="g.status==='draft'" style="background:#1E1E2A;border:1px solid #2A2A3C;border-radius:99px;padding:4px 10px;flex-shrink:0">
              <span style="font-size:11px;font-weight:600;color:#8080C0">ЧЕРНОВИК</span>
            </div>
            <div v-else style="background:#1E1E2A;border:1px solid #2A2A3C;border-radius:99px;padding:4px 10px;flex-shrink:0">
              <span style="font-size:11px;font-weight:600;color:#6060A0">ЗАВЕРШЁН</span>
            </div>
          </div>

          <!-- Прогресс-бар -->
          <div v-if="progressPercent !== null" style="margin-bottom:10px">
            <div style="display:flex;justify-content:space-between;margin-bottom:5px">
              <span style="font-size:11px;color:#50507A">Прогресс</span>
              <span style="font-size:11px;color:#7070A0;font-weight:600">{{ progressPercent }}%</span>
            </div>
            <div style="background:#1A1A28;border-radius:99px;height:4px;overflow:hidden">
              <div :style="{ width: progressPercent + '%', height:'100%', background:'linear-gradient(to right,#6C5CE7,#A78BFA)', borderRadius:'99px' }"></div>
            </div>
          </div>

          <div v-if="endCountdown" style="font-size:11px;color:#FB923C;font-weight:500;display:flex;align-items:center;gap:4px">
            <svg width="11" height="11" viewBox="0 0 11 11" fill="none"><circle cx="5.5" cy="5.5" r="4.5" stroke="currentColor" stroke-width="1.2"/><path d="M5.5 3v2.5l1.5 1" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
            Заканчивается через {{ endCountdown }}
          </div>
          <div v-else-if="startCountdown" style="font-size:11px;color:#6C5CE7;font-weight:500;display:flex;align-items:center;gap:4px">
            <svg width="11" height="11" viewBox="0 0 11 11" fill="none"><circle cx="5.5" cy="5.5" r="4.5" stroke="currentColor" stroke-width="1.2"/><path d="M5.5 3v2.5l1.5 1" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
            Старт через {{ startCountdown }}
          </div>
        </div>

        <!-- ── Prizes ── -->
        <div v-if="g.prizes && g.prizes.length" style="background:#13131C;border:1px solid #1E1E2C;border-radius:16px;padding:14px 16px;margin-bottom:12px">
          <div style="font-size:11px;font-weight:600;color:#50507A;letter-spacing:0.06em;text-transform:uppercase;margin-bottom:12px">Призы</div>
          <div style="display:flex;flex-direction:column;gap:8px">
            <div v-for="(p, i) in g.prizes" :key="p.place"
              :style="{
                display:'flex', alignItems:'center', gap:'10px',
                background: (prizeColors[i] || prizeColors[3]).bg,
                border: '1px solid ' + (prizeColors[i] || prizeColors[3]).border,
                borderRadius:'12px', padding:'10px 12px',
              }">
              <span style="font-size:20px;flex-shrink:0">{{ (prizeColors[i] || prizeColors[3]).icon }}</span>
              <div style="flex:1">
                <div :style="{ fontSize:'13px', fontWeight:'600', color:(prizeColors[i]||prizeColors[3]).color }">{{ p.title }}</div>
                <div style="font-size:11px;color:#50507A;margin-top:1px">{{ p.place }} место</div>
              </div>
            </div>
          </div>
        </div>
        <div v-else-if="g.prize" style="background:#13131C;border:1px solid #1E1E2C;border-radius:16px;padding:14px 16px;margin-bottom:12px">
          <div style="font-size:11px;font-weight:600;color:#50507A;letter-spacing:0.06em;text-transform:uppercase;margin-bottom:12px">Приз</div>
          <div style="display:flex;align-items:center;gap:10px;background:#2A2218;border:1px solid #3D3020;border-radius:12px;padding:10px 12px">
            <span style="font-size:20px">🥇</span>
            <div style="font-size:14px;font-weight:600;color:#F5C842">{{ g.prize }}</div>
          </div>
        </div>

        <!-- ── Условие в нике (показываем перед каналами) ── -->
        <div v-if="g.nickname_condition && g.status === 'active'"
          style="background:#13131C;border:1px solid #1E1E2C;border-radius:16px;padding:14px 16px;margin-bottom:12px">
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
            <div style="width:36px;height:36px;border-radius:10px;background:#6C5CE715;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:18px">🏷</div>
            <div style="flex:1">
              <div style="font-size:13px;font-weight:700;color:#6C5CE7">Бонус ×{{ g.nickname_bonus_multiplier || 10 }} билетов</div>
              <div style="font-size:11px;color:#50507A;margin-top:2px;line-height:1.4">
                Добавь <b style="color:#A78BFA">{{ g.nickname_condition }}</b> в ник — получи ×{{ g.nickname_bonus_multiplier || 10 }} шанс
              </div>
            </div>
          </div>
          <div v-if="myParticipant && myParticipant.nickname_bonus"
            style="background:#0D2A1E;border:1px solid #1A4A30;border-radius:10px;padding:10px;text-align:center">
            <span style="font-size:13px;font-weight:700;color:#34D399">✅ Бонус ×{{ g.nickname_bonus_multiplier || 10 }} получен!</span>
          </div>
          <button v-else-if="!isOwner"
            @click="checkNickname" :disabled="nickChecking"
            style="width:100%;padding:12px;background:#6C5CE722;border:1px solid #6C5CE744;border-radius:12px;color:#9580FF;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit">
            {{ nickChecking ? '⏳ Проверяем...' : '🔍 Проверить мой ник' }}
          </button>
          <div v-if="nickResult === 'fail'"
            style="margin-top:8px;font-size:11px;color:#FB923C;text-align:center">
            Добавьте <b>{{ g.nickname_condition }}</b> в имя или фамилию в Telegram и попробуйте снова
          </div>
        </div>

        <!-- ── Каналы розыгрыша (единый блок) ── -->
        <div v-if="g.channels && g.channels.length && g.status !== 'draft'"
          style="background:#13131C;border:1px solid #1E1E2C;border-radius:16px;padding:14px 16px;margin-bottom:12px">
          <div style="font-size:11px;font-weight:600;color:#50507A;letter-spacing:0.06em;text-transform:uppercase;margin-bottom:10px">Каналы розыгрыша</div>
          <div style="display:flex;flex-direction:column;gap:7px">
            <div v-for="ch in g.channels" :key="ch.id"
              style="display:flex;align-items:center;gap:10px;background:#18182A;border:1px solid #242438;border-radius:12px;padding:9px 12px"
              :style="{ cursor: (isOwner && g.status === 'active') ? 'default' : 'pointer' }"
              @click="(!isOwner || g.status !== 'active') && openChannel(ch)">
              <div style="width:32px;height:32px;border-radius:9px;background:#6C5CE722;border:1px solid #6C5CE733;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;cursor:pointer" @click.stop="openChannel(ch)">📡</div>
              <div style="flex:1;min-width:0;cursor:pointer" @click.stop="openChannel(ch)">
                <div style="font-size:13px;font-weight:500;color:#D0D0E8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ ch.title }}</div>
                <div style="font-size:11px;color:#50507A">{{ ch.member_count ? ch.member_count.toLocaleString() + ' подписчиков' : (ch.username ? '@' + ch.username : '') }}</div>
              </div>
              <button v-if="isOwner && g.status === 'active'" @click.stop="detachOtherChannel(ch.id)"
                style="background:none;border:none;cursor:pointer;color:#F87171;padding:4px;display:flex;align-items:center;flex-shrink:0"
                title="Убрать из розыгрыша">
                <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 2l10 10M12 2L2 12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
              </button>
              <svg v-else width="7" height="12" viewBox="0 0 7 12" fill="none"><path d="M1 1l5 5-5 5" stroke="#44445A" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </div>
          </div>
        </div>

        <!-- ── Кнопка Участвовать (не-владелец, активный, не участвует) ── -->
        <button v-if="g.status === 'active' && !isOwner && !joined" @click="startJoin"
          style="width:100%;padding:16px;background:linear-gradient(135deg,#6C5CE7,#9580FF);border:none;border-radius:16px;color:#fff;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 4px 24px #6C5CE744;letter-spacing:-0.01em;margin-bottom:12px">
          <span style="font-size:18px">🎲</span>
          Участвовать
        </button>

        <!-- ── Я участвую ── -->
        <div v-if="g.status === 'active' && joined && !isOwner"
          style="background:#0D2A1E;border:1px solid #1A4A30;border-radius:16px;padding:16px;margin-bottom:12px;text-align:center">
          <div style="font-size:22px;margin-bottom:6px">✅</div>
          <div style="font-size:14px;font-weight:700;color:#34D399">Вы участвуете!</div>
          <div style="font-size:12px;color:#6C5CE7;margin-top:4px;font-weight:500">
            🎟 У вас <b>{{ myTickets }}</b> {{ myTickets === 1 ? 'билет' : myTickets < 5 ? 'билета' : 'билетов' }}
          </div>
          <!-- Прогресс билетов -->
          <div style="margin-top:8px">
            <div style="background:#0A1F14;border-radius:99px;height:4px;overflow:hidden;margin:0 20px">
              <div :style="{ width: ticketProgress + '%', height:'100%', background:'linear-gradient(to right,#34D399,#6C5CE7)', borderRadius:'99px', transition:'width .5s ease' }"></div>
            </div>
            <div style="font-size:10px;color:#30704A;margin-top:5px">макс. {{ maxTickets }} билетов</div>
          </div>
        </div>

        <!-- ── Задания от организатора ── -->
        <div v-if="g.status === 'active' && joined && !isOwner && adminTasks.length"
          style="margin-bottom:12px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
            <div style="font-size:13px;font-weight:600;color:#9090B8">Задания от организатора</div>
            <div style="font-size:11px;color:#50507A">{{ adminTasks.length }} {{ adminTasks.length === 1 ? 'задание' : adminTasks.length < 5 ? 'задания' : 'заданий' }}</div>
          </div>
          <div style="display:flex;flex-direction:column;gap:8px">
            <div v-for="(task, i) in adminTasks" :key="task.id || i"
              :style="{
                background:'#13131C',
                border: '1.5px solid ' + (mySubmissions[task.id || i] === 'approved' ? '#1A4A30' : mySubmissions[task.id || i] === 'pending' ? '#3A2A1A' : '#1E1E2C'),
                borderRadius:'14px', padding:'13px 14px',
                transition:'border-color .3s',
              }">
              <div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:10px">
                <div :style="{
                  width:'22px',height:'22px',borderRadius:'7px',flexShrink:0,marginTop:'1px',
                  background: mySubmissions[task.id || i] === 'approved' ? '#0D2A1E' : mySubmissions[task.id || i] === 'pending' ? '#2A2010' : '#6C5CE722',
                  border: '1.5px solid ' + (mySubmissions[task.id || i] === 'approved' ? '#1A4A30' : mySubmissions[task.id || i] === 'pending' ? '#4A3A18' : '#6C5CE744'),
                  display:'flex',alignItems:'center',justifyContent:'center',
                  fontSize:'10px',fontWeight:'700',
                  color: mySubmissions[task.id || i] === 'approved' ? '#34D399' : mySubmissions[task.id || i] === 'pending' ? '#F5A623' : '#6C5CE7',
                }">{{ mySubmissions[task.id || i] === 'approved' ? '✓' : (i + 1) }}</div>
                <div style="flex:1;min-width:0">
                  <div :style="{
                    fontSize:'13px', lineHeight:'1.5', fontWeight:'500',
                    color: mySubmissions[task.id || i] === 'approved' ? '#34D399' : mySubmissions[task.id || i] === 'pending' ? '#D0B060' : '#D0D0E8',
                    wordBreak:'break-word', overflowWrap:'anywhere',
                  }">{{ task.text }}</div>
                </div>
                <div :style="{
                  fontSize:'11px',fontWeight:'700',flexShrink:0,
                  color: mySubmissions[task.id || i] === 'approved' ? '#34D399' : mySubmissions[task.id || i] === 'pending' ? '#F5A623' : '#6C5CE7',
                  background: mySubmissions[task.id || i] === 'approved' ? '#0D2A1E' : mySubmissions[task.id || i] === 'pending' ? '#2A2010' : '#6C5CE718',
                  border: '1px solid ' + (mySubmissions[task.id || i] === 'approved' ? '#1A4A30' : mySubmissions[task.id || i] === 'pending' ? '#3A2A10' : '#6C5CE733'),
                  borderRadius:'6px',padding:'2px 7px',
                }">+{{ task.price }} 🎟</div>
              </div>

              <!-- Статус -->
              <div v-if="mySubmissions[task.id || i] === 'approved'"
                style="font-size:11px;color:#34D399;font-weight:600;text-align:center;background:#0D2A1E;border-radius:8px;padding:7px">
                ✅ Выполнено — билеты начислены
              </div>
              <div v-else-if="mySubmissions[task.id || i] === 'pending'"
                style="font-size:11px;color:#C09060;background:#1A130E;border:1px solid #3A2A1A;border-radius:8px;padding:8px 10px;display:flex;gap:7px;align-items:center">
                <svg width="13" height="13" viewBox="0 0 13 13" fill="none"><circle cx="6.5" cy="6.5" r="5.5" stroke="#F5A623" stroke-width="1.2"/><path d="M6.5 3.5v3l1.5 1.5" stroke="#F5A623" stroke-width="1.2" stroke-linecap="round"/></svg>
                На проверке у организатора
              </div>
              <button v-else @click="submitTask(task.id || i)"
                style="width:100%;padding:10px;background:#6C5CE722;border:1px solid #6C5CE744;border-radius:10px;color:#9580FF;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit">
                📎 Выполнить задание
              </button>
            </div>
          </div>

          <!-- Warning -->
          <div style="margin-top:8px;background:#1A130E;border:1.5px solid #3A2A1A;border-radius:12px;padding:10px 13px;display:flex;gap:8px;align-items:flex-start">
            <svg width="15" height="15" viewBox="0 0 16 16" fill="none" style="flex-shrink:0;margin-top:1px">
              <path d="M8 1.5L14.5 13H1.5L8 1.5z" stroke="#F5A623" stroke-width="1.4" stroke-linejoin="round"/>
              <path d="M8 6v3.5M8 11v.5" stroke="#F5A623" stroke-width="1.4" stroke-linecap="round"/>
            </svg>
            <div style="font-size:11px;color:#C09060;line-height:1.5">
              <span style="font-weight:700;color:#F5A623">Ручная проверка.</span>
              Все задания проверяются вручную. Подтверждение может занять некоторое время.
            </div>
          </div>
        </div>

        <!-- ── Реферальная плашка ── -->
        <div v-if="g.status === 'active' && !isOwner"
          style="background:#13131C;border:1px solid #1E1E2C;border-radius:16px;padding:14px 16px;margin-bottom:12px">
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
            <div style="width:36px;height:36px;border-radius:10px;background:#6C5CE715;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:18px">👥</div>
            <div style="flex:1">
              <div style="font-size:13px;font-weight:700;color:#D0D0E8">Пригласи друзей</div>
              <div style="font-size:11px;color:#50507A;margin-top:2px">
                +{{ g.referral_tickets || 1 }} билет за каждого приглашённого
              </div>
            </div>
            <div style="background:#6C5CE722;color:#9580FF;font-size:11px;font-weight:700;border-radius:99px;padding:3px 9px;flex-shrink:0">
              {{ myReferrals.length }}
            </div>
          </div>
          <button @click="copyRefLink"
            style="width:100%;padding:12px;background:#6C5CE722;border:1px solid #6C5CE744;border-radius:12px;color:#9580FF;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;margin-bottom:10px">
            📋 Скопировать реферальную ссылку
          </button>

          <!-- Список приглашённых (если есть) -->
          <div v-if="myReferrals.length">
            <div style="font-size:11px;color:#50507A;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:7px">
              Твои приглашённые
            </div>
            <div v-for="r in myReferrals" :key="r.user_id"
              style="display:flex;align-items:center;gap:10px;background:#18182A;border:1px solid #242438;border-radius:12px;padding:9px 12px;margin-bottom:6px">
              <div style="width:32px;height:32px;border-radius:9px;background:#6C5CE722;border:1px solid #6C5CE733;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#9580FF;flex-shrink:0">
                {{ r.user_name[0].toUpperCase() }}
              </div>
              <div style="flex:1;min-width:0">
                <div style="font-size:13px;font-weight:500;color:#D0D0E8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ r.user_name }}</div>
                <div style="font-size:11px;color:#34D399;margin-top:1px">✓ участвует</div>
              </div>
              <div style="text-align:right;flex-shrink:0">
                <div style="font-size:13px;font-weight:700;color:#F0F0F8">+{{ g.referral_tickets || 1 }}</div>
                <div style="font-size:10px;color:#44445A">билет</div>
              </div>
            </div>
          </div>

          <!-- Пустое состояние -->
          <div v-else style="background:#18182A;border:1px dashed #2A2A3C;border-radius:12px;padding:14px;text-align:center">
            <div style="font-size:11px;color:#50507A;line-height:1.4">
              Поделись ссылкой — друзья присоединятся,<br>и ты увидишь их здесь
            </div>
          </div>
        </div>

        <!-- ════ УПРАВЛЕНИЕ ЧЕРНОВИКОМ (владелец + черновик) ════ -->
        <template v-if="isOwner && g.status === 'draft'">

          <!-- Фото для поста -->
          <div style="background:#13131C;border:1px solid #1E1E2C;border-radius:16px;padding:14px 16px;margin-bottom:12px">
            <div style="font-size:11px;font-weight:600;color:#50507A;letter-spacing:0.06em;text-transform:uppercase;margin-bottom:12px">📷 Фото для поста</div>
            <div v-if="g.photo" style="background:#000;border-radius:12px;overflow:hidden;margin-bottom:10px;aspect-ratio:16/9;display:flex;align-items:center;justify-content:center">
              <div style="font-size:32px">🖼</div>
            </div>
            <div v-else style="background:#000;border-radius:12px;aspect-ratio:16/9;display:flex;align-items:center;justify-content:center;margin-bottom:10px;border:1.5px dashed #2A2A3C">
              <div style="text-align:center">
                <div style="font-size:28px;margin-bottom:6px">📷</div>
                <div style="font-size:12px;color:#50507A">Фото не загружено</div>
              </div>
            </div>
            <label style="display:flex;align-items:center;justify-content:center;gap:8px;padding:12px;background:#6C5CE722;border:1px solid #6C5CE744;border-radius:12px;color:#9580FF;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit">
              {{ g.photo ? '🔄 Заменить фото' : '📷 Загрузить фото' }}
              <input type="file" accept="image/*" style="display:none" @change="uploadPhoto">
            </label>
          </div>

          <!-- Дата завершения -->
          <div style="background:#13131C;border:1px solid #1E1E2C;border-radius:16px;padding:14px 16px;margin-bottom:12px">
            <div style="font-size:11px;font-weight:600;color:#50507A;letter-spacing:0.06em;text-transform:uppercase;margin-bottom:12px">⏰ Дата завершения</div>
            <div style="display:flex;align-items:center;justify-content:space-between;background:#18182A;border:1px solid #242438;border-radius:12px;padding:12px 14px;cursor:pointer"
              @click="openDatePicker('end')">
              <span :style="{ fontSize:'13px', color: endDate ? '#D0D0E8' : '#50507A' }">
                {{ endDate ? endDateDisplay : 'Без таймера — завершить вручную' }}
              </span>
              <span style="font-size:16px">📅</span>
            </div>
            <div style="font-size:11px;color:#44445A;margin-top:7px">Розыгрыш завершится автоматически</div>
          </div>

          <!-- Мои каналы -->
          <div style="background:#13131C;border:1px solid #1E1E2C;border-radius:16px;padding:14px 16px;margin-bottom:12px">
            <div style="font-size:11px;font-weight:600;color:#50507A;letter-spacing:0.06em;text-transform:uppercase;margin-bottom:10px">📺 Мои каналы</div>
            <div v-if="myChannels.length" style="display:flex;flex-direction:column;gap:7px">
              <div v-for="ch in myChannels" :key="ch.id"
                style="display:flex;align-items:center;gap:10px;border-radius:12px;padding:9px 12px;cursor:pointer;transition:background .15s"
                :style="{
                  background: attachedChannelIds.includes(ch.id) ? '#6C5CE720' : '#18182A',
                  border: '1px solid ' + (attachedChannelIds.includes(ch.id) ? '#6C5CE744' : '#242438'),
                }"
                @click.prevent.stop="toggleDraftChannel(ch.id)">
                <div style="width:32px;height:32px;border-radius:9px;background:#6C5CE722;border:1px solid #6C5CE733;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0">📺</div>
                <div style="flex:1;min-width:0">
                  <div style="font-size:13px;font-weight:500;color:#D0D0E8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ ch.title }}</div>
                  <div style="font-size:11px;color:#50507A">{{ ch.member_count ? ch.member_count.toLocaleString() + ' подп.' : '' }}</div>
                </div>
                <div :style="{
                  width:'20px', height:'20px', borderRadius:'6px', flexShrink:0,
                  display:'flex', alignItems:'center', justifyContent:'center',
                  background: attachedChannelIds.includes(ch.id) ? '#6C5CE7' : 'transparent',
                  border: '1.5px solid ' + (attachedChannelIds.includes(ch.id) ? '#6C5CE7' : '#3A3A5A'),
                  fontSize:'12px', color:'#fff',
                }">{{ attachedChannelIds.includes(ch.id) ? '✓' : '' }}</div>
              </div>
            </div>
            <div v-else>
              <div style="text-align:center;padding:16px;color:#50507A;font-size:13px">Нет подключённых каналов</div>
              <button @click="addChannelViBot"
                style="width:100%;padding:12px;background:#6C5CE722;border:1px dashed #6C5CE744;border-radius:12px;color:#9580FF;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;margin-top:4px">
                + Подключить канал
              </button>
            </div>
          </div>

          <!-- Каналы других админов -->
          <div v-if="otherChannels.length" style="background:#13131C;border:1px solid #1E1E2C;border-radius:16px;padding:14px 16px;margin-bottom:12px">
            <div style="font-size:11px;font-weight:600;color:#50507A;letter-spacing:0.06em;text-transform:uppercase;margin-bottom:10px">📺 Каналы других админов</div>
            <div style="display:flex;flex-direction:column;gap:7px">
              <div v-for="ch in otherChannels" :key="ch.id"
                style="display:flex;align-items:center;gap:10px;background:#18182A;border:1px solid #242438;border-radius:12px;padding:9px 12px">
                <div style="width:32px;height:32px;border-radius:9px;background:#A855F722;border:1px solid #A855F733;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0">📺</div>
                <div style="flex:1;min-width:0">
                  <div style="font-size:13px;font-weight:500;color:#D0D0E8">{{ ch.title }}</div>
                  <div style="font-size:11px;color:#50507A">{{ ch.username ? '@' + ch.username : '' }}</div>
                </div>
                <button @click="detachOtherChannel(ch.id)"
                  style="background:none;border:none;cursor:pointer;color:#50507A;padding:4px;display:flex;align-items:center">
                  <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 2l10 10M12 2L2 12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                </button>
              </div>
            </div>
          </div>

          <!-- Пригласить админов -->
          <div style="background:#6C5CE710;border:1px solid #6C5CE730;border-radius:16px;padding:14px 16px;margin-bottom:12px">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
              <div style="font-size:24px">👥</div>
              <div>
                <div style="font-size:13px;font-weight:700;color:#D0D0E8">Пригласить админов</div>
                <div style="font-size:11px;color:#50507A;margin-top:2px">Другие админы добавят свои каналы</div>
              </div>
            </div>
            <button @click="copyAdminLink"
              style="width:100%;padding:12px;background:#6C5CE722;border:1px solid #6C5CE744;border-radius:12px;color:#9580FF;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit">
              📋 Скопировать ссылку для админов
            </button>
          </div>

          <!-- Кнопка запуска -->
          <button @click="launch"
            style="width:100%;padding:16px;background:linear-gradient(135deg,#6C5CE7,#9580FF);border:none;border-radius:16px;color:#fff;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 4px 24px #6C5CE744;letter-spacing:-0.01em;margin-bottom:12px">
            <span style="font-size:18px">🚀</span>
            Запустить розыгрыш
          </button>

          <!-- Кнопка удаления черновика -->
          <button @click="deleteGiveaway"
            style="width:100%;padding:14px;background:#1A0A0A;border:1.5px solid #3A1A1A;border-radius:14px;color:#F87171;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:12px">
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 3.5h10M5.5 3.5V2.5a1 1 0 011-1h1a1 1 0 011 1v1M3.5 3.5l.5 8a1.5 1.5 0 001.5 1.5h3a1.5 1.5 0 001.5-1.5l.5-8" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
            Удалить черновик
          </button>

        </template>

        <!-- ── Кнопка Определить победителей (владелец, активный) ── -->
        <button v-if="isOwner && g.status === 'active'" @click="draw"
          style="width:100%;padding:16px;background:linear-gradient(135deg,#C13584,#E1306C,#F77737);border:none;border-radius:16px;color:#fff;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 4px 24px rgba(193,53,132,0.35);letter-spacing:-0.01em;margin-bottom:12px">
          <span style="font-size:18px">🎰</span>
          Определить победителей
        </button>

        <!-- ── Победители ── -->
        <template v-if="g.status === 'finished' && g.winners && g.winners.length">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
            <div style="font-size:13px;font-weight:600;color:#6060A0">🏆 Победители</div>
            <div style="background:#6C5CE722;color:#6C5CE7;font-size:11px;font-weight:700;border-radius:99px;padding:3px 9px">{{ g.winners.length }}</div>
          </div>
          <div style="background:#13131C;border:1px solid #1E1E2C;border-radius:16px;overflow:hidden;margin-bottom:12px">
            <div v-for="(w, i) in g.winners" :key="w.user_id"
              :style="{ display:'flex', alignItems:'center', gap:'10px', padding:'11px 14px', borderBottom: i < g.winners.length-1 ? '1px solid #1A1A28' : 'none' }">
              <div style="width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,#34D399,#6C5CE7);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#fff;flex-shrink:0">
                {{ w.user_name[0].toUpperCase() }}
              </div>
              <div style="flex:1">
                <div style="font-size:13px;font-weight:600;color:#D0D0E8">{{ w.user_name }}</div>
                <div style="font-size:11px;color:#34D399;margin-top:1px">🏆 {{ i + 1 }} место</div>
              </div>
            </div>
          </div>
        </template>

        <!-- ── Проверить честность ── -->
        <template v-if="g.status === 'finished'">
          <button @click="verifyIntegrity" :disabled="verifying"
            style="width:100%;padding:14px;background:#0A1A12;border:1.5px solid #1A3A2A;border-radius:14px;color:#34D399;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:12px">
            <template v-if="verifying">⏳ Проверка...</template>
            <template v-else-if="verifyResult && verifyResult.verified">✅ Розыгрыш верифицирован</template>
            <template v-else-if="verifyResult && !verifyResult.verified">❌ Ошибка верификации</template>
            <template v-else>🔍 Проверить честность</template>
          </button>
          <div v-if="verifyResult && verifyResult.verified && verifyResult.audit"
            style="background:#0A1A12;border:1px solid #1A3A2A;border-radius:14px;padding:14px;margin-bottom:12px">
            <div style="font-size:11px;color:#34D399;font-weight:600;margin-bottom:8px">✅ Аудит розыгрыша</div>
            <div style="font-size:11px;color:#6060A0;line-height:1.8">
              <div>Алгоритм: <span style="color:#D0D0E8">{{ verifyResult.audit.algorithm }}</span></div>
              <div>Участников: <span style="color:#D0D0E8">{{ verifyResult.audit.total_participants }}</span></div>
              <div>Билетов: <span style="color:#D0D0E8">{{ verifyResult.audit.total_tickets }}</span></div>
              <div style="margin-top:6px">Токен: <code style="font-size:10px;color:#6C5CE7;word-break:break-all">{{ verifyResult.audit.result_token }}</code></div>
              <div>Подпись: <code style="font-size:10px;color:#6C5CE7;word-break:break-all">{{ verifyResult.audit.signature.slice(0, 32) }}...</code></div>
            </div>
            <div @click="showFairness = !showFairness"
              style="margin-top:10px;font-size:11px;color:#6C5CE7;cursor:pointer;text-decoration:underline">
              {{ showFairness ? 'Скрыть' : '🔍 Как это работает?' }}
            </div>
            <div v-if="showFairness" style="margin-top:10px;font-size:11px;color:#8080B0;line-height:1.7;border-top:1px solid #1A3A2A;padding-top:10px">
              <b style="color:#D0D0E8">Алгоритм честного розыгрыша</b><br><br>
              🔐 <b style="color:#34D399">Криптографический рандом</b> — используется <code>random_int()</code> (CSPRNG), который невозможно предсказать или подкрутить.<br><br>
              📋 <b style="color:#34D399">Снапшот участников</b> — перед розыгрышем создаётся снимок всех участников с количеством билетов. SHA-256 хеш снапшота фиксируется навсегда.<br><br>
              ✍️ <b style="color:#34D399">HMAC-подпись</b> — результат подписывается секретным ключом сервера. Подделать подпись без ключа невозможно.<br><br>
              🔒 <b style="color:#34D399">Неизменяемость</b> — аудит-запись создаётся один раз и не может быть перезаписана. Unique constraint в базе данных не позволяет подменить результат.<br><br>
              📢 <b style="color:#34D399">Публичный канал</b> — хеши и токен публикуются в открытый Telegram-канал сразу после розыгрыша. Даже при доступе к серверу подменить опубликованный токен невозможно.<br><br>
              <span style="color:#6060A0">Каждый участник может нажать "Проверить честность" и убедиться что результат не был изменён.</span>
            </div>
          </div>
        </template>

        <!-- ── Кнопка Удалить (владелец, finished) ── -->
        <button v-if="isOwner && g.status === 'finished'" @click="deleteGiveaway"
          style="width:100%;padding:14px;background:#1A0A0A;border:1.5px solid #3A1A1A;border-radius:14px;color:#F87171;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:8px;margin-top:12px;margin-bottom:12px">
          <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 3.5h10M5.5 3.5V2.5a1 1 0 011-1h1a1 1 0 011 1v1M3.5 3.5l.5 8a1.5 1.5 0 001.5 1.5h3a1.5 1.5 0 001.5-1.5l.5-8" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
          Удалить розыгрыш
        </button>

      </div>
      </template>

      <!-- ── Попап: Подтвердите номер ── -->
      <div v-if="showPhonePrompt"
        style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.85);z-index:9999;display:flex;align-items:center;justify-content:center"
        @click.self="showPhonePrompt=false">
        <div style="background:#13131C;border:1px solid #1E1E2C;border-radius:20px;padding:28px 24px;max-width:320px;width:90%;text-align:center">
          <div style="font-size:40px;margin-bottom:12px">📱</div>
          <div style="font-size:16px;font-weight:700;color:#F0F0F8;margin-bottom:8px">Подтвердите номер</div>
          <div style="font-size:13px;color:#6060A0;margin-bottom:20px;line-height:1.5">
            Для участия нужен российский номер (+7).<br>Нажмите — бот попросит поделиться номером.
          </div>
          <button @click="openBotForPhone"
            style="width:100%;padding:13px;background:linear-gradient(135deg,#6C5CE7,#9580FF);border:none;border-radius:14px;color:#fff;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;margin-bottom:10px">
            📲 Подтвердить в боте
          </button>
          <button @click="showPhonePrompt=false"
            style="background:none;border:none;color:#6060A0;font-size:13px;cursor:pointer;font-family:inherit">
            Отмена
          </button>
        </div>
      </div>

    </div>
  `,
}
