
const MONTHS = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь']

export default {
  name: 'DatePicker',
  emits: ['select'],
  data() {
    return {
      show: false,
      title: 'Дата',
      clearText: 'Без таймера',
      year: 2026,
      month: 0,
      day: null,
      hour: 12,
      minute: 0,
      target: null,
      callback: null,
    }
  },
  computed: {
    monthName() {
      return MONTHS[this.month] + ' ' + this.year
    },
    days() {
      const result = []
      const firstDay = new Date(this.year, this.month, 1)
      const startDow = (firstDay.getDay() + 6) % 7
      const daysInMonth = new Date(this.year, this.month + 1, 0).getDate()
      const daysInPrevMonth = new Date(this.year, this.month, 0).getDate()
      const today = new Date(); today.setHours(0,0,0,0)

      for (let i = startDow - 1; i >= 0; i--) {
        result.push({ num: daysInPrevMonth - i, other: true, past: true })
      }
      for (let i = 1; i <= daysInMonth; i++) {
        const dt = new Date(this.year, this.month, i)
        result.push({
          num: i,
          other: false,
          past: dt < today,
          today: dt.getTime() === today.getTime(),
          selected: i === this.day,
        })
      }
      const remaining = 42 - result.length
      for (let i = 1; i <= remaining; i++) {
        result.push({ num: i, other: true, past: false })
      }
      return result
    },
  },
  methods: {
    open(target, currentValue) {
      this.target = target
      this.title = target === 'start' ? 'Дата начала' : 'Дата завершения'
      this.clearText = target === 'start' ? 'Сразу после запуска' : 'Без таймера'

      const now = new Date(new Date().toLocaleString('en-US', { timeZone: 'Europe/Moscow' }))

      if (currentValue) {
        const d = new Date(currentValue)
        this.year = d.getFullYear()
        this.month = d.getMonth()
        this.day = d.getDate()
        this.hour = d.getHours()
        this.minute = d.getMinutes()
      } else {
        this.year = now.getFullYear()
        this.month = now.getMonth()
        this.day = now.getDate()
        this.hour = now.getHours()
        this.minute = now.getMinutes()
      }
      this.show = true
    },

    close() { this.show = false },

    navigate(dir) {
      this.month += dir
      if (this.month > 11) { this.month = 0; this.year++ }
      if (this.month < 0) { this.month = 11; this.year-- }
    },

    selectDay(d) {
      if (d.other || d.past) return
      this.day = d.num
    },

    apply() {
      if (!this.day) return
      const h = parseInt(this.hour) || 0
      const m = parseInt(this.minute) || 0
      const dt = new Date(this.year, this.month, this.day, h, m)
      if (dt <= new Date()) return

      this.$emit('select', {
        target: this.target,
        value: dt.toISOString().slice(0, 16),
        display: String(this.day).padStart(2,'0') + '.' + String(this.month+1).padStart(2,'0') + '.' + this.year + ' ' + String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0'),
      })
      this.close()
    },

    clear() {
      this.$emit('select', { target: this.target, value: null, display: null })
      this.close()
    },
  },

  template: `
    <div class="dp-overlay" v-if="show" @click.self="close">
      <div class="dp-box">
        <!-- Заголовок -->
        <div style="text-align:center;font-family:'Unbounded',sans-serif;font-size:14px;font-weight:700;color:var(--blue);margin-bottom:16px">
          {{ title }}
        </div>

        <!-- Навигация по месяцам -->
        <div class="dp-header">
          <div class="dp-arrow" @click="navigate(-1)">&lsaquo;</div>
          <div class="dp-month">{{ monthName }}</div>
          <div class="dp-arrow" @click="navigate(1)">&rsaquo;</div>
        </div>

        <!-- Дни недели -->
        <div class="dp-weekdays">
          <div class="dp-wd" v-for="d in ['Пн','Вт','Ср','Чт','Пт','Сб','Вс']">{{ d }}</div>
        </div>

        <!-- Дни -->
        <div class="dp-days">
          <div v-for="(d, i) in days" :key="i"
            class="dp-day"
            :class="{ other: d.other, past: d.past, today: d.today, selected: d.selected }"
            @click="selectDay(d)">
            {{ d.num }}
          </div>
        </div>

        <!-- Время -->
        <div class="dp-time">
          <input class="dp-time-input" type="number" min="0" max="23" v-model="hour">
          <div class="dp-time-sep">:</div>
          <input class="dp-time-input" type="number" min="0" max="59" v-model="minute">
          <div style="font-size:12px;color:var(--muted);margin-left:8px">МСК</div>
        </div>

        <!-- Кнопки -->
        <div class="dp-actions">
          <button class="btn btn-g" @click="clear">{{ clearText }}</button>
          <button class="btn btn-p" @click="apply">Применить</button>
        </div>
      </div>
    </div>
  `,
}
