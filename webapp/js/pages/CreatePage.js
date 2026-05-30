
import { store, api, loadChannels, toast, haptic } from '../store.js'

const MEDAL = [
  { bg:'#2A2218', color:'#F5C842', border:'#3D3020' },
  { bg:'#1E2228', color:'#A8B8CC', border:'#2A3040' },
  { bg:'#221A18', color:'#CD7F50', border:'#3A2820' },
  { bg:'#1C2224', color:'#7E9EAC', border:'#263238' },
  { bg:'#1E1E2A', color:'#8880CC', border:'#28284A' },
]

const MAX_TICKETS = 30

export default {
  name: 'CreatePage',
  emits: ['back', 'created'],
  data() {
    return {
      description: '',
      nickCondition: '',
      prizes: [{ title: '' }],
      tasks: [{ text: '', price: '' }],
      saving: false,
      saved: false,
    }
  },
  computed: {
    channels() { return store.myChannels },
    prizes_colors() { return MEDAL },
    totalTaskTickets() {
      return this.tasks.reduce((s, t) => s + (parseInt(t.price) || 0), 0)
    },
    overLimit() {
      return this.totalTaskTickets > MAX_TICKETS
    },
  },
  async mounted() {
    await loadChannels()
  },
  methods: {
    addPrize() {
      if (this.prizes.length >= 5) return
      this.prizes.push({ title: '' })
    },
    removePrize(index) {
      if (this.prizes.length <= 1) return
      this.prizes.splice(index, 1)
    },
    addTask() {
      if (this.tasks.length >= 5) return
      this.tasks.push({ text: '', price: '' })
    },
    removeTask(index) {
      if (this.tasks.length <= 1) return
      this.tasks.splice(index, 1)
    },
    async create() {
      const desc = this.description.trim()
      const prizes = this.prizes
        .map((p, i) => ({ place: i + 1, title: p.title.trim() }))
        .filter(p => p.title)
      const tasks = this.tasks
        .map(t => ({ text: t.text.trim(), price: parseInt(t.price) || 0 }))
        .filter(t => t.text && t.price > 0)
      const title = desc || (prizes.length ? prizes[0].title : 'Розыгрыш')

      if (!desc && !prizes.length) {
        toast('Заполните описание или призы', 'err')
        return
      }

      const total = tasks.reduce((s, t) => s + t.price, 0)
      if (total > MAX_TICKETS) {
        haptic('err')
        toast(`Слишком много билетов (${total}). Максимум — ${MAX_TICKETS}. Уменьши цены заданий`, 'err')
        return
      }

      this.saving = true
      const body = {
        title,
        description: desc,
        prize: prizes.length ? prizes[0].title : '',
        prizes,
        tasks,
        winners_count: Math.max(1, prizes.length),
        creator_id: store.user.id,
        creator_name: (store.user.first_name + ' ' + (store.user.last_name || '')).trim(),
        channel_ids: store.myChannels.map(c => c.id),
        nickname_condition: this.nickCondition.trim() || null,
        nickname_bonus_multiplier: 10,
        referral_tickets: 1,
      }

      const r = await api('/giveaway', { method: 'POST', body: JSON.stringify(body) })
      this.saving = false

      if (r.ok) {
        haptic('ok')
        this.saved = true
        setTimeout(() => {
          this.$emit('created', { id: r.giveaway.id, title: r.giveaway.title })
        }, 700)
      } else {
        toast(r.error || 'Не удалось создать розыгрыш', 'err')
      }
    },
  },
  template: `
    <div style="font-family:inherit;color:#F0F0F8;min-height:100vh;background:#06060f;position:relative">

      <!-- Header -->
      <div style="position:sticky;top:0;z-index:50;background:#06060f;padding:14px 20px;box-shadow:0 2px 8px rgba(0,0,0,0.4)">
        <button @click="$emit('back')"
          style="background:none;border:none;color:#6C5CE7;font-size:13px;font-weight:500;cursor:pointer;display:flex;align-items:center;gap:4px;font-family:inherit;padding:0">
          <svg width="7" height="12" viewBox="0 0 7 12" fill="none">
            <path d="M6 1L1 6L6 11" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          Назад
        </button>
      </div>

      <div style="padding:28px 20px 110px">

        <!-- Hero -->
        <div style="text-align:center;margin-bottom:32px">
          <div style="width:64px;height:64px;background:linear-gradient(135deg,#1E1A35 0%,#251F42 100%);border-radius:20px;display:inline-flex;align-items:center;justify-content:center;font-size:30px;border:1px solid #312A55;margin-bottom:14px;box-shadow:0 8px 32px #6C5CE722">
            🎁
          </div>
          <div style="font-size:20px;font-weight:700;color:#F0F0F8;letter-spacing:-0.02em">Новый розыгрыш</div>
          <div style="font-size:13px;color:#60608A;margin-top:4px">Заполни детали и запусти</div>
        </div>

        <!-- Описание -->
        <div style="margin-bottom:20px">
          <div style="font-size:11.5px;font-weight:600;color:#7070A0;letter-spacing:0.01em;margin-bottom:7px">Описание и условия</div>
          <div style="background:#0f0f1a;border:1.5px solid #1e1e30;border-radius:14px;padding:13px 14px;display:flex;align-items:flex-start;gap:10px;transition:border-color 0.2s"
            @focusin="$event.currentTarget.style.borderColor='#6C5CE7';$event.currentTarget.style.background='#12121e'"
            @focusout="$event.currentTarget.style.borderColor='#1e1e30';$event.currentTarget.style.background='#0f0f1a'">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" style="flex-shrink:0;margin-top:2px">
              <rect x="1" y="1" width="14" height="14" rx="3" stroke="#44445A" stroke-width="1.4"/>
              <path d="M4 5.5h8M4 8h6M4 10.5h5" stroke="#44445A" stroke-width="1.4" stroke-linecap="round"/>
            </svg>
            <textarea v-model="description" placeholder="Опиши условия участия — что нужно сделать, чтобы войти в розыгрыш..." maxlength="500" rows="4"
              style="background:none;border:none;outline:none;color:#D0D0E8;width:100%;font-size:13.5px;font-family:inherit;line-height:1.55;resize:none"/>
          </div>
          <div style="font-size:11px;color:#40405A;margin-top:5px;text-align:right">{{ description.length }} / 500</div>
        </div>

        <!-- Призы -->
        <div style="margin-bottom:20px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
            <div style="font-size:11.5px;font-weight:600;color:#7070A0;letter-spacing:0.01em">Призы по местам</div>
            <button v-if="prizes.length < 5" @click="addPrize"
              style="background:none;border:none;cursor:pointer;font-size:12px;color:#6C5CE7;font-weight:500;font-family:inherit;display:flex;align-items:center;gap:3px">
              <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                <circle cx="6" cy="6" r="5" stroke="currentColor" stroke-width="1.3"/>
                <path d="M6 3.5v5M3.5 6h5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
              </svg>
              Добавить
            </button>
          </div>
          <div style="display:flex;flex-direction:column;gap:8px">
            <div v-for="(prize, i) in prizes" :key="i" style="display:flex;gap:10px;align-items:center">
              <div :style="{
                width:'28px',height:'28px',borderRadius:'8px',
                display:'flex',alignItems:'center',justifyContent:'center',
                fontSize:'11px',fontWeight:'700',flexShrink:0,
                background: (prizes_colors[i]||prizes_colors[4]).bg,
                color:       (prizes_colors[i]||prizes_colors[4]).color,
                border:      '1.5px solid ' + (prizes_colors[i]||prizes_colors[4]).border,
              }">{{ i + 1 }}</div>
              <div style="flex:1;background:#0f0f1a;border:1.5px solid #1e1e30;border-radius:14px;padding:12px 14px;display:flex;align-items:center;gap:8px;transition:border-color 0.2s"
                @focusin="$event.currentTarget.style.borderColor='#6C5CE7';$event.currentTarget.style.background='#12121e'"
                @focusout="$event.currentTarget.style.borderColor='#1e1e30';$event.currentTarget.style.background='#0f0f1a'">
                <input v-model="prize.title" :placeholder="'Приз за ' + (i+1) + ' место'" maxlength="200"
                  style="background:none;border:none;outline:none;color:#F0F0F8;width:100%;font-size:13.5px;font-family:inherit"/>
                <button v-if="i > 0" @click="removePrize(i)"
                  style="background:none;border:none;cursor:pointer;color:#44445A;padding:0;display:flex;flex-shrink:0">
                  <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                    <path d="M2 2l10 10M12 2L2 12" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                  </svg>
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Задания для участников -->
        <div style="margin-bottom:20px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
            <div style="display:flex;align-items:center;gap:7px">
              <div style="font-size:11.5px;font-weight:600;color:#7070A0;letter-spacing:0.01em">Задания для участников</div>
              <div style="font-size:10.5px;color:#44445A;font-weight:500">{{ tasks.length }}/5</div>
            </div>
            <button @click="addTask" :disabled="tasks.length >= 5"
              :style="{
                background:'none',border:'none',
                cursor: tasks.length >= 5 ? 'default' : 'pointer',
                fontSize:'12px',
                color: tasks.length >= 5 ? '#44445A' : '#6C5CE7',
                fontWeight:'500',fontFamily:'inherit',
                display:'flex',alignItems:'center',gap:'3px',
                opacity: tasks.length >= 5 ? 0.5 : 1,
              }">
              <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                <circle cx="6" cy="6" r="5" stroke="currentColor" stroke-width="1.3"/>
                <path d="M6 3.5v5M3.5 6h5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
              </svg>
              Добавить
            </button>
          </div>

          <div style="display:flex;flex-direction:column;gap:8px">
            <div v-for="(task, i) in tasks" :key="i"
              style="background:#0f0f1a;border:1.5px solid #1e1e30;border-radius:14px;padding:12px 14px;display:flex;flex-direction:column;gap:10px">
              <!-- Заголовок -->
              <div style="display:flex;align-items:center;justify-content:space-between">
                <div style="display:flex;align-items:center;gap:8px">
                  <div style="width:22px;height:22px;border-radius:7px;background:#6C5CE722;border:1.5px solid #6C5CE744;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#6C5CE7;flex-shrink:0">{{ i + 1 }}</div>
                  <span style="font-size:11.5px;color:#7070A0;font-weight:600">Задание {{ i + 1 }}</span>
                </div>
                <button v-if="tasks.length > 1" @click="removeTask(i)"
                  style="background:none;border:none;cursor:pointer;color:#44445A;padding:0;display:flex">
                  <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                    <path d="M2 2l10 10M12 2L2 12" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                  </svg>
                </button>
              </div>
              <!-- Текст задания -->
              <div style="background:#06060f;border-radius:10px;padding:10px 12px;border:1px solid #1E1E2C">
                <input v-model="task.text" placeholder="Опиши задание (напр. подписаться на канал, поставить лайк...)" maxlength="200"
                  style="background:none;border:none;outline:none;color:#D0D0E8;width:100%;font-size:13px;font-family:inherit"/>
              </div>
              <!-- Цена -->
              <div style="display:flex;align-items:center;gap:8px">
                <div style="background:#06060f;border-radius:10px;padding:9px 12px;border:1px solid #1E1E2C;display:flex;align-items:center;gap:7px;flex:1">
                  <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
                    <circle cx="6.5" cy="6.5" r="5.5" stroke="#44445A" stroke-width="1.2"/>
                    <path d="M6.5 3.5v.8M6.5 8.7v.8M4.5 5.3c0-.66.9-1.3 2-1.3s2 .64 2 1.3c0 1.3-4 1.3-4 2.7 0 .8 1 1.5 2 1.5s2-.7 2-1.5" stroke="#44445A" stroke-width="1.2" stroke-linecap="round"/>
                  </svg>
                  <input v-model="task.price" type="number" min="1" max="30" placeholder="Цена (билеты)"
                    style="background:none;border:none;outline:none;color:#D0D0E8;width:100%;font-size:13px;font-family:inherit"/>
                </div>
                <div style="background:#6C5CE718;border:1.5px solid #6C5CE730;border-radius:10px;padding:9px 12px;font-size:11.5px;color:#6C5CE7;font-weight:600;white-space:nowrap">
                  {{ task.price ? '+' + task.price + ' 🎫' : '+ билеты' }}
                </div>
              </div>
            </div>
          </div>

          <!-- Warning: ручная проверка -->
          <div style="margin-top:10px;background:#1A130E;border:1.5px solid #3A2A1A;border-radius:12px;padding:11px 13px;display:flex;gap:9px;align-items:flex-start">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" style="flex-shrink:0;margin-top:1px">
              <path d="M8 1.5L14.5 13H1.5L8 1.5z" stroke="#F5A623" stroke-width="1.4" stroke-linejoin="round"/>
              <path d="M8 6v3.5M8 11v.5" stroke="#F5A623" stroke-width="1.4" stroke-linecap="round"/>
            </svg>
            <div style="font-size:11.5px;color:#C09060;line-height:1.55">
              <span style="font-weight:700;color:#F5A623">Ручная проверка.</span>
              Все выполненные задания проверяются вручную нашей командой. Подтверждение может занять некоторое время.
            </div>
          </div>

          <!-- Лимит билетов / Превышен лимит -->
          <div :style="{
            marginTop:'8px',
            background: overLimit ? '#2A1414' : '#0F1620',
            border: '1.5px solid ' + (overLimit ? '#5A2030' : '#1E2E44'),
            borderRadius:'12px',padding:'11px 13px',
            display:'flex',gap:'9px',alignItems:'flex-start',
          }">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" style="flex-shrink:0;margin-top:1px">
              <circle cx="8" cy="8" r="6.5" :stroke="overLimit ? '#F87171' : '#5B9BD5'" stroke-width="1.4"/>
              <path d="M8 7v4.5M8 5v.5" :stroke="overLimit ? '#F87171' : '#5B9BD5'" stroke-width="1.4" stroke-linecap="round"/>
            </svg>
            <div :style="{ fontSize:'11.5px', color: overLimit ? '#D08070' : '#607A9A', lineHeight:'1.55' }">
              <span :style="{ fontWeight:'700', color: overLimit ? '#F87171' : '#7AADDC' }">
                {{ overLimit ? 'Превышен лимит!' : 'Лимит билетов.' }}
              </span>
              <template v-if="overLimit">
                Сумма билетов {{ totalTaskTickets }} больше {{ 30 }}. Уменьши цены заданий.
              </template>
              <template v-else>
                Максимальное количество билетов на одного участника —
                <span style="font-weight:700;color:#7AADDC">{{ 30 }} билетов</span>.
                <span v-if="totalTaskTickets > 0" style="color:#7AADDC">Сейчас задания дают {{ totalTaskTickets }}.</span>
              </template>
            </div>
          </div>
        </div>

        <!-- Условие в нике -->
        <div style="margin-bottom:20px">
          <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:8px">
            <div style="font-size:11.5px;font-weight:600;color:#7070A0;letter-spacing:0.01em">Условие в нике</div>
            <div style="background:#6C5CE722;color:#6C5CE7;font-size:10.5px;font-weight:600;border-radius:6px;padding:2px 7px;letter-spacing:0.04em">×10 билетов</div>
          </div>
          <div style="background:#0f0f1a;border:1.5px solid #1e1e30;border-radius:14px;padding:13px 14px;display:flex;align-items:center;gap:10px;transition:border-color 0.2s"
            @focusin="$event.currentTarget.style.borderColor='#6C5CE7';$event.currentTarget.style.background='#12121e'"
            @focusout="$event.currentTarget.style.borderColor='#1e1e30';$event.currentTarget.style.background='#0f0f1a'">
            <svg width="15" height="15" viewBox="0 0 15 15" fill="none" style="flex-shrink:0">
              <path d="M7.5 1C4 1 1 4 1 7.5S4 14 7.5 14 14 11 14 7.5 11 1 7.5 1z" stroke="#44445A" stroke-width="1.3"/>
              <path d="M5 7.5c0-1.38 1.12-2.5 2.5-2.5S10 6.12 10 7.5 8.88 10 7.5 10 5 8.88 5 7.5z" stroke="#44445A" stroke-width="1.3"/>
            </svg>
            <input v-model="nickCondition" placeholder="Напр. |@channel" maxlength="100"
              style="background:none;border:none;outline:none;color:#F0F0F8;width:100%;font-size:13.5px;font-family:inherit"/>
          </div>
          <div style="font-size:11px;color:#44445A;margin-top:6px;line-height:1.5">
            Участник добавит это в ник — получит ×10 билетов
          </div>
        </div>

      </div>

      <!-- Кнопка сохранить (sticky bottom) -->
      <div style="position:fixed;bottom:0;left:0;right:0;display:flex;justify-content:center;pointer-events:none">
        <div style="width:390px;max-width:100vw;padding:12px 20px 28px;background:linear-gradient(to top,#06060f 60%,transparent);pointer-events:all">
          <button @click="create" :disabled="saving || overLimit"
            :style="{
              width:'100%',padding:'15px',
              background: saved
                ? 'linear-gradient(135deg,#34D399,#10B981)'
                : overLimit
                ? 'linear-gradient(135deg,#5A2030,#7A2030)'
                : 'linear-gradient(135deg,#6C5CE7,#9580FF)',
              border:'none',borderRadius:'16px',
              color:'#fff',fontSize:'15px',fontWeight:'600',
              cursor: (saving || overLimit) ? 'not-allowed' : 'pointer',
              fontFamily:'inherit',
              display:'flex',alignItems:'center',justifyContent:'center',gap:'8px',
              transition:'all 0.3s ease',
              boxShadow: saved ? '0 4px 24px rgba(52,211,153,0.35)' : overLimit ? 'none' : '0 4px 24px #6C5CE744',
              letterSpacing:'-0.01em',
              opacity: saving ? 0.75 : 1,
            }">
            <template v-if="saved">
              <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                <path d="M3 8l4 4 6-7" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
              Сохранено!
            </template>
            <template v-else-if="saving">
              Сохраняем...
            </template>
            <template v-else-if="overLimit">
              Превышен лимит билетов
            </template>
            <template v-else>
              <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                <path d="M2 4a2 2 0 012-2h6.586a1 1 0 01.707.293l2.414 2.414A1 1 0 0114 5.414V12a2 2 0 01-2 2H4a2 2 0 01-2-2V4z" stroke="white" stroke-width="1.4"/>
                <rect x="5" y="10" width="6" height="4" rx="1" stroke="white" stroke-width="1.4"/>
                <rect x="5.5" y="2" width="4" height="3" rx="0.5" stroke="white" stroke-width="1.4"/>
              </svg>
              Сохранить розыгрыш
            </template>
          </button>
        </div>
      </div>

    </div>
  `,
}
