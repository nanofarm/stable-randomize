
import { store, tg, toastState, initUser, api, toast, haptic, createParticles, fullName, loadCsrfToken } from './store.js'
import GiveawayCard from './components/GiveawayCard.js'
import DatePicker from './components/DatePicker.js'
import WinnerReveal from './components/WinnerReveal.js'
import HomePage from './pages/HomePage.js'
import ChannelsPage from './pages/ChannelsPage.js'
import MyPage from './pages/MyPage.js'
import CreatePage from './pages/CreatePage.js'
import DetailPage from './pages/DetailPage.js'
import AnalyticsPage from './pages/AnalyticsPage.js'

function normalizePublicId(raw) {
  if (!raw) return null
  const s = String(raw)
  const m = s.match(/[A-Za-z0-9]{8}/)
  return m ? m[0] : null
}

const app = Vue.createApp({
  data() {
    return {
      view: 'home',
      history: [],
      detailId: null,
      analyticsId: null,

      showBroadcast: false,
      broadcastGid: null,
      broadcastMsg: '',

    }
  },

  computed: {
    isSubPage() {
      return ['create', 'detail', 'analytics', 'channels'].includes(this.view)
    },
    user() { return store.user },
    userInitial() {
      if (!store.user) return '?'
      return store.user.first_name[0].toUpperCase()
    },
    userName() { return fullName() },
    toastVisible() { return toastState.visible },
    toastMsg() { return toastState.msg },
    toastType() { return toastState.type },
  },

  methods: {
    reloadTab(tab) {
      this.$nextTick(() => {
        const refs = { home: 'homePage', my: 'myPage' }
        const ref = refs[tab]
        if (ref && this.$refs[ref] && typeof this.$refs[ref].load === 'function') {
          this.$refs[ref].load()
        }
      })
    },

    go(tab) {
      this.view = tab
      this.reloadTab(tab)
      haptic('sel')
    },
    goBack() {
      const tab = this.history.pop() || 'home'
      this.view = tab
      this.reloadTab(tab)
    },
    navigate(tab) {
      this.history.push(this.view)
      this.view = tab
    },

    openGiveaway(id) {
      this.history.push(this.view)
      this.detailId = id
      this.view = 'detail'
    },
    openAnalytics(id) {
      this.history.push(this.view)
      this.analyticsId = id
      this.view = 'analytics'
    },

    onCreated(data) {
      this.detailId = data.id
      this.view = 'detail'
      this.history = ['home']
    },

    openDatePicker(data) {
      this.$refs.datePicker.open(data.target, data.value)
    },
    onDateSelected(data) {
      if (this.$refs.detail) {
        this.$refs.detail.onDateSelected(data)
      }
    },

    async onDraw(gid) {
      const reveal = this.$refs.reveal
      reveal.start()
      haptic('heavy')

      const r = await api('/giveaway/draw', {
        method: 'POST',
        body: JSON.stringify({ giveaway_id: gid, creator_id: store.user.id }),
      })

      await new Promise(resolve => setTimeout(resolve, 2000))

      if (r.ok && r.winners && r.winners.length) {
        const names = r.winners.map(w => w.user_name).join(', ')
        reveal.reveal(names)
        haptic('ok')
      } else {
        reveal.show = false
        toast(r.error || 'Не удалось определить победителя', 'err')
      }
    },
    onRevealClose() {
      if (this.$refs.detail) this.$refs.detail.load()
    },

    openBroadcast(gid) {
      this.broadcastGid = gid
      this.broadcastMsg = ''
      this.showBroadcast = true
      haptic('sel')
    },
    closeBroadcast() {
      this.showBroadcast = false
    },
    async sendBroadcast() {
      const msg = this.broadcastMsg.trim()
      if (!msg) { toast('Напишите сообщение', 'err'); return }
      const r = await api('/giveaway/broadcast', {
        method: 'POST',
        body: JSON.stringify({ giveaway_id: this.broadcastGid, creator_id: store.user.id, message: msg }),
      })
      if (r.ok) {
        haptic('ok')
        toast('Рассылка запущена: ' + r.total + ' участников', 'ok')
        this.closeBroadcast()
      } else {
        toast(r.error || 'Не удалось отправить рассылку', 'err')
      }
    },
  },

  mounted() {
    const query = new URLSearchParams(location.search)
    const startParam = (
      (tg && tg.initDataUnsafe ? tg.initDataUnsafe.start_param || '' : '') ||
      query.get('tgWebAppStartParam') ||
      query.get('startapp') ||
      ''
    )
    const hash = location.hash.replace('#', '').split('?')[0].split('&')[0]

    if (startParam.indexOf('g_') === 0) {
      let sp = startParam.slice(2)
      const chIdx = sp.indexOf('_ch_')
      if (chIdx !== -1) {
        store.sourceChannelId = parseInt(sp.slice(chIdx + 4))
        sp = sp.slice(0, chIdx)
      }
      const refIdx = sp.indexOf('_ref_')
      if (refIdx !== -1) {
        store.referredBy = parseInt(sp.slice(refIdx + 5))
        sp = sp.slice(0, refIdx)
      }
      const gid = normalizePublicId(sp)
      if (gid) this.openGiveaway(gid)
    } else if (hash === 'create') {
      this.navigate('create')
    } else if (hash === 'my') {
      this.go('my')
    } else if (hash === 'channels') {
      this.navigate('channels')
    } else if (hash.indexOf('g:') === 0) {
      let gHash = hash.slice(2)
      const hashRefIdx = gHash.indexOf('_ref_')
      if (hashRefIdx !== -1) {
        store.referredBy = parseInt(gHash.slice(hashRefIdx + 5))
        gHash = gHash.slice(0, hashRefIdx)
      }
      const hashChIdx = gHash.indexOf('_ch_')
      if (hashChIdx !== -1) {
        store.sourceChannelId = parseInt(gHash.slice(hashChIdx + 4))
        gHash = gHash.slice(0, hashChIdx)
      }
      const gid = normalizePublicId(gHash)
      if (gid) this.openGiveaway(gid)
    }
  },
})

app.component('giveaway-card', GiveawayCard)
app.component('date-picker', DatePicker)
app.component('winner-reveal', WinnerReveal)
app.component('home-page', HomePage)
app.component('channels-page', ChannelsPage)
app.component('my-page', MyPage)
app.component('create-page', CreatePage)
app.component('detail-page', DetailPage)
app.component('analytics-page', AnalyticsPage)

async function initApp() {
  console.log('App init started')
  if (tg) {
    tg.ready()
    tg.expand()
    try { tg.setHeaderColor('#06060f'); tg.setBackgroundColor('#06060f') } catch (e) {}
  }
  initUser()
  console.log('User initialized:', store.user)

  try {
    await loadCsrfToken()
    console.log('CSRF token loaded')
  } catch (e) {
    console.error('Failed to load CSRF token:', e)
  }

  if (tg && tg.initDataUnsafe) {
    api('/bot-info').then(r => { 
      if (r.username) {
        store.botUsername = r.username
        console.log('Bot username loaded:', r.username)
      }
    }).catch(e => console.error('Failed to load bot info:', e))
  }

  console.log('Mounting app')
  app.mount('#app')
}

initApp()
