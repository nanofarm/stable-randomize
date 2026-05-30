
import { store, haptic, tg } from '../store.js'

export default {
  name: 'SuccessModal',
  props: {
    giveawayId: { type: String, required: true },
    title: { type: String, default: '' }
  },
  emits: ['close', 'details'],
  methods: {
    copyJoinLink() {
      const url = `https://t.me/${store.botUsername}?start=join_${this.giveawayId}`
      if (navigator.clipboard) navigator.clipboard.writeText(url)
      tg?.showScanQrPopup?.({ text: 'Ссылка скопирована!' })
      haptic('sel')
      alert('Ссылка скопирована!')
    },
    copyAdminLink() {
      const url = `https://t.me/${store.botUsername}?start=addch_${this.giveawayId}`
      if (navigator.clipboard) navigator.clipboard.writeText(url)
      haptic('sel')
      alert('Ссылка скопирована! Отправьте её партнерам.')
    },
    goToDetails() {
      this.$emit('details', this.giveawayId)
    }
  },
  template: `
    <div class="success-overlay">
      <div class="success-content">
        <!-- Анимированная иконка -->
        <div class="success-icon-wrap">
          <div class="success-icon">✨</div>
          <div class="success-glow"></div>
        </div>

        <div class="success-title">Готово!</div>
        <div class="success-subtitle">Розыгрыш «{{ title }}» успешно создан</div>

        <div class="success-cards">
          <!-- Карточка для участников -->
          <div class="success-card" @click="copyJoinLink">
            <div class="success-card-icon">🚀</div>
            <div class="success-card-info">
              <div class="success-card-t">Для участников</div>
              <div class="success-card-s">Скопировать ссылку</div>
            </div>
            <div class="success-card-arrow">→</div>
          </div>

          <!-- Карточка для админов -->
          <div class="success-card" @click="copyAdminLink">
            <div class="success-card-icon">🤝</div>
            <div class="success-card-info">
              <div class="success-card-t">Для партнеров</div>
              <div class="success-card-s">Пригласить админов</div>
            </div>
            <div class="success-card-arrow">→</div>
          </div>
        </div>

        <button class="btn btn-p btn-full" @click="goToDetails" style="margin-top:20px">
          Настроить и запустить
        </button>
        
        <div style="margin-top:16px; color:var(--muted); font-size:13px; cursor:pointer" @click="$emit('close')">
          Закрыть
        </div>
      </div>
    </div>
  `
}
