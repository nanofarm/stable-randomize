
export default {
  name: 'GiveawayCard',
  props: {
    g: { type: Object, required: true },
  },
  emits: ['open', 'analytics'],
  data() {
    return { expanded: false }
  },
  computed: {
    isLive()     { return this.g.status === 'active' },
    isDraft()    { return this.g.status === 'draft' },
    isFinished() { return this.g.status === 'finished' },

    winnersCount() {
      if (this.g.prizes && this.g.prizes.length) return this.g.prizes.length
      return this.g.winners_count
    },

    prizeList() {
      if (this.g.prizes && this.g.prizes.length) {
        return this.g.prizes.slice(0, 3).map(p => p.title)
      }
      if (this.g.prize) return [this.g.prize]
      return []
    },

    daysLeft() {
      if (!this.g.end_date) return null
      const diff = new Date(this.g.end_date) - new Date()
      if (diff <= 0) return 0
      return Math.floor(diff / 864e5)
    },

    daysColor() {
      if (this.daysLeft === null) return '#6060A0'
      if (this.daysLeft === 0)   return '#6060A0'
      if (this.daysLeft <= 2)   return '#F87171'
      if (this.daysLeft <= 5)   return '#FB923C'
      return '#34D399'
    },

    fmt() {
      const n = this.g.participant_count || 0
      return n >= 1000 ? (n / 1000).toFixed(1).replace('.0', '') + 'K' : n
    },
  },
  template: `
    <div
      @click="expanded = !expanded"
      :style="{
        background: '#13131C',
        border: '1px solid ' + (expanded ? '#6C5CE744' : '#1E1E2C'),
        borderRadius: '16px',
        padding: '14px 16px',
        cursor: 'pointer',
        transition: 'border-color .2s',
        marginBottom: '10px',
      }"
    >
      <!-- Top row: id + badge -->
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px">
        <div style="flex:1;min-width:0;margin-right:10px">
          <div style="font-size:15px;font-weight:700;color:#F0F0F8;letter-spacing:-0.01em;line-height:1.2">{{ g.public_id || g.id }}</div>
          <div style="font-size:12px;color:#60608A;margin-top:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ g.title }}</div>
        </div>

        <!-- LIVE badge -->
        <div v-if="isLive" style="display:flex;align-items:center;gap:4px;background:#0D2A1E;border:1px solid #1A4A30;border-radius:99px;padding:3px 9px;flex-shrink:0">
          <div style="width:5px;height:5px;border-radius:3px;background:#34D399;box-shadow:0 0 6px #34D399"></div>
          <span style="font-size:10px;font-weight:700;color:#34D399;letter-spacing:0.05em">LIVE</span>
        </div>
        <div v-else-if="isDraft" style="background:#1E1E2C;border-radius:99px;padding:3px 9px;flex-shrink:0">
          <span style="font-size:10px;font-weight:600;color:#8080C0">ЧЕРНОВИК</span>
        </div>
        <div v-else style="background:#1E1E2C;border-radius:99px;padding:3px 9px;flex-shrink:0">
          <span style="font-size:10px;font-weight:600;color:#6060A0">ЗАВЕРШЁН</span>
        </div>
      </div>

      <!-- Prize tags -->
      <div v-if="prizeList.length" style="display:flex;flex-wrap:wrap;gap:5px;margin-bottom:10px">
        <div v-for="(p, i) in prizeList" :key="i" :style="{
          display:'flex', alignItems:'center', gap:'4px',
          background: i===0 ? '#2A221A' : i===1 ? '#1A1A24' : '#1A1F1A',
          border: '1px solid ' + (i===0 ? '#3D3020' : i===1 ? '#252535' : '#252A25'),
          borderRadius: '8px', padding: '3px 8px',
        }">
          <span style="font-size:11px">{{ i===0 ? '🥇' : i===1 ? '🥈' : '🥉' }}</span>
          <span :style="{ fontSize:'11px', fontWeight:'500', color: i===0?'#F5C842':i===1?'#A0A0C0':'#8EBF8E' }">{{ p }}</span>
        </div>
      </div>

      <!-- Stats row -->
      <div style="display:flex;gap:12px;align-items:center">
        <div style="display:flex;align-items:center;gap:5px">
          <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
            <circle cx="5" cy="4.5" r="2" stroke="#6060A0" stroke-width="1.2"/>
            <circle cx="8.5" cy="5" r="1.6" stroke="#6060A0" stroke-width="1.2"/>
            <path d="M1 10.5c0-1.657 1.79-3 4-3s4 1.343 4 3" stroke="#6060A0" stroke-width="1.2" stroke-linecap="round"/>
            <path d="M9 8.2c1.1.4 2 1.2 2 2.3" stroke="#6060A0" stroke-width="1.2" stroke-linecap="round"/>
          </svg>
          <span style="font-size:12px;color:#8080A8;font-weight:500">{{ fmt }}</span>
        </div>
        <div style="display:flex;align-items:center;gap:5px">
          <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
            <path d="M6.5 1.5L7.9 4.8l3.6.3-2.7 2.3.9 3.5L6.5 9l-3.2 1.9.9-3.5L1.5 5.1l3.6-.3z" stroke="#6060A0" stroke-width="1.2" stroke-linejoin="round"/>
          </svg>
          <span style="font-size:12px;color:#8080A8;font-weight:500">{{ winnersCount }} побед.</span>
        </div>
        <div v-if="isLive && daysLeft !== null" style="margin-left:auto;font-size:11px;font-weight:600" :style="{ color: daysColor }">
          {{ daysLeft === 0 ? 'Сегодня финал' : daysLeft + 'д' }}
        </div>
      </div>

      <!-- Expanded actions -->
      <div v-if="expanded" @click.stop style="margin-top:12px;padding-top:12px;border-top:1px solid #1E1E2C;display:flex;gap:8px">
        <button @click.stop="$emit('analytics', g.id)" style="flex:1;padding:9px;background:#6C5CE718;border:1px solid #6C5CE740;border-radius:10px;color:#6C5CE7;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit">
          📊 Аналитика
        </button>
        <button @click.stop="$emit('open', g.id)" style="flex:1;padding:9px;background:#1E1E2A;border:1px solid #2C2C3E;border-radius:10px;color:#A0A0C0;font-size:12px;font-weight:500;cursor:pointer;font-family:inherit">
          ✏️ Изменить
        </button>
      </div>
    </div>
  `,
}
