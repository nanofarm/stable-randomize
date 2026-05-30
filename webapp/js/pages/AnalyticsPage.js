import { api, haptic, store, tg, toast } from '../store.js'

const C = {
  bg0: '#0D0D12',
  bg1: '#13131A',
  bg2: '#1A1A24',
  bg3: '#22222F',
  border: '#2C2C3E',
  text0: '#F0F0F8',
  text1: '#9898B4',
  text2: '#5C5C78',
  accent: '#6C5CE7',
  accent2: '#A78BFA',
  green: '#34D399',
  orange: '#FB923C',
  red: '#F87171',
  pink: '#F472B6',
}

function fmtNumber(n) {
  const value = Number(n || 0)
  return value >= 1000 ? (value / 1000).toFixed(1).replace('.0', '') + 'K' : String(value)
}

export default {
  name: 'AnalyticsPage',
  props: {
    giveawayId: { type: String, required: true },
  },
  emits: ['back', 'broadcast'],
  data() {
    return {
      loading: true,
      stats: null,
      activeTab: 'overview',
      participants: [],
    }
  },
  computed: {
    giveaway() {
      return this.stats && this.stats.giveaway ? this.stats.giveaway : null
    },
    title() {
      return this.giveaway ? this.giveaway.title : 'Аналитика'
    },
    periodLabel() {
      if (!this.giveaway) return ''
      const start = this.formatShortDate(this.giveaway.start_date || this.giveaway.created_at)
      const end = this.giveaway.end_date ? this.formatShortDate(this.giveaway.end_date) : 'сейчас'
      return `${start} - ${end}`
    },
    ageBars() {
      if (!this.stats) return []
      const age = this.stats.age_groups || {}
      const total = Math.max(1, Number(this.stats.total || 0))
      return [
        { label: '13-17', val: Math.round(((age['13-17'] || 0) / total) * 100) },
        { label: '18-24', val: Math.round(((age['18-24'] || 0) / total) * 100) },
        { label: '25-34', val: Math.round(((age['25-34'] || 0) / total) * 100) },
        { label: '35-44', val: Math.round(((age['35-44'] || 0) / total) * 100) },
        { label: '45+', val: Math.round(((age['45+'] || 0) / total) * 100) },
      ]
    },
    gender() {
      const rows = this.stats && this.stats.gender ? this.stats.gender : []
      const male = rows.find(row => row.gender === 'male')
      const female = rows.find(row => row.gender === 'female')
      return {
        male: Number(male ? male.percent : 0),
        female: Number(female ? female.percent : 0),
      }
    },
    deviceCards() {
      const devices = this.stats && this.stats.devices ? this.stats.devices : { ios: 0, android: 0, desktop: 0 }
      return [
        { label: 'iOS', icon: '🍎', val: devices.ios || 0, color: C.text0 },
        { label: 'Android', icon: '🤖', val: devices.android || 0, color: C.green },
        { label: 'Desktop', icon: '💻', val: devices.desktop || 0, color: C.orange },
      ]
    },
    timelinePoints() {
      const timeline = this.stats && this.stats.timeline ? this.stats.timeline : []
      return timeline.slice(-30).map(item => Number(item.count || 0))
    },
    topChannels() {
      return this.stats && this.stats.top_channels ? this.stats.top_channels : []
    },
    geoRows() {
      return this.stats && this.stats.geo ? this.stats.geo.slice(0, 6) : []
    },
    sourceRows() {
      return this.stats && this.stats.sources ? this.stats.sources.slice(0, 8) : []
    },
  },
  async mounted() {
    await this.load()
  },
  methods: {
    fmtNumber,
    async load() {
      this.loading = true
      const [r, p] = await Promise.all([
        api('/analytics/admin/' + this.giveawayId),
        api('/giveaway/' + this.giveawayId)
      ])
      if (r.ok) {
        this.stats = r
      } else {
        toast(r.error || 'Не удалось загрузить аналитику', 'err')
      }
      if (p.ok && p.giveaway) {
        this.participants = p.giveaway.participants || []
      }
      this.loading = false
    },
    formatShortDate(value) {
      if (!value) return '—'
      return new Date(value).toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' })
    },
    exportCsv() {
      const url = location.origin + '/api/analytics/' + this.giveawayId + '/csv?creator_id=' + store.user.id
      if (tg) tg.openLink(url)
      else window.open(url, '_blank')
      haptic('ok')
    },
    linePath(data, width = 340, height = 88) {
      if (!data.length) return ''
      const max = Math.max(...data, 1)
      const min = Math.min(...data, 0)
      return data.map((value, index) => {
        const x = data.length === 1 ? width / 2 : (index / (data.length - 1)) * width
        const y = height - ((value - min) / ((max - min) || 1)) * (height - 8) - 4
        return `${index === 0 ? 'M' : 'L'}${x},${y}`
      }).join(' ')
    },
    areaPath(data, width = 340, height = 88) {
      const line = this.linePath(data, width, height)
      if (!line) return ''
      return `${line} L${width},${height} L0,${height} Z`
    },
    donutSegments() {
      const slices = [
        { val: this.gender.male, color: C.accent },
        { val: this.gender.female, color: C.pink },
      ]
      const total = Math.max(1, slices.reduce((sum, slice) => sum + slice.val, 0))
      let angle = -Math.PI / 2
      return slices.map(slice => {
        const radius = 28
        const cx = 45
        const cy = 45
        const arc = (slice.val / total) * Math.PI * 2
        const x1 = cx + radius * Math.cos(angle)
        const y1 = cy + radius * Math.sin(angle)
        angle += arc
        const x2 = cx + radius * Math.cos(angle)
        const y2 = cy + radius * Math.sin(angle)
        const large = arc > Math.PI ? 1 : 0
        return {
          d: `M${x1},${y1} A${radius},${radius} 0 ${large},1 ${x2},${y2}`,
          color: slice.color,
        }
      })
    },
  },
  template: `
    <div style="font-family:Manrope, sans-serif;color:#F0F0F8;min-height:100vh;background:#0D0D12;padding-bottom:24px">
      <div style="padding:16px 16px 0;position:sticky;top:0;background:#0D0D12;z-index:100;border-bottom:1px solid #2C2C3E;padding-bottom:12px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
          <div style="display:flex;align-items:center;gap:10px">
            <button @click="$emit('back')" style="background:none;border:none;color:#6C5CE7;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;padding:0">
              Назад
            </button>
            <div>
              <div style="font-size:11px;color:#5C5C78;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;margin-bottom:2px">Аналитика</div>
              <div style="font-size:18px;font-weight:700;line-height:1">{{ title }}</div>
            </div>
          </div>
          <div style="display:flex;gap:8px;align-items:center">
            <button v-if="giveaway && giveaway.status === 'active'" @click="$emit('broadcast', giveawayId)" style="background:#6C5CE722;border:1px solid #6C5CE744;border-radius:10px;color:#9580FF;font-size:12px;font-weight:600;padding:7px 12px;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:6px">
              <span>📢</span>
              <span>Написать участникам</span>
            </button>
            <button @click="exportCsv" style="background:#34D39922;border:1px solid #34D39944;border-radius:10px;color:#34D399;font-size:12px;font-weight:600;padding:7px 12px;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:6px">
              <span>📥</span>
              <span>Скачать участников</span>
            </button>
          </div>
        </div>
        <div v-if="giveaway" style="background:#1A1A24;border:1px solid #2C2C3E;border-radius:12px;padding:10px 12px;display:flex;justify-content:space-between;align-items:center">
          <div>
            <div style="font-size:13px;font-weight:600;color:#F0F0F8">{{ title }}</div>
            <div style="font-size:11px;color:#5C5C78;margin-top:1px">{{ periodLabel }}</div>
          </div>
          <div style="display:flex;gap:6px;align-items:center">
            <div v-if="giveaway.status === 'active'" style="width:6px;height:6px;border-radius:3px;background:#34D399;box-shadow:0 0 6px #34D399"></div>
            <span style="font-size:11px;color:#9898B4">{{ giveaway.status === 'active' ? 'Активен' : giveaway.status === 'finished' ? 'Завершён' : 'Черновик' }}</span>
          </div>
        </div>
      </div>

      <div style="display:flex;padding:12px 16px 0;overflow-x:auto;scrollbar-width:none">
        <button v-for="t in [{id:'overview',label:'Обзор'},{id:'audience',label:'Аудитория'},{id:'sources',label:'Источники'},{id:'channels',label:'Каналы'},{id:'participants',label:'Участники'}]"
          :key="t.id"
          @click="activeTab = t.id"
          :style="{background:'none',border:'none',cursor:'pointer',padding:'8px 14px',fontSize:'13px',fontWeight:'500',color:activeTab===t.id ? '#F0F0F8' : '#5C5C78',borderBottom:activeTab===t.id ? '2px solid #6C5CE7' : '2px solid transparent',whiteSpace:'nowrap',fontFamily:'inherit'}">
          {{ t.label }}
        </button>
      </div>

      <div v-if="loading" style="padding:80px 20px;text-align:center;color:#9898B4">Загружаем аналитику...</div>
      <div v-else-if="!stats" style="padding:80px 20px;text-align:center;color:#F87171">Не удалось загрузить аналитику</div>

      <div v-else style="padding:12px 16px 0">
        <template v-if="activeTab === 'overview'">
          <div style="display:flex;gap:8px;margin-bottom:10px">
            <div style="background:#1A1A24;border-radius:14px;padding:14px 12px;flex:1">
              <div style="font-size:10px;color:#5C5C78;margin-bottom:6px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase">Участников</div>
              <div style="font-size:22px;font-weight:700;line-height:1">{{ fmtNumber(stats.kpi.participants) }}</div>
              <div style="font-size:11px;color:#5C5C78">всего</div>
            </div>
            <div style="background:#1A1A24;border-radius:14px;padding:14px 12px;flex:1">
              <div style="font-size:10px;color:#5C5C78;margin-bottom:6px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase">Сегодня</div>
              <div style="font-size:22px;font-weight:700;line-height:1;color:#6C5CE7">{{ stats.kpi.new_today > 0 ? '+' + fmtNumber(stats.kpi.new_today) : '—' }}</div>
              <div style="font-size:11px;color:#5C5C78">новых участников</div>
            </div>
          </div>
          <div style="display:flex;gap:8px;margin-bottom:10px">
            <div style="background:#1A1A24;border-radius:14px;padding:14px 12px;flex:1">
              <div style="font-size:10px;color:#5C5C78;margin-bottom:6px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase">Каналов</div>
              <div style="font-size:22px;font-weight:700;line-height:1;color:#FB923C">{{ stats.kpi.channels }}</div>
              <div style="font-size:11px;color:#5C5C78">источников</div>
            </div>
          </div>
          <div style="background:#13131A;border:1px solid #2C2C3E;border-radius:16px;padding:16px;margin-bottom:10px">
            <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:10px">
              <div style="font-size:11px;font-weight:600;color:#5C5C78;letter-spacing:0.08em;text-transform:uppercase">Динамика участий</div>
              <span style="font-size:11px;color:#5C5C78">по дням</span>
            </div>
            <svg viewBox="0 0 340 88" width="100%" height="88" style="overflow:visible">
              <defs>
                <linearGradient id="analyticsLineGradient" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stop-color="#6C5CE7" stop-opacity="0.3"></stop>
                  <stop offset="100%" stop-color="#6C5CE7" stop-opacity="0"></stop>
                </linearGradient>
              </defs>
              <path :d="areaPath(timelinePoints)" fill="url(#analyticsLineGradient)"></path>
              <path :d="linePath(timelinePoints)" fill="none" stroke="#6C5CE7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
            </svg>
          </div>
        </template>

        <template v-if="activeTab === 'audience'">
          <div style="background:#13131A;border:1px solid #2C2C3E;border-radius:16px;padding:16px;margin-bottom:10px">
            <div style="font-size:11px;font-weight:600;color:#5C5C78;letter-spacing:0.08em;text-transform:uppercase;margin-bottom:12px">Возраст</div>
            <div style="display:flex;gap:6px;align-items:flex-end;height:88px">
              <div v-for="bar in ageBars" :key="bar.label" style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px">
                <div style="font-size:10px;color:#9898B4;font-weight:600">{{ bar.val }}%</div>
                <div :style="{width:'100%',background:'#6C5CE722',borderRadius:'6px',overflow:'hidden',height: Math.max(8, Math.round((bar.val / Math.max(...ageBars.map(x => x.val), 1)) * 52) + 8) + 'px'}">
                  <div style="width:100%;height:100%;background:linear-gradient(to top,#6C5CE7,#A78BFA);border-radius:6px"></div>
                </div>
                <div style="font-size:9.5px;color:#5C5C78">{{ bar.label }}</div>
              </div>
            </div>
          </div>

          <div style="background:#13131A;border:1px solid #2C2C3E;border-radius:16px;padding:16px;margin-bottom:10px">
            <div style="font-size:11px;font-weight:600;color:#5C5C78;letter-spacing:0.08em;text-transform:uppercase;margin-bottom:12px">Пол</div>
            <div style="display:flex;gap:16px;align-items:center">
              <svg width="90" height="90">
                <path v-for="(segment, index) in donutSegments()" :key="index" :d="segment.d" fill="none" :stroke="segment.color" stroke-width="10"></path>
              </svg>
              <div style="flex:1;display:flex;flex-direction:column;gap:10px">
                <div>
                  <div style="display:flex;justify-content:space-between;margin-bottom:5px">
                    <div style="display:flex;gap:6px;align-items:center"><div style="width:8px;height:8px;border-radius:2px;background:#6C5CE7"></div><span style="font-size:12px;color:#9898B4">Мужской</span></div>
                    <span style="font-size:14px;font-weight:600">{{ gender.male }}%</span>
                  </div>
                  <div style="background:#22222F;border-radius:99px;overflow:hidden;height:5px"><div :style="{width: gender.male + '%', background:'#6C5CE7', height:'100%'}"></div></div>
                </div>
                <div>
                  <div style="display:flex;justify-content:space-between;margin-bottom:5px">
                    <div style="display:flex;gap:6px;align-items:center"><div style="width:8px;height:8px;border-radius:2px;background:#F472B6"></div><span style="font-size:12px;color:#9898B4">Женский</span></div>
                    <span style="font-size:14px;font-weight:600">{{ gender.female }}%</span>
                  </div>
                  <div style="background:#22222F;border-radius:99px;overflow:hidden;height:5px"><div :style="{width: gender.female + '%', background:'#F472B6', height:'100%'}"></div></div>
                </div>
              </div>
            </div>
          </div>

          <div style="background:#13131A;border:1px solid #2C2C3E;border-radius:16px;padding:16px;margin-bottom:10px">
            <div style="font-size:11px;font-weight:600;color:#5C5C78;letter-spacing:0.08em;text-transform:uppercase;margin-bottom:12px">География</div>
            <div style="display:flex;flex-direction:column;gap:10px">
              <div v-for="(row, index) in geoRows" :key="row.country">
                <div style="display:flex;justify-content:space-between;margin-bottom:5px;align-items:baseline">
                  <span style="font-size:13px;color:#9898B4">{{ row.country }}</span>
                  <div style="display:flex;gap:6px;align-items:baseline">
                    <span style="font-size:13px;font-weight:600">{{ fmtNumber(row.count) }}</span>
                    <span style="font-size:11px;color:#5C5C78">{{ row.percent }}%</span>
                  </div>
                </div>
                <div style="background:#22222F;border-radius:99px;overflow:hidden;height:4px">
                  <div :style="{width: row.percent + '%', background:index === 0 ? '#6C5CE7' : index === 1 ? '#A78BFA' : '#5C5C78', height:'100%'}"></div>
                </div>
              </div>
            </div>
          </div>

          <div style="background:#13131A;border:1px solid #2C2C3E;border-radius:16px;padding:16px;margin-bottom:10px">
            <div style="font-size:11px;font-weight:600;color:#5C5C78;letter-spacing:0.08em;text-transform:uppercase;margin-bottom:12px">Устройства</div>
            <div style="display:flex;gap:8px">
              <div v-for="device in deviceCards" :key="device.label" style="flex:1;background:#1A1A24;border-radius:12px;padding:12px 10px;text-align:center">
                <div style="font-size:20px;margin-bottom:4px">{{ device.icon }}</div>
                <div :style="{fontSize:'18px',fontWeight:'700',color:device.color}">{{ device.val }}%</div>
                <div style="font-size:10px;color:#5C5C78;margin-top:2px">{{ device.label }}</div>
              </div>
            </div>
          </div>
        </template>

        <template v-if="activeTab === 'sources'">
          <div style="background:#13131A;border:1px solid #2C2C3E;border-radius:16px;padding:16px;margin-bottom:10px">
            <div style="font-size:11px;font-weight:600;color:#5C5C78;letter-spacing:0.08em;text-transform:uppercase;margin-bottom:12px">Источники трафика</div>
            <div style="display:flex;flex-direction:column;gap:8px">
              <div v-for="row in sourceRows" :key="row.name">
                <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                  <span style="font-size:12px;color:#9898B4">{{ row.name }}</span>
                  <span style="font-size:11px;color:#5C5C78">{{ row.percent }}%</span>
                </div>
                <div style="background:#22222F;border-radius:99px;overflow:hidden;height:5px">
                  <div :style="{width: row.percent + '%', background:'#6C5CE7', height:'100%'}"></div>
                </div>
              </div>
            </div>
          </div>
        </template>

        <template v-if="activeTab === 'channels'">
          <div style="background:#13131A;border:1px solid #2C2C3E;border-radius:16px;padding:12px 0;margin-bottom:10px">
            <div style="display:grid;grid-template-columns:1fr 56px 48px 56px 32px;gap:4px;padding:0 12px 8px;border-bottom:1px solid #2C2C3E;margin-bottom:4px">
              <span style="font-size:10px;color:#5C5C78;font-weight:600;text-transform:uppercase;letter-spacing:0.06em">Канал</span>
              <span style="font-size:10px;color:#5C5C78;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;text-align:center">Уч.</span>
              <span style="font-size:10px;color:#5C5C78;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;text-align:center">CVR</span>
              <span style="font-size:10px;color:#5C5C78;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;text-align:right">Подп.</span>
              <span></span>
            </div>
            <div v-for="(channel, index) in topChannels" :key="channel.channel_id" :style="{display:'grid',gridTemplateColumns:'1fr 56px 48px 56px 32px',gap:'4px',padding:'10px 12px',background:index % 2 === 0 ? 'transparent' : '#1A1A2480',alignItems:'center'}">
              <div style="display:flex;gap:8px;align-items:center">
                <div style="width:20px;height:20px;border-radius:6px;background:#6C5CE722;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#6C5CE7;flex-shrink:0">{{ index + 1 }}</div>
                <span style="font-size:12px;color:#F0F0F8;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ channel.username ? '@' + channel.username : channel.title }}</span>
              </div>
              <span style="font-size:13px;font-weight:600;color:#F0F0F8;text-align:center">{{ fmtNumber(channel.participants) }}</span>
              <div style="text-align:center">
                <span :style="{fontSize:'12px',fontWeight:'600',color:channel.conversion_percent > 35 ? '#34D399' : channel.conversion_percent > 25 ? '#FB923C' : '#9898B4'}">{{ channel.conversion_percent == null ? '—' : channel.conversion_percent + '%' }}</span>
              </div>
              <span style="font-size:11px;color:#5C5C78;text-align:right">{{ channel.member_count ? fmtNumber(channel.member_count) : '—' }}</span>
            </div>
          </div>
        </template>

        <template v-if="activeTab === 'participants'">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
            <div style="font-size:13px;font-weight:600;color:#6060A0">Участники</div>
            <div style="background:#6C5CE722;color:#6C5CE7;font-size:11px;font-weight:700;border-radius:99px;padding:3px 9px">{{ participants.length }}</div>
          </div>
          <div v-if="participants && participants.length" style="background:#13131A;border:1px solid #2C2C3E;border-radius:16px;overflow:hidden;margin-bottom:10px">
            <div v-for="(p, i) in participants" :key="p.user_id" :style="{ display:'flex', alignItems:'center', gap:'10px', padding:'11px 14px', borderBottom: i < participants.length-1 ? '1px solid #1A1A28' : 'none' }">
              <div :style="{ width:'34px', height:'34px', borderRadius:'10px', background:'#6C5CE718', border:'1px solid #6C5CE730', display:'flex', alignItems:'center', justifyContent:'center', fontSize:'13px', fontWeight:'700', color:'#6C5CE7', flexShrink:0 }">{{ p.user_name[0].toUpperCase() }}</div>
              <div style="flex:1;min-width:0">
                <div style="font-size:13px;font-weight:500;color:#D0D0E8;display:flex;align-items:center;gap:5px">
                  <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ p.user_name }}</span>
                  <span v-if="p.nickname_bonus" style="font-size:9px;background:#6C5CE722;color:#9580FF;border-radius:4px;padding:1px 5px;font-weight:600;flex-shrink:0">×10</span>
                </div>
                <div style="font-size:11px;color:#44445A;margin-top:1px">
                  <span v-if="p.referred_by">👤 приглашён · </span>
                </div>
              </div>
              <div style="text-align:right;flex-shrink:0">
                <div style="font-size:13px;font-weight:700;color:#F0F0F8">{{ p.tickets || 1 }}</div>
                <div style="font-size:10px;color:#44445A">билетов</div>
              </div>
            </div>
          </div>
          <div v-else style="text-align:center;padding:30px;color:#50507A;font-size:14px;background:#13131A;border:1px solid #2C2C3E;border-radius:16px;margin-bottom:10px">
            Пока никто не присоединился
          </div>
        </template>
      </div>
    </div>
  `,
}
