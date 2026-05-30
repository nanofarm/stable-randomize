
import { store, api, loadChannels, toast, tg, haptic } from '../store.js'

const CH_COLORS = ['#7C6FF7', '#34D399', '#FB923C', '#F472B6', '#A78BFA']

export default {
  name: 'ChannelsPage',
  computed: {
    channels() { return store.myChannels },
  },
  async mounted() {
    await this.load()
  },
  methods: {
    async load() {
      await loadChannels()
    },
    async connect() {
      const r = await api('/channels/connect-request', {
        method: 'POST',
        body: JSON.stringify({ user_id: store.user?.id }),
      })
      if (r.ok) {
        haptic('ok')
        toast('Открой бота — там кнопка выбора канала', 'ok')
        if (tg) setTimeout(() => tg.openTelegramLink(`https://t.me/${store.botUsername}`), 800)
      } else {
        toast(r.error || 'Ошибка', 'err')
      }
    },
    async remove(id) {
      if (!confirm('Отключить канал?')) return
      const r = await api('/channels/disconnect', { method: 'POST', body: JSON.stringify({ channel_id: id }) })
      if (r.ok) {
        haptic('ok')
        toast('Канал отключён', 'ok')
      } else {
        toast(r.error || 'Ошибка', 'err')
      }
      await loadChannels()
    },
    chColor(i) { return CH_COLORS[i % CH_COLORS.length] },
  },
  template: `
    <div style="padding:0 16px;animation:fs .4s ease">

      <!-- Section header -->
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <div style="font-size:13px;font-weight:600;color:#9090B8">Мои каналы</div>
        <div style="font-size:12px;font-weight:600;color:#6060A0">{{ channels.length }}</div>
      </div>

      <!-- Channel list -->
      <div style="display:flex;flex-direction:column;gap:8px">
        <div v-for="(ch, i) in channels" :key="ch.id"
          style="background:#13131C;border:1px solid #1E1E2C;border-radius:16px;padding:14px 16px;display:flex;align-items:center;gap:12px">
          <div :style="{
            width:'42px',height:'42px',borderRadius:'12px',
            background: chColor(i) + '22',
            border: '1.5px solid ' + chColor(i) + '44',
            display:'flex',alignItems:'center',justifyContent:'center',
            fontSize:'18px',flexShrink:'0',
          }">📡</div>
          <div style="flex:1;min-width:0">
            <div style="font-size:14px;font-weight:600;color:#F0F0F8;margin-bottom:2px">{{ ch.title }}</div>
            <div style="font-size:11px;color:#6060A0">
              {{ ch.username ? '@' + ch.username : 'ID: ' + ch.chat_id }}
              <span v-if="ch.member_count"> · {{ ch.member_count }} подп.</span>
            </div>
          </div>
          <button @click="remove(ch.id)"
            style="background:none;border:none;cursor:pointer;color:#44445A;padding:4px;display:flex;flex-shrink:0">
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
              <path d="M2 2l10 10M12 2L2 12" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
            </svg>
          </button>
        </div>
      </div>

      <!-- Add channel button -->
      <button @click="connect" style="
        width:100%;margin-top:12px;padding:13px;
        background:none;border:1.5px dashed #2C2C42;
        border-radius:14px;color:#6060A0;font-size:13px;font-weight:500;
        cursor:pointer;font-family:inherit;
        display:flex;align-items:center;justify-content:center;gap:6px;
      ">
        <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
          <circle cx="7" cy="7" r="5.5" stroke="currentColor" stroke-width="1.3"/>
          <path d="M7 4.5v5M4.5 7h5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
        </svg>
        Добавить канал
      </button>

      <!-- Empty -->
      <div v-if="!channels.length" style="text-align:center;padding:40px 20px;color:#60608A">
        <div style="font-size:36px;margin-bottom:12px">📡</div>
        <div style="font-size:15px;font-weight:600;color:#9090C0;margin-bottom:6px">Нет каналов</div>
        <div style="font-size:13px">Добавь бота админом и подключи</div>
      </div>
    </div>
  `,
}
