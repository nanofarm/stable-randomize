
import { store, api, toast, haptic } from '../store.js'

export default {
  name: 'HomePage',
  emits: ['open', 'create', 'analytics'],
  data() {
    return { giveaways: [], loaded: false }
  },
  async mounted() {
    await this.load()
  },
  computed: {
    liveCount() { return this.giveaways.filter(g => g.status === 'active').length },
  },
  methods: {
    async load() {
      const r = await api('/giveaways')
      if (r.ok) {
        this.giveaways = r.created || []
      }
      this.loaded = true
    },
    async deleteGiveaway(g, event) {
      event.stopPropagation()
      if (!confirm('Удалить розыгрыш «' + (g.title || g.id) + '»?')) return
      const r = await api('/giveaway/delete', { method: 'POST', body: JSON.stringify({ giveaway_id: g.id }) })
      if (r.ok) {
        haptic('ok')
        toast('Удалён', 'ok')
        this.giveaways = this.giveaways.filter(x => x.id !== g.id)
      } else {
        toast(r.error || 'Не удалось удалить', 'err')
      }
    },
  },
  template: `
    <div style="padding:0 16px;animation:fs .4s ease">

      <!-- CTA -->
      <div @click="$emit('create')" style="
        background:linear-gradient(135deg,#6C5CE7 0%,#9580FF 50%,#B06EF3 100%);
        border-radius:18px;padding:18px 20px;
        display:flex;align-items:center;justify-content:space-between;
        cursor:pointer;margin-bottom:20px;
        box-shadow:0 8px 32px #6C5CE740;
        position:relative;overflow:hidden;
      ">
        <div style="position:absolute;top:-20px;right:40px;width:80px;height:80px;background:rgba(255,255,255,0.08);border-radius:40px;filter:blur(20px)"></div>
        <div style="position:absolute;bottom:-10px;right:10px;width:60px;height:60px;background:rgba(255,255,255,0.06);border-radius:30px;filter:blur(16px)"></div>
        <div style="position:relative">
          <div style="font-size:17px;font-weight:700;color:#fff;letter-spacing:-0.02em;margin-bottom:2px">Создать розыгрыш</div>
          <div style="font-size:12px;color:rgba(255,255,255,0.65)">Запусти за 30 секунд</div>
        </div>
        <div style="width:42px;height:42px;background:rgba(255,255,255,0.18);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;position:relative;flex-shrink:0">✨</div>
      </div>

      <!-- Section header -->
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <div style="font-size:13px;font-weight:600;color:#9090B8">Мои розыгрыши</div>
        <div style="display:flex;gap:6px;align-items:center">
          <div v-if="liveCount" style="background:#6C5CE722;color:#6C5CE7;font-size:11px;font-weight:700;border-radius:99px;padding:3px 9px">
            {{ liveCount }} live
          </div>
          <div style="font-size:12px;font-weight:600;color:#6060A0">{{ giveaways.length }}</div>
        </div>
      </div>

      <!-- Cards -->
      <div style="display:flex;flex-direction:column;gap:8px">
        <div v-for="g in giveaways" :key="g.id"
          @click="$emit('open', g.id)"
          style="background:#13131C;border:1px solid #1E1E2C;border-radius:16px;padding:14px 16px;display:flex;align-items:center;gap:12px;cursor:pointer">
          <div style="width:40px;height:40px;border-radius:12px;background:#1E1A35;border:1.5px solid #312A55;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0">🎁</div>
          <div style="flex:1;min-width:0">
            <div style="display:flex;align-items:center;gap:6px;margin-bottom:3px">
              <div style="font-size:14px;font-weight:600;color:#F0F0F8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ g.title || g.public_id || g.id }}</div>
              <div v-if="g.status==='draft'" style="font-size:9px;font-weight:700;color:#8080C0;background:#1E1E2A;border-radius:4px;padding:1px 5px;flex-shrink:0">DRAFT</div>
              <div v-else-if="g.status==='active'" style="font-size:9px;font-weight:700;color:#34D399;background:#0D2A1E;border-radius:4px;padding:1px 5px;flex-shrink:0">LIVE</div>
              <div v-else style="font-size:9px;font-weight:700;color:#6060A0;background:#1E1E2A;border-radius:4px;padding:1px 5px;flex-shrink:0">END</div>
            </div>
            <div style="font-size:11px;color:#6060A0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ g.participant_count || 0 }} участников</div>
          </div>
          <button v-if="g.status !== 'active'" @click="deleteGiveaway(g, $event)"
            style="background:none;border:none;cursor:pointer;padding:8px;display:flex;align-items:center;flex-shrink:0;color:#F8717180;transition:color .15s"
            @mouseenter="$event.target.style.color='#F87171'"
            @mouseleave="$event.target.style.color='#F8717180'">
            <svg width="16" height="16" viewBox="0 0 14 14" fill="none"><path d="M2 3.5h10M5.5 3.5V2.5a1 1 0 011-1h1a1 1 0 011 1v1M3.5 3.5l.5 8a1.5 1.5 0 001.5 1.5h3a1.5 1.5 0 001.5-1.5l.5-8" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
          </button>
          <svg v-else width="7" height="12" viewBox="0 0 7 12" fill="none">
            <path d="M1 1l5 5-5 5" stroke="#44445A" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
      </div>

      <!-- Empty -->
      <div v-if="loaded && !giveaways.length" style="text-align:center;padding:48px 20px;color:#60608A">
        <div style="font-size:40px;margin-bottom:12px">🎰</div>
        <div style="font-size:15px;font-weight:600;color:#9090C0;margin-bottom:6px">Пока пусто</div>
        <div style="font-size:13px">Создай первый розыгрыш!</div>
      </div>
    </div>
  `,
}
