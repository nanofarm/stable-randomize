
import { store, api } from '../store.js'

export default {
  name: 'MyPage',
  emits: ['open'],
  data() {
    return { participated: [], loaded: false }
  },
  async mounted() {
    await this.load()
  },
  methods: {
    async load() {
      const r = await api('/giveaways')
      if (r.ok) {
        this.participated = r.participated || []
      }
      this.loaded = true
    },
  },
  template: `
    <div style="padding:0 16px;animation:fs .4s ease">

      <!-- Section header -->
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <div style="font-size:13px;font-weight:600;color:#9090B8">Розыгрыши в которых участвую</div>
        <div style="font-size:12px;font-weight:600;color:#6060A0">{{ participated.length }}</div>
      </div>

      <!-- Cards -->
      <div style="display:flex;flex-direction:column;gap:8px">
        <div v-for="g in participated" :key="g.id"
          @click="$emit('open', g.id)"
          style="background:#13131C;border:1px solid #1E1E2C;border-radius:16px;padding:14px 16px;display:flex;align-items:center;gap:12px;cursor:pointer">
          <div :style="{
            width:'40px',height:'40px',borderRadius:'12px',
            background: g.status==='active' ? '#1A2A1E' : g.status==='finished' ? '#1A1A2E' : '#1E1A35',
            border: '1.5px solid ' + (g.status==='active' ? '#1E3A28' : g.status==='finished' ? '#2A2A45' : '#312A55'),
            display:'flex',alignItems:'center',justifyContent:'center',fontSize:'18px',flexShrink:0,
          }">{{ g.status === 'finished' ? '🏆' : '🎰' }}</div>
          <div style="flex:1;min-width:0">
            <div style="display:flex;align-items:center;gap:6px;margin-bottom:3px">
              <div style="font-size:14px;font-weight:600;color:#F0F0F8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ g.title || g.public_id || g.id }}</div>
              <div v-if="g.status==='active'" style="font-size:9px;font-weight:700;color:#34D399;background:#0D2A1E;border-radius:4px;padding:1px 5px;flex-shrink:0">LIVE</div>
              <div v-else-if="g.status==='finished'" style="font-size:9px;font-weight:700;color:#6060A0;background:#1E1E2A;border-radius:4px;padding:1px 5px;flex-shrink:0">END</div>
            </div>
            <div style="font-size:11px;color:#6060A0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ g.participant_count || 0 }} участников</div>
          </div>
          <svg width="7" height="12" viewBox="0 0 7 12" fill="none">
            <path d="M1 1l5 5-5 5" stroke="#44445A" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
      </div>

      <!-- Empty -->
      <div v-if="loaded && !participated.length" style="text-align:center;padding:48px 20px;color:#60608A">
        <div style="font-size:40px;margin-bottom:12px">🎰</div>
        <div style="font-size:15px;font-weight:600;color:#9090C0;margin-bottom:6px">Пока пусто</div>
        <div style="font-size:13px">Вступи в розыгрыш через ссылку или канал</div>
      </div>
    </div>
  `,
}
